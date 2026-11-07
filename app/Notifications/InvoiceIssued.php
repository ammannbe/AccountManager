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
 * To the user: a new invoice, the PDF attached.
 */
class InvoiceIssued extends Notification implements ShouldQueue
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
            ->subject(__('Invoice :number', ['number' => $invoice->number]))
            ->line(__('Here is your invoice :number of :amount, payable by :date. You find it as a PDF attached, with the payment slip.', [
                'number' => $invoice->number,
                'amount' => Money::format($invoice->total_cents, $invoice->currency),
                'date' => $invoice->due_at->format('d.m.Y'),
            ]))
            ->action(__('My invoices'), route('invoices.index'))
            ->line(__('If you have questions, just reply to this email.'))
            ->attachData(app(InvoicePdf::class)->contents($invoice), $invoice->fileName(), ['mime' => 'application/pdf']);
    }
}
