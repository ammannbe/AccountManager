<?php

namespace App\Entitlements;

use App\Enums\AccountStatus;
use App\Jobs\SyncUserGroups;
use App\Models\EntitlementState;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\Subscription;
use App\Models\User;
use App\Webhooks\Webhooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a user may do in a service (see docs/ENTITLEMENTS-API.md): the features of their effective
 * subscriptions plus the features granted directly, and the admin flag, both only while their
 * account at the service is active.
 *
 * Every user and service has one snapshot with a version. refresh() recomputes it after each
 * change; a changed snapshot gets the next version, an entitlements.changed event to the app and a
 * group sync in the identity provider.
 */
class Entitlements
{
    public const CHANGED = 'entitlements.changed';

    public const USER_DELETED = 'user.deleted';

    /**
     * Status in the snapshot when the user has no account (anymore) at the service.
     */
    public const NO_ACCOUNT = 'none';

    public function __construct(private readonly Webhooks $webhooks) {}

    /**
     * Effective features of the user at the service, sorted by key.
     *
     * @return Collection<int, Feature>
     */
    public function features(User $user, Service $service, ?ServiceAccount $account = null): Collection
    {
        $account ??= $this->account($user, $service);

        if ($account?->status !== AccountStatus::Active) {
            return new Collection;
        }

        return $this->heldFeatures($user)->where('service_id', $service->id)->orderBy('key')->get();
    }

    /**
     * The snapshot without version, as the app gets it.
     *
     * @return array{sub: string|null, service: string, status: string, admin: bool, features: list<string>}
     */
    public function compute(User $user, Service $service, ?ServiceAccount $account = null): array
    {
        $account ??= $this->account($user, $service);
        $active = $account?->status === AccountStatus::Active;

        return [
            'sub' => $user->oidc_sub,
            'service' => (string) $service->key,
            'status' => $account?->status->value ?? self::NO_ACCOUNT,
            'admin' => $active && $account->is_admin,
            'features' => array_values(array_map('strval', $this->features($user, $service, $account)->pluck('key')->all())),
        ];
    }

    /**
     * Current snapshot with version for the API (recomputed first, so it is never stale); null when
     * the user has no account at the service or no identity.
     *
     * @return array{sub: string|null, service: string, status: string, admin: bool, features: list<string>, version: int}|null
     */
    public function snapshot(User $user, Service $service): ?array
    {
        if ($user->oidc_sub === null || ! $this->account($user, $service)) {
            return null;
        }

        [$snapshot, $changed] = $this->refreshService($user, $service);

        if ($changed) {
            SyncUserGroups::dispatch($user);
        }

        return $snapshot;
    }

    /**
     * Recomputes the user's snapshots at the given services (default: every service where they
     * have or had an account). Users without identity are skipped: the apps cannot know them.
     *
     * @param  iterable<Service>|null  $services
     */
    public function refresh(User $user, ?iterable $services = null): void
    {
        if ($user->oidc_sub === null || ! $user->exists) {
            return;
        }

        $changed = false;

        foreach ($services ?? $this->servicesOf($user) as $service) {
            $changed = $this->refreshService($user, $service)[1] || $changed;
        }

        if ($changed) {
            SyncUserGroups::dispatch($user);
        }
    }

    /**
     * @param  iterable<int>  $userIds
     */
    public function refreshUsers(iterable $userIds): void
    {
        $ids = collect($userIds)->unique()->values()->all();

        User::query()->whereIn('id', $ids)->whereNotNull('oidc_sub')->orderBy('id')->get()->each(fn (User $user) => $this->refresh($user));
    }

    /**
     * After the features of a plan changed: every user with a subscription of it.
     */
    public function refreshPlan(Plan $plan): void
    {
        $this->refreshUsers(array_map('intval', $plan->subscriptions()->pluck('user_id')->all()));
    }

    /**
     * The user is being deleted: every app where they have or had an account gets user.deleted
     * (only the sub); the apps delete the user and their private contents.
     */
    public function userDeleted(User $user): void
    {
        if ($user->oidc_sub === null) {
            return;
        }

        $this->webhooks->send(self::USER_DELETED, ['user' => ['sub' => $user->oidc_sub]], $this->servicesOf($user));
    }

