<?php

namespace App\Models;

use App\Enums\ServiceType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ServiceType $type
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'url',
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
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
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
}
