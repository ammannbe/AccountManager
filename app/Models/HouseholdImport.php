<?php

namespace App\Models;

use App\Enums\HouseholdImportStatus;
use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A local household an app proposes when switching to HOUSEHOLDS_DRIVER=konto. An admin creates
 * a new household from it or merges it into an existing one; the app then polls the uuid.
 *
 * @property HouseholdImportStatus $status
 * @property list<array{sub: string, role: string}> $members
 */
class HouseholdImport extends Model
{
    protected $fillable = ['service_id', 'local_id', 'name', 'members', 'status', 'household_id', 'resolved_by', 'resolved_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'members' => 'array',
            'status' => HouseholdImportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The proposed members known here, with their proposed role.
     *
     * @return Collection<int, array{user: User, role: HouseholdRole}>
     */
    public function knownMembers(): Collection
    {
        $roles = [];

        foreach ($this->members as $member) {
            $roles[$member['sub']] = HouseholdRole::tryFrom($member['role']) ?? HouseholdRole::Member;
        }

        return User::query()
            ->whereIn('oidc_sub', array_keys($roles))
            ->get()
            ->map(fn (User $user): array => ['user' => $user, 'role' => $roles[(string) $user->oidc_sub] ?? HouseholdRole::Member])
            ->values();
    }

    /**
     * Subjects the app reported that have no user here.
     *
     * @return list<string>
     */
    public function unknownSubs(): array
    {
        $subs = array_column($this->members, 'sub');
        $known = User::query()->whereIn('oidc_sub', $subs)->pluck('oidc_sub')->map(fn ($sub): string => (string) $sub)->all();

        return array_values(array_diff($subs, $known));
    }

    /**
     * An existing household with the same name that shares a member, as a suggestion to merge into.
     */
    public function suggestedHousehold(): ?Household
    {
        $userIds = $this->knownMembers()->map(fn (array $member): int => (int) $member['user']->getKey())->all();

        if ($userIds === []) {
            return null;
        }

        return Household::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($this->name)])
            ->whereHas('members', fn ($query) => $query->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->first();
    }
}
