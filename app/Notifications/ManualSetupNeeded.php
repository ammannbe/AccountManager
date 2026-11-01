<?php

namespace App\Notifications;

use App\Filament\Resources\Users\UserResource;
use App\Models\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins in the reduced mode: create the user and set the group in the identity provider.
 */
class ManualSetupNeeded extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject(__('New registration: :service', ['service' => $this->account->service->name]))
            ->line(__(':user (:email) registered for :service.', [
                'user' => $this->account->user->name,
                'email' => $this->account->user->email,
                'service' => $this->account->service->name,
            ]))
            ->line(__('Create the user in the identity provider and add them to the group :group.', ['group' => $this->account->service->access_group ?? '–']))
            ->action(__('Open user'), UserResource::getUrl('edit', ['record' => $this->account->user]));
    }
}
