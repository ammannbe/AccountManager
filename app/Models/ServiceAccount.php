<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Observers\ServiceAccountObserver;
use Database\Factories\ServiceAccountFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property AccountStatus $status
 * @property bool $is_admin
 */
#[ObservedBy(ServiceAccountObserver::class)]
class ServiceAccount extends Model
{
    /** @use HasFactory<ServiceAccountFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    protected $fillable = [
        'user_id',
        'service_id',
        'status',
        'is_admin',
        'external_username',
        'note',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'is_admin' => 'boolean',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<Suspension, $this>
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }
}
