<?php

namespace App\Connectors;

use App\Connectors\Wizarr\WizarrClient;
use App\Enums\AccountStatus;
use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Notifications\ConnectorFailed;
use App\Notifications\ExternalInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Media servers (Emby, Jellyfin, Plex …) through Wizarr: provisioning creates a single-use
 * invitation and mails the link (the portal shows it until it is used); connectors:check finds the
 * redeemed invitation and keeps the Wizarr user id; suspending disables the user (or revokes the
 * open invitation), resuming enables them again (or invites again if Wizarr deleted them);
 * deprovisioning deletes the user and revokes an open invitation.
 *
 * Config (services.connector_config): base_url, api_key, server_id, optional public_url (base of
 * the invitation links if base_url is internal), library_ids, expires_in_days (1|7|30), duration
 * (days of access, empty = unlimited), allow_downloads, allow_live_tv.
 * Connector data of an account: state (invited|active|suspended|expired|revoked|removed),
 * invitation_id, invitation_code, invitation_url, invitation_expires_at, unmatched.
 */
class WizarrConnector implements ChecksExternalState, ServiceConnector
{
    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const REMOVED = 'removed';

    public function __construct(private readonly Connectors $connectors) {}

    public function provision(ServiceAccount $account): ConnectorResult
    {
        if ($account->external_id !== null) {
            return $this->resume($account);
        }

        // Retrying an open invitation just mails it again.
        if ($this->hasOpenInvitation($account)) {
            $account->user->notify(new ExternalInvitation($account));

            return ConnectorResult::done("Invitation {$account->connector_data['invitation_code']} is still open, mailed it again.");
        }

        $invitation = $this->client($account->service)->createInvitation($this->invitationOptions($account->service));

        $account->mergeConnectorData([
            'state' => self::INVITED,
            'invitation_id' => $invitation['id'],
            'invitation_code' => $invitation['code'],
            'invitation_url' => $this->invitationUrl($account->service, $invitation),
            // Wizarr sends UTC without an offset.
            'invitation_expires_at' => isset($invitation['expires']) ? Carbon::parse((string) $invitation['expires'], 'UTC')->toIso8601String() : null,
            'invited_at' => now()->toIso8601String(),
            'unmatched' => null,
        ])->save();

        $account->user->notify(new ExternalInvitation($account));

        return ConnectorResult::done("Created invitation {$invitation['code']} and mailed it.");
    }

    public function suspend(ServiceAccount $account): ConnectorResult
    {
        $client = $this->client($account->service);

        if ($account->external_id !== null) {
            $message = $client->disableUser($account->external_id);

            if ($message === null) {
                $this->forgetUser($account);

                return ConnectorResult::done('The user does not exist in Wizarr anymore.');
            }

            // Servers that cannot disable users: Wizarr deletes them instead.
            if (Str::contains(Str::lower($message), 'deleted')) {
                $this->forgetUser($account);

                return ConnectorResult::done("Wizarr: {$message}. Resuming sends a new invitation.");
            }

            $account->mergeConnectorData(['state' => self::SUSPENDED])->save();

            return ConnectorResult::done("Wizarr: {$message}");
        }

        if ($this->hasPendingInvitation($account)) {
            $client->deleteInvitation($account->connector_data['invitation_id']);
            $this->forgetInvitation($account, self::REVOKED);

            return ConnectorResult::done('Revoked the open invitation.');
        }

        return ConnectorResult::skipped('There is no Wizarr user or open invitation.');
    }

    public function resume(ServiceAccount $account): ConnectorResult
    {
        if ($account->external_id === null) {
            return $this->provision($account);
        }

        $message = $this->client($account->service)->enableUser($account->external_id);

        if ($message === null) {
            $this->forgetUser($account);

            return $this->provision($account);
        }

        if (Str::contains(Str::lower($message), ['failed', 'not supported'])) {
            throw ConnectorException::permanent("Wizarr could not enable the user: {$message}");
        }

        $account->mergeConnectorData(['state' => self::ACTIVE])->save();

        return ConnectorResult::done("Wizarr: {$message}");
    }

    public function deprovision(ServiceAccount $account): ConnectorResult
    {
        $client = $this->client($account->service);
        $done = [];

        if ($account->external_id !== null) {
            $done[] = $client->deleteUser($account->external_id) ? "deleted user {$account->external_id}" : "user {$account->external_id} was already gone";
        }

        if ($this->hasPendingInvitation($account)) {
            $id = $account->connector_data['invitation_id'];
            $done[] = $client->deleteInvitation($id) ? "revoked invitation {$id}" : "invitation {$id} was already gone";
        }

        return $done === [] ? ConnectorResult::skipped('There is no Wizarr user or open invitation.') : ConnectorResult::done(ucfirst(implode(', ', $done)).'.');
    }

