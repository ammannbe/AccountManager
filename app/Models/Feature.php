<?php

namespace App\Models;

use App\Observers\FeatureObserver;
use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A function of a service the AccountManager unlocks, e.g. shoppinglist.ai. The apps only get the
 * key (in the entitlements); the optional group is set in the identity provider for the token.
 *
 * @property string $key
 * @property string|null $group
 */
#[ObservedBy(FeatureObserver::class)]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    /**
     * Allowed keys: lowercase words joined by dots, dashes or underscores (e.g. shoppinglist.ai).
     */
    public const KEY_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

    protected $fillable = ['service_id', 'key', 'name', 'description', 'group'];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features');
    }

    /**
     * @return HasMany<FeatureGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(FeatureGrant::class);
    }

    /**
     * Users that hold the feature through a direct grant or a subscription (in any status), i.e.
     * whose entitlements may change with it.
     *
     * @return list<int>
     */
    public function holderIds(): array
    {
        $granted = $this->grants()->pluck('user_id');
        $subscribed = Subscription::query()->whereIn('plan_id', $this->plans()->select('plans.id'))->pluck('user_id');

        return array_values(array_unique(array_map('intval', [...$granted->all(), ...$subscribed->all()])));
    }
}
