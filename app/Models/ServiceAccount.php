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
 * @property string|null $external_id
 * @property array<string, mixed>|null $connector_data
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

    /**
     * Holds e.g. the invitation link of the user.
     *
     * @var list<string>
     */
    protected $hidden = ['connector_data'];

    protected $fillable = [
        'user_id',
        'service_id',
        'status',
        'is_admin',
        'external_id',
        'external_username',
        'connector_data',
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
            'connector_data' => 'encrypted:array',
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
     * @return HasMany<ConnectorRun, $this>
     */
    public function connectorRuns(): HasMany
    {
        return $this->hasMany(ConnectorRun::class);
    }

    /**
     * Merges values into the connector data (null removes a key) without saving.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeConnectorData(array $values): static
    {
        $data = array_merge($this->connector_data ?? [], $values);

        $this->connector_data = array_filter($data, fn (mixed $value): bool => $value !== null) ?: null;

        return $this;
    }

    /**
     * @return HasMany<Suspension, $this>
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }
}