    public function status(ServiceAccount $account): ?ConnectorStatus
    {
        $data = $account->connector_data ?? [];

        return match ($data['state'] ?? null) {
            self::INVITED => $this->invitationExpired($account)
                ? new ConnectorStatus(__('Invitation expired'), 'danger', __('Ask an admin for a new invitation.'))
                : new ConnectorStatus(
                    __('Invitation open'),
                    'warning',
                    isset($data['invitation_expires_at'])
                        ? __('Create your account with the invitation until :date.', ['date' => Carbon::parse($data['invitation_expires_at'])->isoFormat('LL')])
                        : __('Create your account with the invitation.'),
                    $data['invitation_url'] ?? null,
                    __('Accept invitation'),
                ),
            self::EXPIRED, self::REVOKED => $account->status === AccountStatus::Active
                ? new ConnectorStatus(__('Invitation expired'), 'danger', __('Ask an admin for a new invitation.'))
                : null,
            self::ACTIVE => new ConnectorStatus(__('Account created'), 'success'),
            self::SUSPENDED => new ConnectorStatus(__('Account disabled'), 'danger'),
            self::REMOVED => new ConnectorStatus(__('Account deleted in the service'), 'gray'),
            default => null,
        };
    }

    /**
     * Finds redeemed and expired invitations (one call for all open invitations of the service).
     */
    public function check(Service $service): void
    {
        $accounts = $service->accounts()->with(['service', 'user'])->whereNull('external_id')->get()
            ->filter(fn (ServiceAccount $account): bool => $this->hasPendingInvitation($account));

        if ($accounts->isEmpty()) {
            return;
        }

        $client = $this->client($service);
        $invitations = collect($client->invitations())->keyBy(fn (array $invitation): string => (string) $invitation['id']);
        $users = null;

        foreach ($accounts as $account) {
            $invitation = $invitations->get((string) $account->connector_data['invitation_id']);

            if ($invitation === null) {
                $this->forgetInvitation($account, self::EXPIRED);
                $this->connectors->log($account, ConnectorAction::Check, ConnectorResult::done('The invitation does not exist in Wizarr anymore.'));

                continue;
            }

            if (($invitation['status'] ?? null) === 'expired' || ($invitation['status'] !== 'used' && $this->invitationExpired($account))) {
                $this->forgetInvitation($account, self::EXPIRED);
                $this->connectors->log($account, ConnectorAction::Check, ConnectorResult::done('The invitation expired unused.'));

                continue;
            }

            if (($invitation['status'] ?? null) !== 'used') {
                continue;
            }

            $users ??= collect($client->users());
            $user = $this->redeemedBy($invitation, $account, $users);

            if ($user === null) {
                $this->reportUnmatched($account, $invitation);

                continue;
            }

            $this->redeemed($account, $user);
        }
    }

    /**
     * The invitation was used: keep the Wizarr user; disable them right away if the access was
     * suspended in the meantime.
     *
     * @param  array<string, mixed>  $user
     */
    private function redeemed(ServiceAccount $account, array $user): void
    {
        $account->external_id = (string) $user['id'];
        $account->external_username = $account->external_username ?: ($user['username'] ?? null);
        $this->forgetInvitation($account, self::ACTIVE, save: false);
        $account->mergeConnectorData(['redeemed_at' => now()->toIso8601String()])->save();

        $this->connectors->log($account, ConnectorAction::Check, ConnectorResult::done("Invitation redeemed by Wizarr user {$user['id']} (".($user['username'] ?? '?').').'));

        if ($account->status === AccountStatus::Suspended) {
            $this->connectors->dispatch($account, ConnectorAction::Suspend);
        }
    }

