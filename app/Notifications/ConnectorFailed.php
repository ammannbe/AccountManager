<?php

namespace App\Notifications;

use App\Filament\Resources\ConnectorRuns\ConnectorRunResource;
use App\Models\ConnectorRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the admins: a connector call failed for good (after the retries or right away for errors that
 * a retry does not fix). They can retry it in the panel.
 */
class ConnectorFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ConnectorRun $run) {}

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
            'service' => $this->run->service->name,
            'action' => $this->run->action->getLabel(),
            'user' => $this->run->userLabel(),
        ];

        return (new MailMessage)
            ->subject(__('Connector failed: :service', $replace))
            ->line(__(':action of the account of :user in :service failed.', $replace))
            ->line(__('Error: :message', ['message' => $this->run->message ?? '–']))
            ->line(__('Check the connector settings of the service, then retry it in the connector log.'))
            ->action(__('Open connector log'), ConnectorRunResource::getUrl('index'));
    }
}
