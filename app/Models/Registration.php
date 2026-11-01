<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A self-registration for a service, waiting for the e-mail confirmation (double opt-in).
 */
class Registration extends Model
{
    public const LIFETIME_DAYS = 7;

    protected $fillable = ['service_id', 'email', 'name', 'locale', 'token_hash', 'expires_at', 'confirmed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<Registration>  $query
     * @return Builder<Registration>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at')->where('expires_at', '>', now());
    }
}