    /**
     * Wizarr only gives the user of an invitation as a string: the default representation of its
     * user row ("<User 12>") or a username; otherwise the address of the account is tried.
     *
     * @param  array<string, mixed>  $invitation
     * @param  Collection<int, array<string, mixed>>  $users
     * @return array<string, mixed>|null
     */
    private function redeemedBy(array $invitation, ServiceAccount $account, Collection $users): ?array
    {
        $usedBy = trim((string) ($invitation['used_by'] ?? ''));

        if (preg_match('/^<User (\d+)>$/', $usedBy, $matches) === 1) {
            $user = $users->first(fn (array $user): bool => (string) ($user['id'] ?? '') === $matches[1]);

            if ($user) {
                return $user;
            }
        }

        if ($usedBy !== '' && ! str_starts_with($usedBy, '<')) {
            $user = $users->first(fn (array $user): bool => ($user['username'] ?? null) === $usedBy);

            if ($user) {
                return $user;
            }
        }

        $email = mb_strtolower((string) $account->user->email);

        return $users->first(fn (array $user): bool => $email !== '' && mb_strtolower((string) ($user['email'] ?? '')) === $email);
    }

    /**
     * Used, but no user found: the admins set the Wizarr user id by hand (told once).
     *
     * @param  array<string, mixed>  $invitation
     */
    private function reportUnmatched(ServiceAccount $account, array $invitation): void
    {
        if ($account->connector_data['unmatched'] ?? false) {
            return;
        }

        $account->mergeConnectorData(['unmatched' => true])->save();

        $run = $this->connectors->log($account, ConnectorAction::Check, new ConnectorResult(
            ConnectorRunStatus::Failed,
            'The invitation was used (by '.((string) ($invitation['used_by'] ?? '') ?: '?').'), but the Wizarr user was not found: enter the Wizarr user id on the account by hand.',
        ));

        Notification::send(User::query()->where('is_admin', true)->get(), new ConnectorFailed($run));
    }

    /**
     * Options of a new invitation: single use (unlimited = false; true would let it be used
     * again and again), for the configured server and libraries.
     *
     * @return array<string, mixed>
     */
    private function invitationOptions(Service $service): array
    {
        $libraries = array_values(array_filter(array_map('intval', (array) $service->connectorSetting('library_ids', [])), fn (int $id): bool => $id > 0));
        $expires = (int) $service->connectorSetting('expires_in_days', 0);
        $duration = (int) $service->connectorSetting('duration', 0);

        return array_filter([
            'server_ids' => [(int) $service->connectorSetting('server_id')],
            'expires_in_days' => in_array($expires, [1, 7, 30], true) ? $expires : null,
            'duration' => $duration > 0 ? (string) $duration : 'unlimited',
            'unlimited' => false,
            'library_ids' => $libraries ?: null,
            'allow_downloads' => (bool) $service->connectorSetting('allow_downloads', false),
            'allow_live_tv' => (bool) $service->connectorSetting('allow_live_tv', false),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Wizarr returns a path (/j/<code>); it is made absolute with the public URL.
     *
     * @param  array<string, mixed>  $invitation
     */
    private function invitationUrl(Service $service, array $invitation): string
    {
        $url = (string) ($invitation['url'] ?? '') ?: "/j/{$invitation['code']}";

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $base = (string) ($service->connectorSetting('public_url') ?: $service->connectorSetting('base_url'));

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }

    private function client(Service $service): WizarrClient
    {
        $baseUrl = trim((string) $service->connectorSetting('base_url', ''));
        $apiKey = trim((string) $service->connectorSetting('api_key', ''));

        if ($baseUrl === '' || $apiKey === '' || (int) $service->connectorSetting('server_id', 0) <= 0) {
            throw ConnectorException::permanent("The Wizarr connector of {$service->key} needs the base URL, the API key and the server id.");
        }

        return new WizarrClient($baseUrl, $apiKey);
    }

    private function hasPendingInvitation(ServiceAccount $account): bool
    {
        return ($account->connector_data['state'] ?? null) === self::INVITED && isset($account->connector_data['invitation_id']);
    }

    private function hasOpenInvitation(ServiceAccount $account): bool
    {
        return $this->hasPendingInvitation($account) && ! $this->invitationExpired($account);
    }

    private function invitationExpired(ServiceAccount $account): bool
    {
        $expires = $account->connector_data['invitation_expires_at'] ?? null;

        return $expires !== null && Carbon::parse($expires)->isPast();
    }

    private function forgetInvitation(ServiceAccount $account, string $state, bool $save = true): void
    {
        $account->mergeConnectorData([
            'state' => $state,
            'invitation_id' => null,
            'invitation_code' => null,
            'invitation_url' => null,
            'invitation_expires_at' => null,
            'unmatched' => null,
        ]);

        if ($save) {
            $account->save();
        }
    }

    private function forgetUser(ServiceAccount $account): void
    {
        $account->external_id = null;
        $account->mergeConnectorData(['state' => self::REMOVED])->save();
    }
}
