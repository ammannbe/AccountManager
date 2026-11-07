<?php

namespace App\Notifications;

use App\Models\Suspension;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the user: an admin suspended an access or a plan, with the reason.
 */
class Suspended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Suspension $suspension) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = $this->suspension->serviceAccount;
        $subscription = $this->suspension->subscription;

        $mail = $account
            ? (new MailMessage)
                ->subject(__('Access suspended: :service', ['service' => $account->service->name]))
                ->line(__('Your access to :service has been suspended.', ['service' => $account->service->name]))
            : (new MailMessage)
                ->subject(__('Plan suspended: :plan', ['plan' => $subscription?->plan->name]))
                ->line(__('Your plan :plan has been suspended; its functions are not available for now.', ['plan' => $subscription?->plan->name]));

        if ($this->suspension->reason !== '') {
            $mail->line(__('Reason: :reason', ['reason' => $this->suspension->reason]));
        }

        return $mail
            ->line(__('If you have questions, just reply to this email.'))
            ->action(__('My services'), route('portal'));
    }
}
