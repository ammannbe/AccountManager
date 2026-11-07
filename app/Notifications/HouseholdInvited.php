<?php

namespace App\Notifications;

use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HouseholdInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HouseholdInvitation $invitation, public string $token, ?string $locale = null)
    {
        $this->locale($locale ?? app()->getLocale());
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
        $household = $this->invitation->household->name;
        $inviter = $this->invitation->inviter?->name;

        return (new MailMessage)
            ->subject(__('Invitation to the household ":name"', ['name' => $household]))
            ->line($inviter
                ? __(':name invites you to the household ":household". Members see what is shared with the household in the connected apps.', ['name' => $inviter, 'household' => $household])
                : __('You are invited to the household ":household". Members see what is shared with the household in the connected apps.', ['household' => $household]))
            ->action(__('Show invitation'), route('household-invitations.show', ['token' => $this->token]))
            ->line(__('The invitation is valid until :date. Without an account you can create one with this address.', ['date' => $this->invitation->expires_at->isoFormat('LL')]))
            ->line(__('If you did not expect this invitation, you can ignore this email.'));
    }
}
