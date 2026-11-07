<?php

namespace App\Notifications;

use App\Billing\Money;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins: invoices that became overdue in this run. They decide what happens (reminder,
 * suspension or nothing); nothing is suspended automatically.
 */
class InvoicesOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $invoiceIds
     */
    public function __construct(public array $invoiceIds) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoices = Invoice::query()->whereKey($this->invoiceIds)->orderBy('id')->get();

        $mail = (new MailMessage)
            ->subject(__('Overdue invoices (:count)', ['count' => $invoices->count()]))
            ->line(__('These invoices are past their due date and not marked as paid:'));

        foreach ($invoices as $invoice) {
            $mail->line(__(':number, :name, :amount, due on :date', [
                'number' => $invoice->number,
                'name' => $invoice->recipient['name'],
                'amount' => Money::format($invoice->total_cents, $invoice->currency),
                'date' => $invoice->due_at->format('d.m.Y'),
            ]));
        }

        return $mail
            ->line(__('Nothing is suspended automatically. Mark paid invoices as paid, or suspend the plan or access of the user.'))
            ->action(__('Review invoices'), InvoiceResource::getUrl('index'));
    }
}
