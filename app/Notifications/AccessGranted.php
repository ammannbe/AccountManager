<?php

namespace App\Notifications;

use App\Models\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the user: an admin approved the access to a service.
 */
class AccessGranted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ServiceAccount $account) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = $this->account->service;

        $mail = (new MailMessage)
            ->subject(__('Access granted: :service', ['service' => $service->name]))
            ->line(__('You now have access to :service.', ['service' => $service->name]));

        // The invitation follows in its own mail; the service cannot be used before.
        if ($service->connector === 'wizarr') {
            return $mail->line(__('You will get an invitation to create your account there in a separate mail.'));
        }

        return $service->url ? $mail->action(__('Open :service', ['service' => $service->name]), $service->url) : $mail;
    }
}
