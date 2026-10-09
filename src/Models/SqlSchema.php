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
        'source',
        'canvas',
        'baseline',
        'committed_at',
    ];

    protected $casts = [
        'canvas' => 'array',
        'baseline' => 'array',
        'committed_at' => 'datetime',
    ];

    public const SOURCE_DATABASE = 'database';

    public function isProjectSchema(): bool
    {
        return $this->source === self::SOURCE_DATABASE;
    }

    /**
     * Схемы, которые видит посетитель. Обычно — все: кто попал в редактор, тот
     * в общем рабочем пространстве. С per_user залогиненный видит только свои.
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (config('cheap-sql-scheme.per_user') && $user) {
            // Схема базы проекта общая — она у всех одна.
            $query->where(fn (Builder $q) => $q
                ->where('user_id', (string) $user->getAuthIdentifier())
                ->orWhere('source', self::SOURCE_DATABASE));
        }
    }

    public function isVisibleTo(?Authenticatable $user): bool
    {
        return ! config('cheap-sql-scheme.per_user')
            || ! $user
            || $this->isProjectSchema()
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
