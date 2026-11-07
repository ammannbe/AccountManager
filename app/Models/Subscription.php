<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Observers\EntitlementSourceObserver;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A plan assigned to a user by an admin. It unlocks the plan's features while it is active and
 * between starts_at and ends_at; plans with a price are billed per period (billed_periods counts the
 * periods already on an invoice, App\Billing\BillingPeriod).
 *
 * @property SubscriptionStatus $status
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int $billed_periods
 */
#[ObservedBy(EntitlementSourceObserver::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'billed_periods', 'note', 'created_by'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'billed_periods' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'billed_periods' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Suspension, $this>
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }

    /**
     * Subscriptions that unlock their features right now.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Ends the subscription now (or keeps an earlier end).
     */
    public function end(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Ended,
            'ends_at' => $this->ends_at !== null && $this->ends_at->lessThan(now()) ? $this->ends_at : now(),
        ]);
    }

    public function isEffective(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->starts_at->lessThanOrEqualTo(now())
            && ($this->ends_at === null || $this->ends_at->greaterThan(now()));
    }
}
