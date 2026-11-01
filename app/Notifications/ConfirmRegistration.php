<?php

namespace App\Notifications;

use App\Models\Registration;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmRegistration extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Service $service, public string $token, ?string $locale = null)
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
            ->subject(__('Confirm your registration for :service', ['service' => $this->service->name]))
            ->line(__('Please confirm your email address to complete the registration for :service.', ['service' => $this->service->name]))
            ->action(__('Confirm registration'), route('registrations.show', ['token' => $this->token]))
            ->line(__('The link is valid for :days days. If you did not register, you can ignore this email.', ['days' => Registration::LIFETIME_DAYS]));
    }
}
