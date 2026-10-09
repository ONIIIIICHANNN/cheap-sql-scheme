<?php

namespace CheapSqlScheme\Services;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Читает структуру базы приложения: таблицы, колонки, одноколоночные
 * индексы и внешние ключи — в терминах редактора (типы Blueprint).
 */
class DatabaseIntrospector
{
    /**
     * @return array<int, array{
     *     name: string,
     *     columns: array<int, array<string, mixed>>,
     *     foreign: array<int, array{name: ?string, column: string, table: string, references: string, on_delete: ?string, on_update: ?string}>
     * }>
     */
    public function read(): array
    {
        $schema = Schema::connection(config('cheap-sql-scheme.project_schema.connection'));
        $exclude = array_map('strtolower', (array) config('cheap-sql-scheme.project_schema.exclude', []));

        $tables = [];
        foreach ($this->tableNames($schema) as $name) {
            if (in_array(strtolower($name), $exclude, true)) {
                continue;
            }
            $tables[] = $this->table($schema, $name);
        }

        usort($tables, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $tables;
    }

    /** @return array<int, string> */
    private function tableNames(Builder $schema): array
    {
        // С Laravel 12 getTables() без аргумента отдаёт таблицы всех схем
        // (в MySQL — всех баз), поэтому просим только текущую.
        $tables = method_exists($schema, 'getCurrentSchemaName')
            ? $schema->getTables($schema->getCurrentSchemaName())
            : $schema->getTables();

        return array_values(array_unique(array_map(fn ($t) => $t['name'], $tables)));
    }

    /** @return array<string, mixed> */
    private function table(Builder $schema, string $name): array
    {
        $primary = [];
        $unique = [];
        $index = [];
        foreach ($schema->getIndexes($name) as $idx) {
            // Составные индексы редактор не умеет — их не трогаем вовсе.
            if (count($idx['columns']) !== 1) {
                continue;
            }
            $column = strtolower($idx['columns'][0]);
            if ($idx['primary']) {
                $primary[$column] = true;
            } elseif ($idx['unique']) {
                $unique[$column] = true;
            } elseif (! str_ends_with(strtolower((string) $idx['name']), '_foreign')) {
                // Индекс «_foreign» MySQL создаёт сам под внешний ключ.
                $index[$column] = true;
            }
        }

        $columns = [];
        foreach ($schema->getColumns($name) as $col) {
            $key = strtolower($col['name']);
            $isPrimary = isset($primary[$key]);
            $autoIncrement = (bool) ($col['auto_increment'] ?? false);
            [$type, $length] = $this->type($col, $isPrimary && $autoIncrement);

            $columns[] = [
                'name' => $col['name'],
                'type' => $type,
                'length' => $length,
                'nullable' => (bool) $col['nullable'],
                'is_primary' => $isPrimary,
                'is_unique' => isset($unique[$key]),
                'is_index' => isset($index[$key]),
                'is_auto_increment' => $autoIncrement,
                'default_value' => $autoIncrement ? null : $this->defaultValue($col['default'] ?? null),
            ];
        }

        $foreign = [];
        foreach ($schema->getForeignKeys($name) as $fk) {
            if (count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1) {
                continue;
            }
            $foreign[] = [
                'name' => $fk['name'] ?? null,
                'column' => $fk['columns'][0],
                'table' => $fk['foreign_table'],
                'references' => $fk['foreign_columns'][0],
                'on_delete' => Snapshot::action($fk['on_delete'] ?? null),
                'on_update' => Snapshot::action($fk['on_update'] ?? null),
            ];
        }

        return ['name' => $name, 'columns' => $columns, 'foreign' => $foreign];
    }

    /**
     * Тип колонки базы → тип редактора и длина.
     *
     * @return array{0: string, 1: ?string}
     */
    private function type(array $col, bool $isId): array
    {
        $full = strtolower((string) ($col['type'] ?? ''));
        $name = strtolower((string) ($col['type_name'] ?? $full));
        $length = preg_match('/\(([\d\s,]+)\)/', $full, $m) ? Snapshot::length($m[1]) : null;

        if ($isId) {
            return ['id', null];
        }

        if (str_starts_with($full, 'tinyint(1)') || in_array($name, ['bool', 'boolean'], true)) {
            return ['boolean', null];
        }

        return match (true) {
            in_array($name, ['bigint', 'int8'], true) => ['bigInteger', null],
            in_array($name, ['int', 'integer', 'int4', 'mediumint', 'smallint', 'int2', 'tinyint'], true) => ['integer', null],
            in_array($name, ['varchar', 'character varying', 'nvarchar'], true) => ['string', $length],
            in_array($name, ['char', 'bpchar', 'character', 'nchar'], true) => $length === '36' ? ['uuid', null] : ['string', $length],
            in_array($name, ['text', 'tinytext', 'mediumtext', 'longtext', 'clob'], true) => ['text', null],
            $name === 'date' => ['date', null],
            $name === 'datetime' => ['dateTime', null],
            str_starts_with($name, 'timestamp') => ['timestamp', null],
            str_starts_with($name, 'time') => ['time', null],
            in_array($name, ['decimal', 'numeric'], true) => ['decimal', $length],
            in_array($name, ['float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => ['float', null],
            in_array($name, ['json', 'jsonb'], true) => ['json', null],
            $name === 'uuid' => ['uuid', null],
            default => ['string', $length],
        };
    }

    /** Значение по умолчанию без кавычек и приведений типа (PostgreSQL: 'x'::text). */
    private function defaultValue(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $value = trim((string) $default);
        if ($value === '' || strtoupper($value) === 'NULL' || str_starts_with(strtolower($value), 'nextval(')) {
            return null;
        }
        $value = (string) preg_replace('/::[\w\s]+(\[\])?$/', '', $value);
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }
}
