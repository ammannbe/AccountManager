<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last entitlements snapshot of a user at a service: its hash and version. The version grows
 * by one whenever the snapshot changes, so the apps can drop stale events.
 *
 * @property int $version
 * @property string|null $hash
 */
class EntitlementState extends Model
{
    protected $fillable = ['user_id', 'service_id', 'version', 'hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
