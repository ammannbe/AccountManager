<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\BillingConfig;
use App\Billing\BillingException;
use App\Billing\BillingPeriod;
use App\Billing\InvoicePdf;
use App\Billing\QrBills;
use App\Enums\InvoiceStatus;
use App\Enums\PlanInterval;
use App\Enums\ReferenceType;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Notifications\InvoiceReminder;
use App\Notifications\InvoicesOverdue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'CH93 0076 2011 6238 5295 7';

    private const QR_IBAN = 'CH44 3199 9123 0008 8901 2';

    private Service $shopping;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-01-31 08:00'));
        $this->shopping = Service::factory()->create(['key' => 'shoppinglist', 'name' => 'Shoppinglist']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configure(string $iban = self::IBAN, array $overrides = []): void
    {
        config([
            'billing.creditor.name' => 'Narrenhaus GmbH',
            'billing.creditor.street' => 'Bahnhofstrasse 1',
            'billing.creditor.postal_code' => '8001',
            'billing.creditor.city' => 'Zürich',
            'billing.creditor.country' => 'CH',
            'billing.creditor.iban' => $iban,
            'billing.reference_type' => null,
            'billing.currency' => 'CHF',
            'billing.number_prefix' => '',
            'billing.payment_terms_days' => 30,
            'billing.reminder_days' => 10,
            'billing.admin_email' => null,
            ...$overrides,
        ]);
    }

    private function plan(int $cents = 1200, PlanInterval $interval = PlanInterval::Month, ?string $currency = null): Plan
    {
        return Plan::factory()->for($this->shopping)->create(['name' => 'KI', 'price_cents' => $cents, 'interval' => $interval, 'currency' => $currency]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscribe(?User $user = null, ?Plan $plan = null, array $attributes = []): Subscription
    {
        return Subscription::factory()
            ->for($user ?? $this->customer())
            ->for($plan ?? $this->plan())
            ->create(['starts_at' => now(), ...$attributes]);
    }

    private function customer(string $name = 'Anna Muster'): User
    {
        return User::factory()->create([
            'name' => $name,
            'billing_street' => 'Rosenweg 3',
            'billing_postal_code' => '3000',
            'billing_city' => 'Bern',
            'billing_country' => 'CH',
        ]);
    }

    /**
     * The uncompressed content streams of a PDF (dompdf writes the text as plain strings).
     */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        return implode("\n", array_map(fn (string $stream): string => (string) @gzuncompress($stream), $matches[1]));
    }

    private function billingCommand(): PendingCommand
    {
        $command = $this->artisan('billing:run');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function runBilling(): void
    {
        app(Billing::class)->run();
    }

    public function test_billing_is_off_without_an_iban(): void
    {
        $this->subscribe();

        $this->assertFalse(app(BillingConfig::class)->enabled());
        $this->billingCommand()->expectsOutputToContain('Billing is disabled')->assertSuccessful();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Subscription::query()->value('billed_periods'));
    }

    public function test_an_incomplete_creditor_stops_the_run(): void
    {
        $this->configure(overrides: ['billing.creditor.city' => null]);
        $this->subscribe();

        $this->billingCommand()->expectsOutputToContain('BILLING_CREDITOR_CITY')->assertFailed();
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_reference_type_must_fit_the_iban(): void
    {
        $this->configure(self::IBAN, ['billing.reference_type' => 'QRR']);
        $this->assertNotSame([], app(BillingConfig::class)->errors());

        $this->configure(self::QR_IBAN, ['billing.reference_type' => 'SCOR']);
        $this->assertNotSame([], app(BillingConfig::class)->errors());

        $this->configure(self::QR_IBAN);
        $this->assertSame(ReferenceType::Qrr, app(BillingConfig::class)->referenceType());
        $this->assertSame([], app(BillingConfig::class)->errors());

        $this->configure(self::IBAN);
        $this->assertSame(ReferenceType::Scor, app(BillingConfig::class)->referenceType());
        $this->assertSame([], app(BillingConfig::class)->errors());

        $this->configure(self::IBAN, ['billing.reference_type' => 'non']);
        $this->assertSame(ReferenceType::Non, app(BillingConfig::class)->referenceType());
        $this->assertSame([], app(BillingConfig::class)->errors());

        $this->configure('CH00 1234');
        $this->assertNotSame([], app(BillingConfig::class)->errors());
        $this->expectException(BillingException::class);
        $this->runBilling();
    }

    public function test_references_are_valid_qr_bill_data(): void
    {
        $qrr = QrBills::reference(ReferenceType::Qrr, 2026, 1);
        $this->assertMatchesRegularExpression('/^\d{27}$/', (string) $qrr);
        $this->assertStringStartsWith('00000000000000002026000001', (string) $qrr);
        $this->assertStringStartsWith('123456', (string) QrBills::reference(ReferenceType::Qrr, 2026, 1, '123456'));
        $this->assertStringStartsWith('RF', (string) QrBills::reference(ReferenceType::Scor, 2026, 1));
        $this->assertNull(QrBills::reference(ReferenceType::Non, 2026, 1));
        $this->assertNotSame(QrBills::reference(ReferenceType::Scor, 2026, 1), QrBills::reference(ReferenceType::Scor, 2026, 2));

        foreach ([[self::QR_IBAN, ReferenceType::Qrr], [self::IBAN, ReferenceType::Scor], [self::IBAN, ReferenceType::Non]] as [$iban, $type]) {
            $creditor = ['name' => 'Narrenhaus GmbH', 'street' => 'Bahnhofstrasse 1', 'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH', 'iban' => str_replace(' ', '', $iban)];
            $debtor = ['name' => 'Anna Muster', 'street' => 'Rosenweg 3', 'postal_code' => '3000', 'city' => 'Bern', 'country' => 'CH'];
            $bill = app(QrBills::class)->make($creditor, $debtor, 1250, 'CHF', $type, QrBills::reference($type, 2026, 7), 'Rechnung 2026-0007');

            $this->assertTrue($bill->isValid(), "{$type->value}: ".implode(' ', array_map(fn ($violation) => $violation->getMessage(), iterator_to_array($bill->getViolations()))));
        }

        $this->assertTrue(CreditorInformation::create(str_replace(' ', '', self::QR_IBAN))->containsQrIban());
    }

    public function test_periods_are_anchored_at_the_start_day(): void
    {
        $subscription = $this->subscribe(attributes: ['starts_at' => Carbon::parse('2026-01-31 14:30')]);
        $period = fn (int $index) => BillingPeriod::of($subscription, $index);

        $this->assertSame(['2026-01-31', '2026-02-27'], [$period(0)?->start->toDateString(), $period(0)?->end?->toDateString()]);
        $this->assertSame(['2026-02-28', '2026-03-30'], [$period(1)?->start->toDateString(), $period(1)?->end?->toDateString()]);
        $this->assertSame(['2026-03-31', '2026-04-29'], [$period(2)?->start->toDateString(), $period(2)?->end?->toDateString()]);

        $yearly = $this->subscribe(plan: $this->plan(9900, PlanInterval::Year), attributes: ['starts_at' => Carbon::parse('2024-02-29')]);
        $this->assertSame(['2025-02-28', '2026-02-27'], [BillingPeriod::of($yearly, 1)?->start->toDateString(), BillingPeriod::of($yearly, 1)?->end?->toDateString()]);

        $once = $this->subscribe(plan: $this->plan(5000, PlanInterval::Once));
        $this->assertNull(BillingPeriod::of($once, 0)?->end);
        $this->assertNull(BillingPeriod::of($once, 1));
    }

    public function test_due_periods_respect_the_billed_periods_and_the_end(): void
    {
        $subscription = $this->subscribe(attributes: ['starts_at' => Carbon::parse('2025-10-31')]);

        // 31.10., 30.11., 31.12., 31.1. have started.
        $this->assertCount(4, BillingPeriod::due($subscription, now()));

        $subscription->update(['billed_periods' => 3]);
        $this->assertSame(['2026-01-31'], array_map(fn (BillingPeriod $period) => $period->start->toDateString(), BillingPeriod::due($subscription, now())));

        // Ended in December: the periods from 31.12. on are not billed.
        $subscription->update(['billed_periods' => 0, 'ends_at' => Carbon::parse('2025-12-31')]);
        $this->assertCount(2, BillingPeriod::due($subscription, now()));

        $free = $this->subscribe(plan: $this->plan(0));
        $this->assertSame([], BillingPeriod::due($free, now()));

        $later = $this->subscribe(attributes: ['starts_at' => now()->addDay()]);
        $this->assertSame([], BillingPeriod::due($later, now()));
    }

    public function test_the_run_bills_due_periods_with_the_plan_price(): void
    {
        $this->configure();
        $anna = $this->customer();
        $this->subscribe($anna, $this->plan(1250), ['starts_at' => Carbon::parse('2025-12-31')]);
        $this->subscribe($anna, $this->plan(0));
        $this->subscribe($anna, Plan::factory()->create(['name' => 'Bündel', 'price_cents' => 9900, 'interval' => PlanInterval::Year]));
        $this->subscribe($anna, $this->plan(1000, PlanInterval::Month, 'EUR'));

        $this->billingCommand()->expectsOutputToContain('Created 2 invoice(s), sent 2')->assertSuccessful();

        $chf = Invoice::query()->where('currency', 'CHF')->firstOrFail();
        $this->assertSame(InvoiceStatus::Open, $chf->status);
        $this->assertSame('2026-0001', $chf->number);
        $this->assertSame(1250 * 2 + 9900, $chf->total_cents);
        $this->assertSame(['Shoppinglist: KI', 'Shoppinglist: KI', 'Bündel'], $chf->lines->pluck('description')->all());
        $this->assertSame(['2025-12-31', '2026-01-31', '2026-01-31'], $chf->lines->map(fn ($line) => $line->period_start?->toDateString())->all());
        $this->assertSame('2025-12-31', $chf->period_start?->toDateString());
        $this->assertSame('2027-01-30', $chf->period_end?->toDateString());
        $this->assertSame('2026-03-02', $chf->due_at->toDateString());
        $this->assertSame(ReferenceType::Scor, $chf->reference_type);
        $this->assertSame('Bern', $chf->recipient['city']);
        $this->assertSame('Narrenhaus GmbH', $chf->creditor['name']);

        $eur = Invoice::query()->where('currency', 'EUR')->firstOrFail();
        $this->assertSame('2026-0002', $eur->number);
        $this->assertSame(1000, $eur->total_cents);

        // Nothing is billed twice.
        $this->billingCommand()->expectsOutputToContain('Created 0 invoice(s)')->assertSuccessful();
        $this->assertSame(2, Invoice::query()->count());

        // The next month.
        $this->travelTo(Carbon::parse('2026-02-28 08:00'));
        $this->runBilling();
        $this->assertSame(['2026-0001', '2026-0002', '2026-0003', '2026-0004'], Invoice::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame(1250, Invoice::query()->where('number', '2026-0003')->value('total_cents'));
        $this->assertSame(1000, Invoice::query()->where('number', '2026-0004')->value('total_cents'));
    }

    public function test_numbers_are_sequential_per_year(): void
    {
        $this->configure(overrides: ['billing.number_prefix' => 'R-']);
        $this->subscribe($this->customer('Anna'));
        $this->subscribe($this->customer('Ben'));
        $this->runBilling();

        $this->assertSame(['R-2026-0001', 'R-2026-0002'], Invoice::query()->orderBy('id')->pluck('number')->all());

        $this->travelTo(Carbon::parse('2027-01-02 08:00'));
        $this->subscribe($this->customer('Cleo'));
        $this->runBilling();

        $this->assertSame('R-2027-0001', Invoice::query()->where('year', 2027)->orderBy('sequence')->value('number'));
        $this->assertSame([1, 2], Invoice::query()->where('year', 2026)->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_the_pdf_is_stored_privately_and_mailed(): void
    {
        $this->configure(self::QR_IBAN);
        $anna = $this->customer();
        $this->subscribe($anna);
        $this->runBilling();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('invoices/2026/2026-0001.pdf', $invoice->pdf_path);
        Storage::disk('local')->assertExists('invoices/2026/2026-0001.pdf');
        $this->assertNotNull($invoice->sent_at);
        $this->assertSame(ReferenceType::Qrr, $invoice->reference_type);

        $pdf = (string) Storage::disk('local')->get($invoice->pdf_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10_000, strlen($pdf));
        $this->assertSame(1, preg_match_all('#/Type /Page\b(?!s)#', $pdf));
        $this->assertStringContainsString('Invoice 2026-0001', $this->pdfText($pdf));
        $this->assertStringContainsString('Narrenhaus GmbH', $this->pdfText($pdf));

        Notification::assertSentTo($anna, InvoiceIssued::class, function (InvoiceIssued $notification) use ($anna, $pdf): bool {
            $mail = $notification->toMail($anna);

            return $mail->subject === 'Invoice 2026-0001'
                && count($mail->rawAttachments) === 1
                && $mail->rawAttachments[0]['name'] === 'Invoice-2026-0001.pdf'
                && $mail->rawAttachments[0]['data'] === $pdf;
        });
    }

    public function test_invoices_in_other_currencies_have_no_payment_part(): void
    {
        $this->configure();
        $this->subscribe(plan: $this->plan(1000, PlanInterval::Month, 'USD'));
        $this->runBilling();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertNull(app(QrBills::class)->forInvoice($invoice));
        $this->assertStringStartsWith('%PDF-', app(InvoicePdf::class)->render($invoice));
    }

    public function test_a_missing_pdf_is_rendered_again(): void
    {
        $this->configure();
        $this->subscribe();
        $this->runBilling();
        $invoice = Invoice::query()->firstOrFail();
        Storage::disk('local')->delete((string) $invoice->pdf_path);

        $this->assertStringStartsWith('%PDF-', app(InvoicePdf::class)->contents($invoice));
        Storage::disk('local')->assertExists((string) $invoice->pdf_path);
    }

    public function test_many_lines_put_the_payment_part_on_its_own_page(): void
    {
        $this->configure();
        $this->subscribe(attributes: ['starts_at' => Carbon::parse('2024-01-31')]);
        $this->runBilling();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertCount(25, $invoice->lines);
        $pdf = app(InvoicePdf::class)->render($invoice);
        // Two pages of lines, the payment part on the third.
        $this->assertSame(3, preg_match_all('#/Type /Page\b(?!s)#', $pdf));
        $this->assertStringContainsString('CHF 300.00', $this->pdfText($pdf));
    }

    public function test_overdue_invoices_are_reported_and_reminded_once(): void
    {
        $this->configure();
        $admin = User::factory()->admin()->create();
        $anna = $this->customer();
        $this->subscribe($anna, $this->plan(1200, PlanInterval::Year));
        $this->runBilling();
        $invoice = Invoice::query()->firstOrFail();

        // Due on 2.3.: still open that day.
        $this->travelTo(Carbon::parse('2026-03-02 08:00'));
        $this->runBilling();
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);

        $this->travelTo(Carbon::parse('2026-03-03 08:00'));
        $this->billingCommand()->expectsOutputToContain('1 became overdue, sent 0 reminder(s)')->assertSuccessful();
        $this->assertSame(InvoiceStatus::Overdue, $invoice->refresh()->status);
        Notification::assertSentTo($admin, InvoicesOverdue::class, fn (InvoicesOverdue $notification) => $notification->invoiceIds === [$invoice->id]);
        Notification::assertNotSentTo($anna, InvoiceReminder::class);

        // 10 days after the due date: one reminder.
        $this->travelTo(Carbon::parse('2026-03-12 08:00'));
        $this->runBilling();
        $this->travelTo(Carbon::parse('2026-03-13 08:00'));
        $this->runBilling();
        $this->travelTo(Carbon::parse('2026-03-14 08:00'));
        $this->runBilling();

        Notification::assertSentToTimes($anna, InvoiceReminder::class, 1);
        Notification::assertSentToTimes($admin, InvoicesOverdue::class, 1);
        $this->assertNotNull($invoice->refresh()->reminded_at);
        Notification::assertSentTo($anna, InvoiceReminder::class, fn (InvoiceReminder $notification) => count($notification->toMail($anna)->rawAttachments) === 1);
    }

    public function test_overdue_invoices_go_to_the_billing_address_and_without_reminders(): void
    {
        $this->configure(overrides: ['billing.admin_email' => 'billing@example.com', 'billing.reminder_days' => null]);
        $invoice = Invoice::factory()->create(['due_at' => now()->subDay()]);

        $this->runBilling();

        $this->assertSame(InvoiceStatus::Overdue, $invoice->fresh()?->status);
        Notification::assertSentOnDemand(InvoicesOverdue::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'billing@example.com');
        Notification::assertNotSentTo($invoice->user, InvoiceReminder::class);
    }

    public function test_paid_and_cancelled_invoices_are_not_overdue(): void
    {
        $this->configure();
        $paid = Invoice::factory()->create(['due_at' => now()->subDays(20)]);
        $paid->markPaid(now()->subDays(25), ' Bank ');
        $cancelled = Invoice::factory()->create(['due_at' => now()->subDays(20)]);
        $cancelled->cancel('Falscher Betrag');

        $this->runBilling();

        $paid->refresh();
        $cancelled->refresh();
        $this->assertSame(InvoiceStatus::Paid, $paid->status);
        $this->assertSame('Bank', $paid->payment_note);
        $this->assertSame(InvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('Falscher Betrag', $cancelled->cancel_reason);
        Notification::assertNothingSent();

        $this->expectException(LogicException::class);
        $paid->cancel('zu spät');
    }

    public function test_invoices_are_never_deleted(): void
    {
        $invoice = Invoice::factory()->create();

        try {
            $invoice->delete();
            $this->fail('An invoice was deleted.');
        } catch (LogicException) {
            $this->assertModelExists($invoice);
        }

        // Deleting the user keeps the invoice with its snapshot.
        $name = $invoice->recipient['name'];
        $invoice->user?->delete();
        $this->assertNull($invoice->fresh()?->user_id);
        $this->assertSame($name, $invoice->fresh()?->recipient['name']);
    }

    public function test_users_see_and_download_only_their_own_invoices(): void
    {
        $this->configure();
        $anna = $this->customer('Anna');
        $ben = $this->customer('Ben');
        $this->subscribe($anna);
        $this->subscribe($ben);
        $this->runBilling();
        [$annas, $bens] = Invoice::query()->orderBy('id')->get()->all();
        $draft = Invoice::factory()->for($anna)->create(['number' => '2026-0099', 'sequence' => 99, 'status' => InvoiceStatus::Draft]);

        $this->get(route('invoices.index'))->assertRedirect(route('login'));
        $this->get(route('invoices.pdf', $annas))->assertRedirect(route('login'));

        $this->actingAs($anna)->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Invoice '.$annas->number)
            ->assertDontSee('Invoice '.$bens->number)
            ->assertDontSee('Invoice '.$draft->number)
            ->assertSee('CHF 12.00');

        $response = $this->actingAs($anna)->get(route('invoices.pdf', $annas));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());

        $this->actingAs($anna)->get(route('invoices.pdf', $bens))->assertNotFound();
        $this->actingAs($anna)->get(route('invoices.pdf', $draft))->assertNotFound();
    }

    public function test_the_invoices_tab_only_shows_with_billing(): void
    {
        $anna = $this->customer();

        $this->actingAs($anna)->get(route('portal'))->assertOk()->assertDontSee(route('invoices.index'));

        $this->configure();
        $this->actingAs($anna)->get(route('portal'))->assertOk()->assertSee(route('invoices.index'));
    }
}