    /**
     * Groups of the identity provider the user should be in: per active account the access group,
     * the admin group for admins and the groups of the effective features.
     *
     * @return list<string>
     */
    public function groups(User $user): array
    {
        $accounts = $user->serviceAccounts()->where('status', AccountStatus::Active)->with('service')->get();
        $featureGroups = $this->heldFeatures($user)->whereNotNull('group')->get()->groupBy('service_id');
        $groups = [];

        foreach ($accounts as $account) {
            $groups[] = $account->service->access_group;

            if ($account->is_admin) {
                $groups[] = $account->service->admin_group;
            }

            foreach ($featureGroups->get($account->service_id, []) as $feature) {
                $groups[] = $feature->group;
            }
        }

        $groups = array_values(array_unique(array_filter(array_map('strval', $groups), fn (string $group): bool => $group !== '')));
        sort($groups);

        return $groups;
    }

    /**
     * For the portal: per service with an active account the effective subscriptions that concern
     * it and its effective features.
     *
     * @return array<int, array{subscriptions: Collection<int, Subscription>, features: Collection<int, Feature>}>
     */
    public function overview(User $user): array
    {
        $subscriptions = $user->subscriptions()->effective()->with('plan.features')->orderBy('starts_at')->get();
        $features = $this->heldFeatures($user)->orderBy('name')->get()->groupBy('service_id');
        $overview = [];

        foreach ($user->serviceAccounts()->where('status', AccountStatus::Active)->get() as $account) {
            $serviceId = (int) $account->service_id;

            $overview[$serviceId] = [
                'subscriptions' => $subscriptions->filter(fn (Subscription $subscription): bool => (int) $subscription->plan->service_id === $serviceId
                    || $subscription->plan->features->contains(fn (Feature $feature): bool => (int) $feature->service_id === $serviceId))->values(),
                'features' => new Collection($features->get($serviceId, [])),
            ];
        }

        return $overview;
    }

    /**
     * Recomputes one snapshot under a row lock, so every version is used once.
     *
     * @return array{0: array{sub: string|null, service: string, status: string, admin: bool, features: list<string>, version: int}|null, 1: bool}
     */
    private function refreshService(User $user, Service $service): array
    {
        return DB::transaction(function () use ($user, $service): array {
            $account = $this->account($user, $service);

            if ($account) {
                EntitlementState::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $state = EntitlementState::query()->where('user_id', $user->id)->where('service_id', $service->id)->lockForUpdate()->first();

            // Never had an account: the app does not know the user.
            if (! $state) {
                return [null, false];
            }

            $data = $this->compute($user, $service, $account);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

            if ($state->hash === $hash) {
                return [[...$data, 'version' => $state->version], false];
            }

            $state->version++;
            $state->hash = $hash;
            $state->save();

            $snapshot = [...$data, 'version' => $state->version];
            $this->webhooks->send(self::CHANGED, ['entitlements' => $snapshot], [$service]);

            return [$snapshot, true];
        });
    }

    /**
     * Features the user holds through an effective subscription or a direct grant, whatever the
     * state of their accounts.
     *
     * @return Builder<Feature>
     */
    private function heldFeatures(User $user): Builder
    {
        return Feature::query()->where(fn (Builder $query) => $query
            ->whereHas('grants', fn (Builder $grants) => $grants->where('user_id', $user->id))
            ->orWhereHas('plans', fn (Builder $plans) => $plans->whereHas('subscriptions', fn ($subscriptions) => $subscriptions->where('user_id', $user->id)->effective())));
    }

    /**
     * Services where the user has an account or had one (a snapshot exists).
     *
     * @return Collection<int, Service>
     */
    private function servicesOf(User $user): Collection
    {
        return Service::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('accounts', fn (Builder $accounts) => $accounts->where('user_id', $user->id))
                ->orWhereIn('id', EntitlementState::query()->where('user_id', $user->id)->select('service_id')))
            ->orderBy('id')
            ->get();
    }

    private function account(User $user, Service $service): ?ServiceAccount
    {
        return ServiceAccount::query()->where('user_id', $user->id)->where('service_id', $service->id)->first();
    }
}
