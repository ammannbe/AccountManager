<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property HouseholdRole $role
 * @property Carbon $expires_at
 */
class HouseholdInvitation extends Model
{
    /** @use HasFactory<HouseholdInvitationFactory> */
    use HasFactory;

    public const LIFETIME_DAYS = 14;

    protected $fillable = ['household_id', 'email', 'token_hash', 'role', 'invited_by', 'expires_at', 'accepted_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->pending()->where('token_hash', static::hash($token))->first();
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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<HouseholdInvitation>  $query
     * @return Builder<HouseholdInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * Invitations sent to the user's address (verified or not; accepting checks that).
     *
     * @param  Builder<HouseholdInvitation>  $query
     * @return Builder<HouseholdInvitation>
     */
    public function scopeSentTo(Builder $query, User $user): Builder
    {
        return $query->where('email', mb_strtolower($user->email));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Possession of the token is not enough: the account must own the verified address.
     */
    public function isFor(?User $user): bool
    {
        return $user !== null
            && $user->hasVerifiedEmail()
            && mb_strtolower($user->email) === mb_strtolower($this->email);
    }
}
