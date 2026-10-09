<?php

namespace CheapSqlScheme\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SqlColumn extends Model
{
    protected $table = 'sql_columns';

    protected $fillable = [
        'sql_table_id',
        'uid',
        'position',
        'name',
        'type',
        'length',
        'nullable',
        'is_primary',
        'is_unique',
        'is_index',
        'is_auto_increment',
        'default_value',
        'note',
    ];

    protected $casts = [
        'position' => 'integer',
        'nullable' => 'boolean',
        'is_primary' => 'boolean',
        'is_unique' => 'boolean',
        'is_index' => 'boolean',
        'is_auto_increment' => 'boolean',
    ];

    public function sqlTable(): BelongsTo
    {
        return $this->belongsTo(SqlTable::class);
    }
}
