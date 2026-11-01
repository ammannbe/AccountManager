<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
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
     * Groups of the identity provider the user should be in: one per active service account.
     *
     * @return list<string>
     */
    public function desiredGroups(): array
    {
        return array_values($this->serviceAccounts()
            ->where('status', AccountStatus::Active)
            ->with('service')
            ->get()
            ->map(fn (ServiceAccount $account): ?string => $account->service->access_group)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_admin;
    }
}
