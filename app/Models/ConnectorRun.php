<?php

namespace App\Models;

use App\Enums\ConnectorAction;
use App\Enums\ConnectorRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One connector call for one service account (log of App\Connectors\Connectors).
 *
 * @property ConnectorAction $action
 * @property ConnectorRunStatus $status
 * @property array{user_id?: int|null, name?: string|null, email?: string|null, status?: string|null, external_id?: string|null, external_username?: string|null, connector_data?: array<string, mixed>|null}|null $snapshot
 * @property Carbon|null $finished_at
 */
class ConnectorRun extends Model
{
    protected $fillable = ['service_id', 'service_account_id', 'user_id', 'connector', 'action', 'status', 'attempts', 'message', 'snapshot', 'finished_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ConnectorAction::class,
            'status' => ConnectorRunStatus::class,
            'attempts' => 'integer',
            'snapshot' => 'encrypted:array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ServiceAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class, 'service_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Name and address of the user, also after the user was deleted.
     */
    public function userLabel(): string
    {
        $name = $this->user->name ?? $this->snapshot['name'] ?? null;
        $email = $this->user->email ?? $this->snapshot['email'] ?? null;

        return trim(($name ?? '–').($email ? " ({$email})" : ''));
    }
}
