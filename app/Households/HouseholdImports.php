<?php

namespace App\Households;

use App\Enums\HouseholdImportStatus;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdImport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Switching an app from local households to the AccountManager (HOUSEHOLDS_DRIVER=account-manager): the app
 * proposes its local households, an admin of the AccountManager creates a new household from each
 * proposal or merges it into an existing one (or rejects it), and the app polls the mapping
 * local id → household uuid. Nothing is merged automatically.
 */
class HouseholdImports
{
    public function __construct(private readonly HouseholdEvents $events) {}

    /**
     * Creates or updates the proposals of the service. Resolved proposals are kept as they are,
     * so the app can send all its households again and again.
     *
     * @param  list<array{local_id: string, name: string, members: list<array{sub: string, role?: string|null}>}>  $households
     * @return Collection<int, HouseholdImport>
     */
    public function propose(Service $service, array $households): Collection
    {
        return DB::transaction(fn (): Collection => collect($households)->map(function (array $proposal) use ($service): HouseholdImport {
            $import = HouseholdImport::query()->firstOrNew(['service_id' => $service->id, 'local_id' => (string) $proposal['local_id']]);

            if ($import->exists && $import->status !== HouseholdImportStatus::Pending) {
                return $import;
            }

            $import->fill([
                'name' => trim($proposal['name']),
                'members' => array_map(fn (array $member): array => [
                    'sub' => $member['sub'],
                    'role' => ($member['role'] ?? null) === HouseholdRole::Admin->value ? HouseholdRole::Admin->value : HouseholdRole::Member->value,
                ], $proposal['members']),
            ])->save();

            return $import;
        })->values());
    }

    /**
     * A new household with the proposed members known here. Without a proposed admin the first
     * member becomes admin.
     */
    public function createHousehold(HouseholdImport $import, User $resolvedBy): Household
    {
        $members = $this->knownMembers($import);

        return DB::transaction(function () use ($import, $resolvedBy, $members): Household {
            if (! $members->contains(fn (array $member): bool => $member['role'] === HouseholdRole::Admin)) {
                $members = $members->values()->map(fn (array $member, int $index): array => $index === 0 ? [...$member, 'role' => HouseholdRole::Admin] : $member);
            }

            $creator = $members->first(fn (array $member): bool => $member['role'] === HouseholdRole::Admin);

            $household = Household::query()->create(['name' => $import->name, 'created_by' => $creator ? $creator['user']->getKey() : null]);

            foreach ($members as $member) {
                $household->members()->create(['user_id' => $member['user']->getKey(), 'role' => $member['role'], 'joined_at' => now()]);
            }

            $this->resolve($import, HouseholdImportStatus::Confirmed, $resolvedBy, $household);
            $this->events->dispatch(HouseholdEvents::CREATED, $household->snapshot(), HouseholdEvents::memberIds($household));

            return $household;
        });
    }

    /**
     * Adds the proposed members that are missing; existing members keep their role.
     */
    public function mergeInto(HouseholdImport $import, Household $household, User $resolvedBy): void
    {
        $members = $this->knownMembers($import);

        DB::transaction(function () use ($import, $household, $resolvedBy, $members): void {
            $current = Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();
            $household->setRawAttributes($current->getAttributes(), true);
            $existing = array_map('intval', $household->members()->pluck('user_id')->all());
            $added = $members->reject(fn (array $member): bool => in_array((int) $member['user']->getKey(), $existing, true));

            foreach ($added as $member) {
                $household->members()->create(['user_id' => $member['user']->getKey(), 'role' => $member['role'], 'joined_at' => now()]);
            }

            $this->resolve($import, HouseholdImportStatus::Confirmed, $resolvedBy, $household);

            if ($added->isNotEmpty()) {
                $household->version++;
                $household->save();
                $this->events->dispatch(HouseholdEvents::UPDATED, $household->snapshot(), HouseholdEvents::memberIds($household));
            }
        });
    }

    public function reject(HouseholdImport $import, User $resolvedBy): void
    {
        $this->resolve($import, HouseholdImportStatus::Rejected, $resolvedBy);
    }

    /**
     * @return Collection<int, array{user: User, role: HouseholdRole}>
     */
    private function knownMembers(HouseholdImport $import): Collection
    {
        if ($import->status !== HouseholdImportStatus::Pending) {
            throw new InvalidArgumentException('The proposal is resolved already.');
        }

        $members = $import->knownMembers();

        if ($members->isEmpty()) {
            throw new InvalidArgumentException('None of the proposed members has an account here.');
        }

        return $members;
    }

    private function resolve(HouseholdImport $import, HouseholdImportStatus $status, User $resolvedBy, ?Household $household = null): void
    {
        $import->update([
            'status' => $status,
            'household_id' => $household?->getKey(),
            'resolved_by' => $resolvedBy->getKey(),
            'resolved_at' => now(),
        ]);
    }
}
