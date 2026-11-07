<?php

namespace App\Notifications;

use App\Enums\ConnectorAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins: something has to be done by hand. Without an action: the reduced mode of the
 * identity provider (create the user, set the group). With an action: the manual connector of an
 * external service (create, suspend, reactivate or delete the account there).
 *
 * Keeps plain values, so it also works for accounts and users that are deleted by now.
 */
class ManualSetupNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public string $serviceName;

    public ?string $group;

    public string $userName;

    public string $userEmail;

    public ?int $userId;

    public ?string $externalUsername;

    public function __construct(ServiceAccount $account, public ?ConnectorAction $action = null)
    {
        $this->serviceName = $account->service->name;
        $this->group = $account->service->access_group;
        $this->userName = (string) $account->user->name;
        $this->userEmail = (string) $account->user->email;
        $this->userId = $account->user->exists ? (int) $account->user->getKey() : null;
        $this->externalUsername = $account->external_username;
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
        $replace = [
            'user' => $this->userName,
            'email' => $this->userEmail,
            'service' => $this->serviceName,
            'username' => $this->externalUsername ?? '–',
        ];

        $mail = match ($this->action) {
            null => (new MailMessage)
                ->subject(__('New registration: :service', $replace))
                ->line(__(':user (:email) registered for :service.', $replace))
                ->line(__('Create the user in the identity provider and add them to the group :group.', ['group' => $this->group ?? '–'])),
            ConnectorAction::Provision => (new MailMessage)
                ->subject(__('Create account: :service', $replace))
                ->line(__(':user (:email) has access to :service now.', $replace))
                ->line(__('Create the account in :service, send the user the login details and enter the username on the user page.', $replace)),
            ConnectorAction::Suspend => (new MailMessage)
                ->subject(__('Suspend account: :service', $replace))
                ->line(__('The access of :user (:email) to :service is suspended.', $replace))
                ->line(__('Disable the account (username: :username) in :service.', $replace)),
            ConnectorAction::Resume => (new MailMessage)
                ->subject(__('Reactivate account: :service', $replace))
                ->line(__('The access of :user (:email) to :service is active again.', $replace))
                ->line(__('Enable the account (username: :username) in :service again.', $replace)),
            ConnectorAction::Deprovision, ConnectorAction::Check => (new MailMessage)
                ->subject(__('Delete account: :service', $replace))
                ->line(__('The access of :user (:email) to :service was removed.', $replace))
                ->line(__('Delete the account (username: :username) in :service.', $replace)),
        };

        return $this->userId !== null ? $mail->action(__('Open user'), UserResource::getUrl('edit', ['record' => $this->userId])) : $mail;
    }
}
