<?php

namespace App\Notifications;

use App\Http\Controllers\ProfileController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, ?string $locale = null)
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
        return (new MailMessage)
            ->subject(__('Confirm your new email address'))
            ->line(__('Please confirm that this is the new email address of your account.'))
            ->action(__('Confirm email address'), $this->url)
            ->line(__('The link is valid for :hours hours. If you did not change your address, you can ignore this email.', ['hours' => ProfileController::EMAIL_LINK_HOURS]));
    }
}
