<?php

namespace App\Households;

use App\Models\Household;
use App\Models\Service;
use App\Webhooks\Webhooks;
use Illuminate\Support\Collection;

/**
 * Sends the household events to the apps: one signed delivery per service that has a webhook
 * and at least one affected user with an account there. Every event carries the full snapshot
 * of the household, so the apps can apply it idempotently and drop stale ones by version.
 */
class HouseholdEvents
{
    public const CREATED = 'household.created';

    public const UPDATED = 'household.updated';

    public const DELETED = 'household.deleted';

    public const MEMBER_ADDED = 'household.member_added';

    public const MEMBER_UPDATED = 'household.member_updated';

    public const MEMBER_REMOVED = 'household.member_removed';

    public function __construct(private readonly Webhooks $webhooks) {}

    /**
     * @param  array<string, mixed>  $snapshot  Household::snapshot() at the time of the event
     * @param  list<int>  $userIds  current and former members the event concerns
     * @param  array<string, mixed>  $data  more fields next to "household" (e.g. "member")
     */
    public function dispatch(string $type, array $snapshot, array $userIds, array $data = []): void
    {
        $this->webhooks->send($type, ['household' => $snapshot, ...$data], $this->recipients($userIds));
    }

    /**
     * Current members of the household (for the recipients).
     *
     * @return list<int>
     */
    public static function memberIds(Household $household): array
    {
        return array_values(array_map('intval', $household->members()->pluck('user_id')->all()));
    }

    /**
     * Services with a webhook where one of the users has an account (in any status): apps only
     * learn about households that concern their users. Others pull them on the first login.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Service>
     */
    private function recipients(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return Service::query()
            ->whereNotNull('webhook_url')
            ->whereNotNull('secret')
            ->whereHas('accounts', fn ($query) => $query->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->get()
            ->filter(fn (Service $service): bool => $service->receivesWebhooks())
            ->values();
    }
}
