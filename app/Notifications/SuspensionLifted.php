<?php

namespace App\Notifications;

use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\Suspension;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the user: an admin lifted the suspension of an access or a plan.
 */
class SuspensionLifted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ServiceAccount|Subscription $target, public ?string $message = null, public ?Suspension $suspension = null) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->target instanceof ServiceAccount
            ? (new MailMessage)
                ->subject(__('Access restored: :service', ['service' => $this->target->service->name]))
                ->line(__('Your access to :service is active again.', ['service' => $this->target->service->name]))
            : (new MailMessage)
                ->subject(__('Plan active again: :plan', ['plan' => $this->target->plan->name]))
                ->line(__('Your plan :plan is active again.', ['plan' => $this->target->plan->name]));

        if ($this->message !== null) {
            $mail->line($this->message);
        }

        return $mail->action(__('My services'), route('portal'));
    }
}
