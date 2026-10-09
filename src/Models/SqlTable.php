<?php

namespace CheapSqlScheme\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SqlTable extends Model
{
    protected $table = 'sql_tables';

    protected $fillable = [
        'sql_schema_id',
        'uid',
        'name',
        'pos_x',
        'pos_y',
        'color',
        'note',
    ];

    protected $casts = [
        'pos_x' => 'integer',
        'pos_y' => 'integer',
    ];

    public function sqlSchema(): BelongsTo
    {
        return $this->belongsTo(SqlSchema::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SqlColumn::class)->orderBy('position');
    }
}
