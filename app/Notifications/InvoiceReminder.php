<?php

namespace App\Notifications;

use App\Billing\InvoicePdf;
use App\Billing\Money;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the user: the one reminder of an overdue invoice, the PDF attached again.
 */
class InvoiceReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;

        return (new MailMessage)
            ->subject(__('Payment reminder: invoice :number', ['number' => $invoice->number]))
            ->line(__('We have not received the payment for invoice :number of :amount yet; it was due on :date.', [
                'number' => $invoice->number,
                'amount' => Money::format($invoice->total_cents, $invoice->currency),
                'date' => $invoice->due_at->format('d.m.Y'),
            ]))
            ->line(__('Please pay it in the next few days with the payment slip in the attached PDF. If you have paid it in the meantime, please ignore this email.'))
            ->action(__('My invoices'), route('invoices.index'))
            ->line(__('If you have questions, just reply to this email.'))
            ->attachData(app(InvoicePdf::class)->contents($invoice), $invoice->fileName(), ['mime' => 'application/pdf']);
    }
}
