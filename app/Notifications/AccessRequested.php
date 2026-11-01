<?php

namespace App\Notifications;

use App\Filament\Resources\Users\UserResource;
use App\Models\ServiceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins: somebody requests access to a service that needs an approval.
 */
class AccessRequested extends Notification implements ShouldQueue
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
            ->subject(__('Access requested: :service', ['service' => $this->account->service->name]))
            ->line(__(':user (:email) requests access to :service.', [
                'user' => $this->account->user->name,
                'email' => $this->account->user->email,
                'service' => $this->account->service->name,
            ]))
            ->action(__('Review request'), UserResource::getUrl('edit', ['record' => $this->account->user]));
    }
}
