<?php

namespace CheapSqlScheme\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SqlSchema extends Model
{
    protected $table = 'sql_schemas';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'dialect',
        'canvas',
    ];

    protected $casts = [
        'canvas' => 'array',
    ];

    /**
     * Схемы, которые видит посетитель. Обычно — все: кто попал в редактор, тот
     * в общем рабочем пространстве. С per_user залогиненный видит только свои.
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (config('cheap-sql-scheme.per_user') && $user) {
            $query->where('user_id', (string) $user->getAuthIdentifier());
        }
    }

    public function isVisibleTo(?Authenticatable $user): bool
    {
        return ! config('cheap-sql-scheme.per_user')
            || ! $user
            || (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            config('cheap-sql-scheme.user_model') ?? config('auth.providers.users.model'),
        );
    }

    public function tables(): HasMany
    {
        return $this->hasMany(SqlTable::class);
    }

    public function relations(): HasMany
    {
        return $this->hasMany(SqlRelation::class);
    }
}
