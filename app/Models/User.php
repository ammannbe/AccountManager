<?php

namespace App\Models;

use App\Connectors\Connectors;
use App\Entitlements\Entitlements;
use App\Households\Households;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'billing_name', 'billing_street', 'billing_postal_code', 'billing_city', 'billing_country'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    protected static function booted(): void
    {
        // Memberships go before the user, so the apps learn about it and every household keeps an
        // admin; then every app the user had an account in gets user.deleted and the connectors
        // remove the external accounts (the service accounts go by a cascade without events).
        static::deleting(function (User $user): void {
            app(Households::class)->forget($user);
            app(Entitlements::class)->userDeleted($user);
            app(Connectors::class)->userDeleting($user);
        });

        // A new identity link makes the user visible to the apps (entitlements carry the sub).
        static::saved(function (User $user): void {
            if ($user->wasChanged('oidc_sub') || ($user->wasRecentlyCreated && $user->oidc_sub !== null)) {
                app(Entitlements::class)->refresh($user);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ServiceAccount, $this>
     */
    public function serviceAccounts(): HasMany
    {
        return $this->hasMany(ServiceAccount::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<FeatureGrant, $this>
     */
    public function featureGrants(): HasMany
    {
        return $this->hasMany(FeatureGrant::class);
    }

    /**
     * @return HasMany<Suspension, $this>
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Recipient of an invoice: billing name (or the name), the structured address and the email.
     *
     * @return array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, email: ?string}
     */
    public function billingRecipient(): array
    {
        return [
            'name' => $this->billing_name ?: $this->name,
            'street' => $this->billing_street ?: null,
            'postal_code' => $this->billing_postal_code ?: null,
            'city' => $this->billing_city ?: null,
            'country' => $this->billing_country ?: null,
            'email' => $this->email,
        ];
    }

    /**
     * @return BelongsToMany<Household, $this, HouseholdMember, 'membership'>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_members')
            ->using(HouseholdMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'joined_at']);
    }

    /**
     * @return HasMany<HouseholdMember, $this>
     */
    public function householdMemberships(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /**
     * Groups of the identity provider the user should be in: per active service account its access
     * group, the admin group for admins and the groups of the effective features.
     *
     * @return list<string>
     */
    public function desiredGroups(): array
    {
        return app(Entitlements::class)->groups($this);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }
}
