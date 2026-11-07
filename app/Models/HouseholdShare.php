<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an app shares with a household (e.g. a cookbook), reported by the app for the overview.
 */
class HouseholdShare extends Model
{
    protected $fillable = ['household_id', 'service_id', 'resource_type', 'resource_id', 'label'];

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
