<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Notifications\InvoiceReminder;
use App\Notifications\InvoicesOverdue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The invoice run (KONZEPT 5.4), daily through `billing:run` or by hand in the panel:
 *
 * 1. Due periods of subscriptions with a priced plan become invoices, one per user and currency
 *    (only while billing is configured).
 * 2. Drafts get their PDF and go to the user by mail (status open).
 * 3. Unpaid invoices past their due date become overdue; the admins get one mail.
 * 4. One reminder mail per overdue invoice, BILLING_REMINDER_DAYS after the due date.
 *
 * Nothing is suspended automatically: the admin decides (KONZEPT 11).
 */
class Billing
{
    public function __construct(
        private BillingConfig $config,
        private InvoicePdf $pdf,
    ) {}

    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    /**
     * @throws BillingException when the configuration is invalid or another run is going on
     */
    public function run(?CarbonInterface $today = null): BillingRun
    {
        $today = CarbonImmutable::parse($today ?? now())->setTimezone(config('app.timezone'))->startOfDay();

        if ($this->enabled() && ($errors = $this->config->errors()) !== []) {
            throw new BillingException(__('Billing is not configured correctly: :errors', ['errors' => implode(' ', $errors)]));
        }

        $lock = Cache::lock('billing:run', 600);

        if (! $lock->get()) {
            throw new BillingException(__('Another invoice run is going on.'));
        }

        try {
            $created = $this->enabled() ? $this->createInvoices($today) : collect();
            $issued = $this->enabled() ? $this->issueDrafts() : 0;
            $overdue = $this->markOverdue($today);
            $reminded = $this->sendReminders($today);
        } finally {
            $lock->release();
        }

        return new BillingRun($created->count(), $issued, $overdue, $reminded);
    }

    /**
     * New invoices (drafts) for the due periods.
     *
     * @return Collection<int, Invoice>
     */
    public function createInvoices(CarbonImmutable $today): Collection
    {
        $subscriptions = Subscription::query()
            ->with(['plan.service', 'user'])
            ->whereHas('plan', fn ($query) => $query->where('price_cents', '>', 0)->whereNotNull('interval'))
            ->where('starts_at', '<=', $today->endOfDay())
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $invoices = collect();

        foreach ($subscriptions as $userSubscriptions) {
            $invoices = $invoices->merge($this->createInvoicesFor($userSubscriptions, $today));
        }

        return $invoices;
    }

    /**
     * @param  Collection<int, Subscription>  $subscriptions  of one user
     * @return list<Invoice>
     */
    private function createInvoicesFor(Collection $subscriptions, CarbonImmutable $today): array
    {
        return DB::transaction(function () use ($subscriptions, $today): array {
            $byCurrency = [];

            foreach ($subscriptions as $subscription) {
                // Fresh and locked: the counter decides what is billed.
                $subscription->billed_periods = (int) Subscription::query()->whereKey($subscription->id)->lockForUpdate()->value('billed_periods');

                foreach (BillingPeriod::due($subscription, $today) as $period) {
                    $currency = strtoupper($subscription->plan->currency ?: $this->config->currency());
                    $byCurrency[$currency][] = [$subscription, $period];
                }
            }

            $invoices = [];

            foreach ($byCurrency as $currency => $items) {
                $invoices[] = $this->createInvoice($subscriptions->first()->user, $currency, $items, $today);
            }

            return $invoices;
        });
    }

    /**
     * @param  list<array{Subscription, BillingPeriod}>  $items
     */
    private function createInvoice(User $user, string $currency, array $items, CarbonImmutable $today): Invoice
    {
        $year = $today->year;
        $sequence = (int) Invoice::query()->where('year', $year)->lockForUpdate()->max('sequence') + 1;
        $type = $this->config->referenceType();

        $invoice = Invoice::query()->create([
            'number' => config('billing.number_prefix').$year.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'year' => $year,
            'sequence' => $sequence,
            'user_id' => $user->id,
            'recipient' => $user->billingRecipient(),
            'creditor' => $this->config->creditor(),
            'currency' => $currency,
            'total_cents' => 0,
            'status' => InvoiceStatus::Draft,
            'period_start' => collect($items)->min(fn (array $item) => $item[1]->start),
            'period_end' => collect($items)->max(fn (array $item) => $item[1]->end),
            'issued_at' => $today,
            'due_at' => $today->addDays($this->config->paymentTermsDays()),
            'reference_type' => $type,
            'reference' => QrBills::reference($type, $year, $sequence, config('billing.qr_customer_id')),
        ]);

        $total = 0;

        foreach ($items as $position => [$subscription, $period]) {
            $plan = $subscription->plan;
            $amount = (int) $plan->price_cents;
            $total += $amount;

            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'position' => $position + 1,
                'description' => $plan->service ? "{$plan->service->name}: {$plan->name}" : $plan->name,
                'period_start' => $period->start,
                'period_end' => $period->end,
                'quantity' => 1,
                'unit_cents' => $amount,
                'amount_cents' => $amount,
            ]);

            // Without events: the counter does not change any entitlements.
            Subscription::query()->whereKey($subscription->id)->increment('billed_periods');
            $subscription->billed_periods++;
        }

        $invoice->update(['total_cents' => $total]);

        return $invoice;
    }

    /**
     * Renders and sends all drafts; failures stay drafts for the next run.
     */
    public function issueDrafts(): int
    {
        $issued = 0;

        foreach (Invoice::query()->where('status', InvoiceStatus::Draft)->orderBy('id')->get() as $invoice) {
            try {
                $this->issue($invoice);
                $issued++;
            } catch (Throwable $exception) {
                Log::error("Invoice {$invoice->number} could not be issued", ['exception' => $exception]);
            }
        }

        return $issued;
    }

    public function issue(Invoice $invoice): void
    {
        $path = $this->pdf->store($invoice);
        $invoice->update(['status' => InvoiceStatus::Open, 'pdf_path' => $path]);
        $this->send($invoice);
    }

    /**
     * Mails the invoice with its PDF to the user (queued); also «send again» in the panel.
     */
    public function send(Invoice $invoice): bool
    {
        if ($invoice->user === null) {
            return false;
        }

        $this->pdf->ensure($invoice);
        $invoice->user->notify(new InvoiceIssued($invoice));
        $invoice->update(['sent_at' => now()]);

        return true;
    }

    public function markOverdue(CarbonImmutable $today): int
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::Open)
            ->where('due_at', '<', $today->toDateString())
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return 0;
        }

        $invoices->each->update(['status' => InvoiceStatus::Overdue, 'overdue_at' => now()]);

        $email = config('billing.admin_email');
        $notification = new InvoicesOverdue(array_values($invoices->map(fn (Invoice $invoice): int => $invoice->id)->all()));

        if (filled($email)) {
            Notification::route('mail', (string) $email)->notify($notification);
        } else {
            Notification::send(User::query()->where('is_admin', true)->get(), $notification);
        }

        return $invoices->count();
    }

    public function sendReminders(CarbonImmutable $today): int
    {
        $days = $this->config->reminderDays();

        if ($days === null) {
            return 0;
        }

        $invoices = Invoice::query()
            ->with('user')
            ->where('status', InvoiceStatus::Overdue)
            ->whereNull('reminded_at')
            ->whereNotNull('user_id')
            ->where('due_at', '<=', $today->subDays($days)->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $this->pdf->ensure($invoice);
            $invoice->user?->notify(new InvoiceReminder($invoice));
            $invoice->update(['reminded_at' => now()]);
        }

        return $invoices->count();
    }
}
