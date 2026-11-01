<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Observers\ServiceAccountObserver;
use Database\Factories\ServiceAccountFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AccountStatus $status
 */
#[ObservedBy(ServiceAccountObserver::class)]
class ServiceAccount extends Model
{
    /** @use HasFactory<ServiceAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'status',
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
}
