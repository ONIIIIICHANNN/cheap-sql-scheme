<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlColumn;
use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Models\SqlTable;
use Illuminate\Support\Str;

class SqlSchemaExporter
{
    /**
     * Соответствие наших типов колонок методам Blueprint.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'id' => 'id',
        'bigincrements' => 'bigIncrements',
        'biginteger' => 'bigInteger',
        'integer' => 'integer',
        'int' => 'integer',
        'smallinteger' => 'smallInteger',
        'tinyinteger' => 'tinyInteger',
        'string' => 'string',
        'varchar' => 'string',
        'char' => 'char',
        'text' => 'text',
        'longtext' => 'longText',
        'mediumtext' => 'mediumText',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'date' => 'date',
        'datetime' => 'dateTime',
        'timestamp' => 'timestamp',
        'time' => 'time',
        'decimal' => 'decimal',
        'float' => 'float',
        'double' => 'double',
        'json' => 'json',
        'jsonb' => 'jsonb',
        'uuid' => 'uuid',
        'foreignid' => 'foreignId',
    ];

    /**
     * Сгенерировать текст миграции Laravel для всей схемы.
     */
    public function toLaravelMigration(SqlSchema $schema): string
    {
        $relationsByFromTable = $this->relationsGroupedByFromTable($schema);

        $blocks = [];
        foreach ($schema->tables as $table) {
            $blocks[] = $this->tableBlock($table);
        }

        $foreignBlocks = [];
        foreach ($schema->tables as $table) {
            if (! empty($relationsByFromTable[$table->id])) {
                $foreignBlocks[] = $this->foreignKeysBlock($table, $relationsByFromTable[$table->id]);
            }
        }

        $up = implode("\n\n", array_merge($blocks, $foreignBlocks));

        $drops = [];
        foreach ($schema->tables->reverse() as $table) {
            $drops[] = "        Schema::dropIfExists('".$this->tableName($table)."');";
        }
        $down = implode("\n", $drops);

        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        $up
            }

