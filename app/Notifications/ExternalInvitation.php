<?php

namespace App\Notifications;

use App\Models\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * To the user: the invitation link of an external service (e.g. Emby through Wizarr). Keeps the
 * link and the expiry of the moment it was created.
 */
class ExternalInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public string $serviceName;

    public string $url;

    public ?string $expiresAt;

    public function __construct(ServiceAccount $account)
    {
        $this->serviceName = $account->service->name;
        $this->url = (string) ($account->connector_data['invitation_url'] ?? '');
        $this->expiresAt = $account->connector_data['invitation_expires_at'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Your invitation to :service', ['service' => $this->serviceName]))
            ->line(__('You have access to :service. Create your account there with this invitation; it works once.', ['service' => $this->serviceName]));

        if ($this->expiresAt !== null) {
            $mail->line(__('The invitation is valid until :date.', ['date' => Carbon::parse($this->expiresAt)->isoFormat('LLL')]));
        }

        return $mail
            ->action(__('Accept invitation'), $this->url)
            ->line(__('You also find the invitation in the portal under «My services» until you have used it.'));
    }
}
