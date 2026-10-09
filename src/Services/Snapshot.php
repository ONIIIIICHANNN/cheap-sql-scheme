<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlColumn;
use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Models\SqlTable;

/**
 * Снимок структуры схемы — то, с чем сравнивается текущее состояние, чтобы
 * получить миграцию только с изменениями. Таблицы и колонки опознаются по
 * uid: так переименование остаётся переименованием, а не «удалили и создали».
 *
 * Формат:
 *   tables:    [{uid, name, columns: [{uid, name, type, length, nullable, is_primary,
 *               is_unique, is_index, is_auto_increment, default_value}]}]
 *   relations: [{from, to, on_delete, on_update, name?}]  — from/to = uid колонок,
 *              name — настоящее имя внешнего ключа в базе, если оно известно.
 */
class Snapshot
{
    /** Поля колонки, изменение которых требует миграции. */
    public const COLUMN_FIELDS = [
        'type', 'length', 'nullable', 'is_primary', 'is_unique', 'is_index', 'is_auto_increment', 'default_value',
    ];

    /** @return array{tables: array<int, array<string, mixed>>, relations: array<int, array<string, mixed>>} */
    public static function fromSchema(SqlSchema $schema): array
    {
        $schema->loadMissing(['tables.columns', 'relations']);

        $uidById = [];
        $tables = [];
        foreach ($schema->tables as $table) {
            $columns = [];
            foreach ($table->columns as $column) {
                $uidById[$column->id] = $column->uid;
                $columns[] = self::column($column);
            }
            $tables[] = ['uid' => $table->uid, 'name' => $table->name, 'columns' => $columns];
        }

        $relations = [];
        foreach ($schema->relations as $relation) {
            $from = $uidById[$relation->from_column_id] ?? null;
            $to = $uidById[$relation->to_column_id] ?? null;
            if ($from && $to) {
                $relations[] = [
                    'from' => $from,
                    'to' => $to,
                    'on_delete' => self::action($relation->on_delete),
                    'on_update' => self::action($relation->on_update),
                ];
            }
        }

        return ['tables' => $tables, 'relations' => $relations];
    }

    /** @return array<string, mixed> */
    public static function column(SqlColumn $column): array
    {
        return [
            'uid' => $column->uid,
            'name' => $column->name,
            'type' => $column->type,
            'length' => self::length($column->length),
            'nullable' => (bool) $column->nullable,
            'is_primary' => (bool) $column->is_primary,
            'is_unique' => (bool) $column->is_unique,
            'is_index' => (bool) $column->is_index,
            'is_auto_increment' => (bool) $column->is_auto_increment,
            'default_value' => $column->default_value === '' ? null : $column->default_value,
        ];
    }

    /** Колонка снимка → несохранённая модель (для генерации строк Blueprint). */
    public static function columnModel(array $column): SqlColumn
    {
        return (new SqlColumn)->forceFill(collect($column)->only(array_merge(['uid', 'name'], self::COLUMN_FIELDS))->all());
    }

    /** Таблица снимка → несохранённая модель с колонками. */
    public static function tableModel(array $table): SqlTable
    {
        $model = (new SqlTable)->forceFill(['uid' => $table['uid'], 'name' => $table['name']]);
        $model->setRelation('columns', collect($table['columns'])->map(fn ($c) => self::columnModel($c)));

        return $model;
    }

    /**
     * Одинаковы ли колонки по существу. Тип сравнивается без регистра,
     * длина — без пробелов: «8,2» и «8, 2» — одно и то же.
     */
    public static function sameColumn(array $a, array $b): bool
    {
        return self::changedFields($a, $b) === [];
    }

    /** @return array<int, string> */
    public static function changedFields(array $a, array $b): array
    {
        $changed = [];
        foreach (self::COLUMN_FIELDS as $field) {
            $x = $a[$field] ?? null;
            $y = $b[$field] ?? null;
            if ($field === 'type') {
                $x = strtolower((string) $x);
                $y = strtolower((string) $y);
            } elseif ($field === 'length') {
                $x = self::length($x);
                $y = self::length($y);
            } elseif ($field === 'default_value') {
                $x = $x === '' ? null : $x;
                $y = $y === '' ? null : $y;
                $x = $x === null ? null : (string) $x;
                $y = $y === null ? null : (string) $y;
            } else {
                $x = (bool) $x;
                $y = (bool) $y;
            }
            if ($x !== $y) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Колонка «по смыслу» — как её увидит база после migrate. Нужна, чтобы
     * сверять снимок с живой базой без ложных тревог: редактор пишет string
     * без длины, timestamp, foreignId, true, а из базы читается varchar(255),
     * datetime (SQLite), bigint, 1.
     *
     * @param  string  $driver  mysql | mariadb | pgsql | sqlite | sqlsrv
     */
    public static function canonical(array $column, string $driver): array
    {
        $type = strtolower((string) ($column['type'] ?? 'string'));
        $length = self::length($column['length'] ?? null);

        if (($column['is_auto_increment'] ?? false) && ($column['is_primary'] ?? false)) {
            $type = 'id';
        }

        $type = match ($type) {
            'bigincrements', 'increments' => 'id',
            'foreignid' => 'biginteger',
            'varchar' => 'string',
            'bool' => 'boolean',
            'int' => 'integer',
            'longtext', 'mediumtext' => 'text',
            default => $type,
        };

        // Как типы хранятся в конкретной СУБД.
        $type = match ($driver) {
            'sqlite' => match ($type) {
                'biginteger', 'smallinteger', 'tinyinteger' => 'integer',
                'timestamp' => 'datetime',
                'json', 'jsonb' => 'text',
                'uuid' => 'string',
                default => $type,
            },
            'pgsql' => match ($type) {
                'datetime' => 'timestamp',
                default => $type,
            },
            'mariadb' => match ($type) {
                'json' => 'text',
                default => $type,
            },
            default => $type,
        };

        if (in_array($type, ['string', 'char'], true) && in_array($length, [null, '255'], true)) {
            $length = $driver === 'sqlite' ? null : '255';
        }
        if ($type === 'decimal' && $length === null) {
            $length = '8, 2';
        }
        if (! in_array($type, ['string', 'char', 'decimal'], true)) {
            $length = null;
        }
        // SQLite не хранит длину varchar вовсе.
        if ($driver === 'sqlite' && $type === 'string') {
            $length = null;
        }

        $default = $column['default_value'] ?? null;
        if ($default !== null) {
            $default = match (strtolower((string) $default)) {
                'true' => '1',
                'false' => '0',
                default => (string) $default,
            };
        }

        return ['type' => $type, 'length' => $length, 'default_value' => $default] + $column;
    }

    public static function length(mixed $length): ?string
    {
        if ($length === null || $length === '') {
            return null;
        }

        return preg_replace('/\s*,\s*/', ', ', trim((string) $length));
    }

    /** «no action» и пустое значение для миграции — одно и то же. */
    public static function action(?string $action): ?string
    {
        $action = $action === null ? null : strtolower(trim($action));

        return in_array($action, [null, '', 'no action'], true) ? null : $action;
    }
}
