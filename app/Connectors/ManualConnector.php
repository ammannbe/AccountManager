<?php

namespace App\Connectors;

use App\Enums\ConnectorAction;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\ManualSetupNeeded;
use Illuminate\Support\Facades\Notification;

/**
 * The admins create, suspend and delete the external account by hand; each step mails them what to
 * do. The optional instructions (connector_config.instructions) are shown to active users.
 */
class ManualConnector implements ServiceConnector
{
    public function provision(ServiceAccount $account): ConnectorResult
    {
        return $this->ask($account, ConnectorAction::Provision);
    }

    public function suspend(ServiceAccount $account): ConnectorResult
    {
        return $this->ask($account, ConnectorAction::Suspend);
    }

    public function resume(ServiceAccount $account): ConnectorResult
    {
        return $this->ask($account, ConnectorAction::Resume);
    }

    public function deprovision(ServiceAccount $account): ConnectorResult
    {
        return $this->ask($account, ConnectorAction::Deprovision);
    }

    public function status(ServiceAccount $account): ?ConnectorStatus
    {
        $instructions = trim((string) $account->service->connectorSetting('instructions', ''));

        return $instructions === '' ? null : new ConnectorStatus(__('How to log in'), detail: $instructions);
    }

    private function ask(ServiceAccount $account, ConnectorAction $action): ConnectorResult
    {
        $admins = User::query()->where('is_admin', true)->get();

        Notification::send($admins, new ManualSetupNeeded($account, $action));

        return ConnectorResult::manual("Mailed {$admins->count()} admin(s) to do it by hand.");
    }
}
