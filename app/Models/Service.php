<?php

namespace App\Models;

use App\Enums\ServiceType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * @property ServiceType $type
 * @property string|null $secret
 * @property Carbon|null $secret_created_at
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Set only through rotateSecret().
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    protected $fillable = [
        'key',
        'name',
        'description',
        'url',
        'login_url',
        'webhook_url',
        'type',
        'access_group',
        'self_signup',
        'requires_approval',
        'visible',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServiceType::class,
            'self_signup' => 'boolean',
            'requires_approval' => 'boolean',
            'visible' => 'boolean',
            'secret' => 'encrypted',
            'secret_created_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * Where "Open" leads: straight into the login of the service if known, so that logging in
     * also links an existing account there.
     */
    public function openUrl(): ?string
    {
        return $this->login_url ?: $this->url;
    }

    /**
     * @return HasMany<ServiceAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('visible', true)->orderBy('position')->orderBy('name');
    }

    /**
     * All groups the AccountManager manages; other groups of a user are left alone.
     *
     * @return list<string>
     */
    public static function managedGroups(): array
    {
        return array_values(array_map('strval', static::query()->whereNotNull('access_group')->orderBy('access_group')->pluck('access_group')->all()));
    }

    /**
     * Generates a new secret for the webhook signatures and the API (HTTP Basic, key:secret) and
     * returns it once; it is stored encrypted, the old one stops working right away.
     */
    public function rotateSecret(): string
    {
        $secret = bin2hex(random_bytes(32));

        $this->forceFill(['secret' => $secret, 'secret_created_at' => now()])->save();

        return $secret;
    }

    public function secretMatches(#[SensitiveParameter] string $secret): bool
    {
        return $this->secret !== null && $this->secret !== '' && hash_equals($this->secret, $secret);
    }

    /**
     * Webhooks need a URL and a secret to sign them.
     */
    public function receivesWebhooks(): bool
    {
        return $this->webhook_url !== null && $this->webhook_url !== '' && $this->secret !== null && $this->secret !== '';
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
