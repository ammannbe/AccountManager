<?php

namespace App\Models;

use App\Enums\ServiceType;
use App\Observers\ServiceObserver;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
#[ObservedBy(ServiceObserver::class)]
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
        'admin_group',
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
     * @return HasMany<Feature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
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
     * All groups the AccountManager manages (access and admin groups of the services, groups of
     * the features); other groups of a user are left alone.
     *
     * @return list<string>
     */
    public static function managedGroups(): array
    {
        $groups = [
            ...static::query()->whereNotNull('access_group')->pluck('access_group')->all(),
            ...static::query()->whereNotNull('admin_group')->pluck('admin_group')->all(),
            ...Feature::query()->whereNotNull('group')->pluck('group')->all(),
        ];
        $groups = array_values(array_unique(array_filter(array_map('strval', $groups), fn (string $group): bool => $group !== '')));
        sort($groups);

        return $groups;
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