            public function down(): void
            {
        $down
            }
        };

        PHP;
    }

    /**
     * Схема как набор миграций Laravel: по файлу на таблицу в порядке зависимостей
     * (на кого ссылаются — создаётся раньше). Внешний ключ пишется в миграцию
     * своей таблицы, если цель уже создана; связи по кругу уходят в последний
     * файл «add_foreign_keys».
     *
     * @return array<int, array{filename: string, content: string}>
     */
    public function toMigrationFiles(SqlSchema $schema, ?string $date = null): array
    {
        $byFrom = $this->relationsGroupedByFromTable($schema);
        $columnTable = [];
        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                $columnTable[$column->id] = $table->id;
            }
        }

        // Зависимости таблицы — таблицы, на которые она ссылается (кроме себя).
        $deps = [];
        foreach ($schema->tables as $table) {
            $deps[$table->id] = [];
            foreach ($byFrom[$table->id] ?? [] as $relation) {
                $target = $columnTable[$relation->to_column_id] ?? null;
                if ($target !== null && $target !== $table->id) {
                    $deps[$table->id][$target] = true;
                }
            }
        }

        // Топологический порядок. Если все оставшиеся ждут друг друга (цикл), раньше
        // ставим ту, на которую ссылается больше оставшихся таблиц.
        $ordered = [];
        $done = [];
        $left = $schema->tables->values()->all();
        while ($left !== []) {
            $index = null;
            foreach ($left as $i => $table) {
                if (array_diff_key($deps[$table->id], $done) === []) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                $best = -1;
                foreach ($left as $i => $table) {
                    $wanted = count(array_filter($left, fn ($other) => isset($deps[$other->id][$table->id])));
                    if ($wanted > $best) {
                        $best = $wanted;
                        $index = $i;
                    }
                }
            }
            $ordered[] = $left[$index];
            $done[$left[$index]->id] = true;
            unset($left[$index]);
        }

        $date ??= now()->format('Y_m_d');
        $files = [];
        $created = [];
        $deferred = [];
        foreach ($ordered as $i => $table) {
            $inline = [];
            foreach ($byFrom[$table->id] ?? [] as $relation) {
                $target = $columnTable[$relation->to_column_id] ?? null;
                if ($target === $table->id || isset($created[$target])) {
                    $line = $this->foreignLine($relation);
                    if ($line !== null) {
                        $inline[] = $line;
                    }
                } else {
                    $deferred[$table->id][] = $relation;
                }
            }
            $created[$table->id] = true;

            $name = $this->tableName($table);
            $files[] = [
                'filename' => sprintf('%s_%06d_create_%s_table.php', $date, $i + 1, $name),
                'content' => $this->migrationFile(
                    $this->tableBlock($table, $inline),
                    "        Schema::dropIfExists('{$name}');",
                ),
            ];
        }

        if ($deferred !== []) {
            $up = [];
            $down = [];
            foreach ($ordered as $table) {
                if (empty($deferred[$table->id])) {
                    continue;
                }
                $up[] = $this->foreignKeysBlock($table, $deferred[$table->id]);
                $name = $this->tableName($table);
                $drops = array_map(
                    fn ($relation) => "            \$table->dropForeign(['{$relation->fromColumn?->name}']);",
                    $deferred[$table->id],
                );
                $down[] = "        Schema::table('{$name}', function (Blueprint \$table) {\n".implode("\n", $drops)."\n        });";
            }
            $files[] = [
                'filename' => sprintf('%s_%06d_add_foreign_keys_to_%s_tables.php', $date, count($ordered) + 1, Str::snake($schema->name)),
                'content' => $this->migrationFile(implode("\n\n", $up), implode("\n\n", $down)),
            ];
        }

        return $files;
    }

    /** ZIP с файлами миграций — для скачивания одним архивом. */
    public function toZip(SqlSchema $schema): string
    {
        return $this->zipFiles($this->toMigrationFiles($schema));
    }

    /**
     * Архив с папкой migrations.
     *
     * @param  array<int, array{filename: string, content: string}>  $files
     */
    public function zipFiles(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cheap-sql-scheme');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $file) {
            $zip->addFromString('migrations/'.$file['filename'], $file['content']);
        }
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    public function migrationFile(string $up, string $down): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        $up
            }

            public function down(): void
            {
        $down
            }
        };

        PHP;
    }

    /** Строка внешнего ключа: $table->foreign(...)->references(...)->on(...). */
    private function foreignLine(\CheapSqlScheme\Models\SqlRelation $relation): ?string
    {
        $fromColumn = $relation->fromColumn;
        $toColumn = $relation->toColumn;
        if (! $fromColumn || ! $toColumn) {
            return null;
        }

        $refTable = $this->tableName($toColumn->sqlTable);
        $line = "\$table->foreign('{$fromColumn->name}')"
            ."->references('{$toColumn->name}')->on('{$refTable}')";
        $line .= $this->referentialAction((string) $relation->on_delete, 'OnDelete');
        $line .= $this->referentialAction((string) $relation->on_update, 'OnUpdate');

        return $line;
    }

    /**
     * @param  array<int, \CheapSqlScheme\Models\SqlRelation>  $relations
     */
    private function foreignKeysBlock(SqlTable $table, array $relations): string
    {
        $name = $this->tableName($table);
        $lines = ["        Schema::table('{$name}', function (Blueprint \$table) {"];

        foreach ($relations as $relation) {
            $fromColumn = $relation->fromColumn;
            $toColumn = $relation->toColumn;
            if (! $fromColumn || ! $toColumn) {
                continue;
            }

            $refTable = $this->tableName($toColumn->sqlTable);
            $line = "            \$table->foreign('{$fromColumn->name}')"
                ."->references('{$toColumn->name}')->on('{$refTable}')";
            $line .= $this->referentialAction((string) $relation->on_delete, 'OnDelete');
            $line .= $this->referentialAction((string) $relation->on_update, 'OnUpdate');
            $lines[] = $line.';';
        }

        $lines[] = '        });';

        return implode("\n", $lines);
    }

    public function referentialAction(string $action, string $suffix): string
    {
        return match (strtolower($action)) {
            'cascade' => "->cascade{$suffix}()",
            'set null', 'setnull' => "->null{$suffix}()",
            'restrict' => "->restrict{$suffix}()",
            default => '',
        };
    }

    /** @param  array<int, string>  $foreignLines  внешние ключи прямо в Schema::create */
    public function tableBlock(SqlTable $table, array $foreignLines = [], ?string $name = null): string
    {
        $name ??= $this->tableName($table);
        $lines = ["        Schema::create('{$name}', function (Blueprint \$table) {"];

        $hasTimestamps = false;
        foreach ($table->columns as $column) {
            $lower = strtolower($column->name);
            if (in_array($lower, ['created_at', 'updated_at'], true)) {
                $hasTimestamps = true;

                continue;
            }
            $lines[] = '            '.$this->columnLine($column).';';
        }

        if ($hasTimestamps) {
            $lines[] = '            $table->timestamps();';
        }

        foreach ($foreignLines as $line) {
            $lines[] = '            '.$line.';';
        }

        $lines[] = '        });';

        return implode("\n", $lines);
    }

    /**
     * Строка колонки Blueprint. Без $withIndexes — без unique/index/primary:
     * так колонку описывают в ->change(), а индексы ставят отдельно.
     */
    public function columnLine(SqlColumn $column, bool $withIndexes = true): string
    {
        $method = self::TYPE_MAP[strtolower($column->type)] ?? 'string';

        // id() и *Increments сами задают первичный ключ и автоинкремент.
        if ($method === 'id' || $method === 'bigIncrements') {
            return "\$table->{$method}('{$column->name}')";
        }

        if ($column->is_auto_increment && in_array($method, ['bigInteger', 'integer'], true)) {
            $method = $method === 'bigInteger' ? 'bigIncrements' : 'increments';

            return "\$table->{$method}('{$column->name}')";
        }

        $args = "'{$column->name}'";
        if (in_array($method, ['string', 'char'], true) && $column->length) {
            $args .= ', '.((int) $column->length);
        }
        if ($method === 'decimal' && $column->length) {
            $args .= ', '.$column->length; // ожидается формат "8, 2"
        }

        $line = "\$table->{$method}({$args})";

        if ($column->nullable) {
            $line .= '->nullable()';
        }
        if ($withIndexes && $column->is_unique) {
            $line .= '->unique()';
        }
        if ($withIndexes && $column->is_primary && ! $column->is_auto_increment) {
            $line .= '->primary()';
        }
        if ($withIndexes && $column->is_index && ! $column->is_unique && ! $column->is_primary) {
            $line .= '->index()';
        }
        if ($column->default_value !== null && $column->default_value !== '') {
            $line .= '->default('.$this->defaultLiteral($column).')';
        }

        return $line;
    }

    private function defaultLiteral(SqlColumn $column): string
    {
        $value = $column->default_value;

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (in_array(strtolower((string) $value), ['true', 'false'], true)) {
            return strtolower((string) $value);
        }

        return "'".addslashes((string) $value)."'";
    }

    private function tableName(SqlTable $table): string
    {
        return Str::snake($table->name);
    }

    /**
     * Связи, сгруппированные по таблице-источнику (владельцу внешнего ключа).
     *
     * @return array<int, array<int, \CheapSqlScheme\Models\SqlRelation>>
     */
    private function relationsGroupedByFromTable(SqlSchema $schema): array
    {
        $grouped = [];
        $columnTableMap = [];
        foreach ($schema->tables as $table) {
            foreach ($table->columns as $column) {
                $columnTableMap[$column->id] = $table->id;
            }
        }

        foreach ($schema->relations as $relation) {
            $fromTableId = $columnTableMap[$relation->from_column_id] ?? null;
            if ($fromTableId === null) {
                continue;
            }
            $grouped[$fromTableId][] = $relation;
        }

        return $grouped;
    }
}
