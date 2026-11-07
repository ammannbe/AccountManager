<?php

namespace App\Models;

use App\Observers\EntitlementSourceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feature an admin granted a user directly, without a plan.
 */
#[ObservedBy(EntitlementSourceObserver::class)]
class FeatureGrant extends Model
{
    protected $fillable = ['user_id', 'feature_id', 'note', 'granted_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Feature, $this>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
