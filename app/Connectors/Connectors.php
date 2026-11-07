<?php

namespace App\Connectors;

use App\Enums\AccountStatus;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Jobs\RunConnector;
use App\Models\ConnectorRun;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\ConnectorFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hooks the connectors into the access lifecycle: approve → provision, suspend → suspend, lift →
 * resume, account or user deleted → deprovision. Every call is a ConnectorRun (log) carried out by
 * the queued RunConnector job with retries; failures notify the admins.
 */
class Connectors
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * From the ServiceAccountObserver: accounts created as active (admin, self-registration).
     */
    public function accountCreated(ServiceAccount $account): ?ConnectorRun
    {
        return $account->status === AccountStatus::Active ? $this->dispatch($account, ConnectorAction::Provision) : null;
    }

    /**
     * From the ServiceAccountObserver: the action that follows from the status change, if any.
     */
    public function accountUpdated(ServiceAccount $account): ?ConnectorRun
    {
        if (! $account->wasChanged('status')) {
            return null;
        }

        $status = $account->status;
        $previous = $account->getOriginal('status');

        $action = match (true) {
            $status === AccountStatus::Active && $previous === AccountStatus::Suspended => ConnectorAction::Resume,
            $status === AccountStatus::Active => ConnectorAction::Provision,
            $status === AccountStatus::Suspended && $previous === AccountStatus::Active => ConnectorAction::Suspend,
            default => null,
        };

        return $action ? $this->dispatch($account, $action) : null;
    }

    /**
     * From the ServiceAccountObserver: requested accounts were never provisioned.
     */
    public function accountDeleted(ServiceAccount $account): ?ConnectorRun
    {
        return $account->status !== AccountStatus::Requested ? $this->dispatch($account, ConnectorAction::Deprovision) : null;
    }

    /**
     * From User::deleting: the accounts go by a cascade in the database (no model events).
     */
    public function userDeleting(User $user): void
    {
        $user->serviceAccounts()->with('service')->get()
            ->reject(fn (ServiceAccount $account): bool => $account->status === AccountStatus::Requested)
            ->each(fn (ServiceAccount $account) => $this->dispatch($account, ConnectorAction::Deprovision));
    }

    /**
     * «Retry» on an account: runs the action that fits its current status again.
     */
    public function resync(ServiceAccount $account): ?ConnectorRun
    {
        return match ($account->status) {
            AccountStatus::Active => $this->dispatch($account, ConnectorAction::Provision),
            AccountStatus::Suspended => $this->dispatch($account, ConnectorAction::Suspend),
            AccountStatus::Requested => null,
        };
    }

    public function dispatch(ServiceAccount $account, ConnectorAction $action): ?ConnectorRun
    {
        $service = $account->service;

        if ($this->registry->isPassive($service->connector)) {
            return null;
        }

        $run = ConnectorRun::query()->create([
            'service_id' => $service->id,
            'service_account_id' => $account->exists ? $account->id : null,
            'user_id' => $account->user_id,
            'connector' => $service->connector,
            'action' => $action,
            'snapshot' => $this->snapshot($account),
        ]);

        RunConnector::dispatch($run);

        return $run;
    }

    /**
     * «Retry» on a failed run.
     */
    public function retry(ConnectorRun $run): void
    {
        $run->update(['status' => ConnectorRunStatus::Pending, 'finished_at' => null]);

        RunConnector::dispatch($run);
    }

    /**
     * Carries out a run (from RunConnector). Exceptions bubble up for the retries.
     */
    public function execute(ConnectorRun $run): void
    {
        if (in_array($run->status, [ConnectorRunStatus::Succeeded, ConnectorRunStatus::Manual, ConnectorRunStatus::Skipped], true)) {
            return;
        }

        $run->increment('attempts');

        $account = $this->accountFor($run);

        if (! $account) {
            $this->finish($run, ConnectorResult::skipped('The account does not exist anymore.'));

            return;
        }

        if (! $this->stillWanted($run->action, $account)) {
            $this->finish($run, ConnectorResult::skipped("The account is {$account->status->value} by now."));

            return;
        }

        $connector = $this->registry->connector($run->connector);

        $result = match ($run->action) {
            ConnectorAction::Provision => $connector->provision($account),
            ConnectorAction::Suspend => $connector->suspend($account),
            ConnectorAction::Resume => $connector->resume($account),
            ConnectorAction::Deprovision => $connector->deprovision($account),
            ConnectorAction::Check => ConnectorResult::skipped('Checks run through connectors:check.'),
        };

        $this->finish($run, $result);
    }

    /**
     * Keeps the error of an attempt that will be retried.
     */
    public function recordError(ConnectorRun $run, Throwable $exception): void
    {
        $run->update(['message' => Str::limit($exception->getMessage(), 1000)]);
    }

    /**
     * After the last attempt or a permanent error: mark it failed and tell the admins.
     */
    public function failed(ConnectorRun $run, ?Throwable $exception): void
    {
        $run->update([
            'status' => ConnectorRunStatus::Failed,
            'message' => $exception ? Str::limit($exception->getMessage(), 1000) : $run->message,
            'finished_at' => now(),
        ]);

        Notification::send(User::query()->where('is_admin', true)->get(), new ConnectorFailed($run));
    }

    /**
     * Logs a result that did not come from a queued run (e.g. a redeemed invitation found by connectors:check).
     */
    public function log(ServiceAccount $account, ConnectorAction $action, ConnectorResult $result): ConnectorRun
    {
        return ConnectorRun::query()->create([
            'service_id' => $account->service_id,
            'service_account_id' => $account->id,
            'user_id' => $account->user_id,
            'connector' => $account->service->connector,
            'action' => $action,
            'status' => $result->status,
            'attempts' => 1,
            'message' => Str::limit($result->message, 1000),
            'finished_at' => now(),
        ]);
    }

    /**
     * Runs the scheduled checks of all services whose connector needs one.
     *
     * @return list<string> Errors per service
     */
    public function check(): array
    {
        $errors = [];

        foreach (Service::query()->get() as $service) {
            if (! $this->registry->has($service->connector)) {
                continue;
            }

            $connector = $this->registry->for($service);

            if (! $connector instanceof ChecksExternalState) {
                continue;
            }

            try {
                $connector->check($service);
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = "{$service->key}: {$exception->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * What the portal and the panel show about the account (null for passive connectors).
     */
    public function status(ServiceAccount $account): ?ConnectorStatus
    {
        $key = $account->service->connector;

        if ($this->registry->isPassive($key) || ! $this->registry->has($key)) {
            return null;
        }

        return $this->registry->connector($key)->status($account);
    }

    /**
     * Provisioning and resuming only for active accounts, suspending only for suspended ones:
     * an outdated run (e.g. retried after the status changed again) is skipped.
     */
    private function stillWanted(ConnectorAction $action, ServiceAccount $account): bool
    {
        return match ($action) {
            ConnectorAction::Provision, ConnectorAction::Resume => $account->status === AccountStatus::Active,
            ConnectorAction::Suspend => $account->status === AccountStatus::Suspended,
            ConnectorAction::Deprovision, ConnectorAction::Check => true,
        };
    }

    /**
     * The live account; for deprovisioning a copy from the snapshot (the account is gone by then).
     */
    private function accountFor(ConnectorRun $run): ?ServiceAccount
    {
        if ($run->action !== ConnectorAction::Deprovision) {
            return $run->account()->with(['service', 'user'])->first();
        }

        $snapshot = $run->snapshot ?? [];

        $account = new ServiceAccount;
        $account->forceFill([
            'service_id' => $run->service_id,
            'user_id' => $snapshot['user_id'] ?? null,
            'status' => $snapshot['status'] ?? AccountStatus::Active->value,
            'external_id' => $snapshot['external_id'] ?? null,
            'external_username' => $snapshot['external_username'] ?? null,
        ]);
        $account->connector_data = $snapshot['connector_data'] ?? null;
        $account->setRelation('service', $run->service);
        $account->setRelation('user', (new User)->forceFill(['name' => $snapshot['name'] ?? '', 'email' => $snapshot['email'] ?? '']));

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ServiceAccount $account): array
    {
        return [
            'user_id' => $account->user_id,
            'name' => $account->user->name,
            'email' => $account->user->email,
            'status' => $account->status->value,
            'external_id' => $account->external_id,
            'external_username' => $account->external_username,
            'connector_data' => $account->connector_data,
        ];
    }

    private function finish(ConnectorRun $run, ConnectorResult $result): void
    {
        $run->update([
            'status' => $result->status,
            'message' => Str::limit($result->message, 1000),
            'finished_at' => now(),
        ]);
    }
}
