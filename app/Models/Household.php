<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A household shared across the apps. The AccountManager keeps the household, its members and
 * invitations; the apps decide what they share with it and mirror the members (by oidc_sub).
 *
 * @property string $uuid
 * @property int $version
 */
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'created_by'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * Only the uuid column is generated; the primary key stays an auto-increment id.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<User, $this, HouseholdMember, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_members')
            ->using(HouseholdMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'joined_at']);
    }

    /**
     * @return HasMany<HouseholdMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /**
     * @return HasMany<HouseholdInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    /**
     * @return HasMany<HouseholdShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(HouseholdShare::class);
    }

    public function roleOf(?User $user): ?HouseholdRole
    {
        if ($user === null) {
            return null;
        }

        return $this->members()->where('user_id', $user->getKey())->first()?->role;
    }

    public function hasMember(?User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    public function isAdministeredBy(?User $user): bool
    {
        return $this->roleOf($user) === HouseholdRole::Admin;
    }

    public function isLastAdmin(User $user): bool
    {
        return $this->isAdministeredBy($user)
            && $this->members()->where('role', HouseholdRole::Admin)->count() === 1;
    }

    /**
     * The current state as the apps receive it (webhooks and API). Only the uuid, the name and
     * oidc_sub plus role of the members leave the AccountManager; members without an identity
     * (local emergency admins) cannot use the apps and are left out.
     *
     * @return array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}
     */
    public function snapshot(): array
    {
        $members = array_values($this->members()
            ->with('user')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (HouseholdMember $member): bool => $member->user->oidc_sub !== null)
            ->map(fn (HouseholdMember $member): array => ['sub' => (string) $member->user->oidc_sub, 'role' => $member->role->value])
            ->all());

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'version' => $this->version,
            'members' => $members,
        ];
    }

    /**
     * @param  Builder<Household>  $query
     * @return Builder<Household>
     */
    public function scopeWithMember(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn (Builder $member) => $member->where('user_id', $user->getKey()));
    }
}
