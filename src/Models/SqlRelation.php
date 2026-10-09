<?php

namespace CheapSqlScheme\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SqlRelation extends Model
{
    protected $table = 'sql_relations';

    protected $fillable = [
        'sql_schema_id',
        'from_column_id',
        'to_column_id',
        'type',
        'on_delete',
        'on_update',
    ];

    public function sqlSchema(): BelongsTo
    {
        return $this->belongsTo(SqlSchema::class);
    }

    public function fromColumn(): BelongsTo
    {
        return $this->belongsTo(SqlColumn::class, 'from_column_id');
    }

    public function toColumn(): BelongsTo
    {
        return $this->belongsTo(SqlColumn::class, 'to_column_id');
    }
}
