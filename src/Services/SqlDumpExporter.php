<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlColumn;
use CheapSqlScheme\Models\SqlRelation;
use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Models\SqlTable;

/**
 * Схема как .sql-файл структуры (без данных) для MySQL, PostgreSQL или SQLite:
 * CREATE TABLE с ключами, затем индексы и внешние ключи. Файл можно сразу
 * выполнить в пустой базе.
 */
class SqlDumpExporter
{
    public const DIALECTS = ['mysql', 'pgsql', 'sqlite'];

    private string $dialect = 'mysql';

    /** @var array<int, SqlColumn> колонка-источник FK → колонка-цель */
    private array $fkTarget = [];

    public function export(SqlSchema $schema, string $dialect): string
    {
        $this->dialect = in_array($dialect, self::DIALECTS, true) ? $dialect : 'mysql';

        $this->fkTarget = [];
        foreach ($schema->relations as $relation) {
            if ($relation->toColumn) {
                $this->fkTarget[$relation->from_column_id] = $relation->toColumn;
            }
        }

        $relationsByTable = [];
        foreach ($schema->relations as $relation) {
            if ($relation->fromColumn && $relation->toColumn) {
                $relationsByTable[$relation->fromColumn->sql_table_id][] = $relation;
            }
        }

        $label = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'][$this->dialect];
        $out = [
            '-- Cheap SQL Scheme · схема «'.$schema->name.'»',
            '-- '.$label.' · '.now()->format('Y-m-d H:i'),
            '-- Структура: таблицы, ключи, индексы и связи.',
            '',
        ];

        if ($this->dialect === 'sqlite') {
            $out[] = 'PRAGMA foreign_keys = ON;';
        }
        if ($this->dialect !== 'mysql') {
            $out[] = 'BEGIN;';
        }
        $out[] = '';

        $indexes = [];
        $comments = [];
        foreach ($schema->tables as $table) {
            $out[] = $this->createTable($table, $relationsByTable[$table->id] ?? []);
            $out[] = '';
            foreach ($table->columns as $column) {
                if ($column->is_index && ! $column->is_unique && ! $column->is_primary) {
                    $indexes[] = 'CREATE INDEX '.$this->q($this->name($table).'_'.$column->name.'_index')
                        .' ON '.$this->q($this->name($table)).' ('.$this->q($column->name).');';
                }
                if ($this->dialect === 'pgsql' && filled($column->note)) {
                    $comments[] = 'COMMENT ON COLUMN '.$this->q($this->name($table)).'.'.$this->q($column->name)
                        .' IS '.$this->string((string) $column->note).';';
                }
            }
        }

        if ($indexes !== []) {
            array_push($out, ...$indexes);
            $out[] = '';
        }

        // Внешние ключи — в конце: таблицы уже все созданы, порядок не важен.
        if ($this->dialect !== 'sqlite') {
            $fks = [];
            foreach ($schema->tables as $table) {
                foreach ($relationsByTable[$table->id] ?? [] as $relation) {
                    $fks[] = 'ALTER TABLE '.$this->q($this->name($table)).' ADD '.$this->foreignClause($table, $relation).';';
                }
            }
            if ($fks !== []) {
                array_push($out, ...$fks);
                $out[] = '';
            }
        }

        if ($comments !== []) {
            array_push($out, ...$comments);
            $out[] = '';
        }

        if ($this->dialect !== 'mysql') {
            $out[] = 'COMMIT;';
        }

        return implode("\n", $out)."\n";
    }

    /** @param  array<int, SqlRelation>  $relations */
    private function createTable(SqlTable $table, array $relations): string
    {
        $lines = [];
        $primary = [];
        foreach ($table->columns as $column) {
            $lines[] = '    '.$this->columnDefinition($column);
            if ($column->is_primary && ! $this->inlinePrimary($column)) {
                $primary[] = $this->q($column->name);
            }
            // В MySQL автоинкремент обязан быть ключом.
            if ($this->dialect === 'mysql' && $this->isAutoIncrement($column) && ! $column->is_primary) {
                $primary[] = $this->q($column->name);
            }
        }
        if ($primary !== []) {
            $lines[] = '    PRIMARY KEY ('.implode(', ', array_unique($primary)).')';
        }
        // SQLite не умеет ALTER TABLE ADD CONSTRAINT — ключи прямо в таблице.
        if ($this->dialect === 'sqlite') {
            foreach ($relations as $relation) {
                $lines[] = '    '.$this->foreignClause($table, $relation);
            }
        }

        $sql = 'CREATE TABLE '.$this->q($this->name($table))." (\n".implode(",\n", $lines)."\n)";
        if ($this->dialect === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        return $sql.';';
    }

    private function foreignClause(SqlTable $table, SqlRelation $relation): string
    {
        $from = $relation->fromColumn;
        $to = $relation->toColumn;
        $sql = 'CONSTRAINT '.$this->q($this->name($table).'_'.$from->name.'_foreign')
            .' FOREIGN KEY ('.$this->q($from->name).') REFERENCES '
            .$this->q($this->name($to->sqlTable)).' ('.$this->q($to->name).')';
        $sql .= $this->action('ON DELETE', (string) $relation->on_delete);
        $sql .= $this->action('ON UPDATE', (string) $relation->on_update);

        return $sql;
    }

    private function action(string $prefix, string $action): string
    {
        return match (strtolower($action)) {
            'cascade' => " {$prefix} CASCADE",
            'set null', 'setnull' => " {$prefix} SET NULL",
            'restrict' => " {$prefix} RESTRICT",
            default => '',
        };
    }

    private function columnDefinition(SqlColumn $column): string
    {
        $sql = $this->q($column->name).' '.$this->type($column);

        if ($this->inlinePrimary($column)) {
            return $sql; // «INTEGER PRIMARY KEY AUTOINCREMENT» в SQLite — уже всё сказано
        }
        if ($this->isAutoIncrement($column)) {
            if ($this->dialect === 'mysql') {
                $sql .= ' NOT NULL AUTO_INCREMENT';
            }

            return $sql; // в PostgreSQL SERIAL/BIGSERIAL уже NOT NULL
        }

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->default_value !== null && $column->default_value !== '') {
            $sql .= ' DEFAULT '.$this->defaultLiteral((string) $column->default_value);
        }
        if ($column->is_unique && ! $column->is_primary) {
            $sql .= ' UNIQUE';
        }
        if ($this->dialect === 'mysql' && filled($column->note)) {
            $sql .= ' COMMENT '.$this->string((string) $column->note);
        }

        return $sql;
    }

    private function isAutoIncrement(SqlColumn $column): bool
    {
        $type = strtolower($column->type);

        return in_array($type, ['id', 'bigincrements', 'increments'], true)
            || ($column->is_auto_increment && in_array($type, ['biginteger', 'integer', 'int'], true));
    }

    /** В SQLite автоинкремент возможен только как INTEGER PRIMARY KEY. */
    private function inlinePrimary(SqlColumn $column): bool
    {
        return $this->dialect === 'sqlite' && $this->isAutoIncrement($column);
    }

    private function type(SqlColumn $column): string
    {
        $type = strtolower($column->type);
        $d = $this->dialect;

        if ($this->isAutoIncrement($column)) {
            $big = $type !== 'integer' && $type !== 'int' && $type !== 'increments';

            return match ($d) {
                'mysql' => $big ? 'BIGINT UNSIGNED' : 'INT UNSIGNED',
                'pgsql' => $big ? 'BIGSERIAL' : 'SERIAL',
                default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            };
        }

        // Колонка-ссылка — того же типа, что и цель, иначе MySQL не создаст ключ.
        $target = $this->fkTarget[$column->id] ?? null;
        if ($target && $this->isAutoIncrement($target)) {
            $big = ! in_array(strtolower($target->type), ['integer', 'int', 'increments'], true);

            return match ($d) {
                'mysql' => $big ? 'BIGINT UNSIGNED' : 'INT UNSIGNED',
                'pgsql' => $big ? 'BIGINT' : 'INTEGER',
                default => 'INTEGER',
            };
        }

        $length = $column->length ? (int) $column->length : null;

        return match ($type) {
            'foreignid', 'biginteger' => match ($d) {
                'mysql' => $type === 'foreignid' ? 'BIGINT UNSIGNED' : 'BIGINT',
                'pgsql' => 'BIGINT',
                default => 'INTEGER',
            },
            'integer', 'int' => $d === 'mysql' ? 'INT' : 'INTEGER',
            'smallinteger' => $d === 'sqlite' ? 'INTEGER' : 'SMALLINT',
            'tinyinteger' => match ($d) {
                'mysql' => 'TINYINT',
                'pgsql' => 'SMALLINT',
                default => 'INTEGER',
            },
            'string', 'varchar' => 'VARCHAR('.($length ?: 255).')',
            'char' => 'CHAR('.($length ?: 255).')',
            'text' => 'TEXT',
            'mediumtext' => $d === 'mysql' ? 'MEDIUMTEXT' : 'TEXT',
            'longtext' => $d === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'boolean', 'bool' => match ($d) {
                'mysql' => 'TINYINT(1)',
                'pgsql' => 'BOOLEAN',
                default => 'INTEGER',
            },
            'date' => 'DATE',
            'datetime' => match ($d) {
                'pgsql' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                default => 'DATETIME',
            },
            'timestamp' => match ($d) {
                'mysql' => 'TIMESTAMP',
                'pgsql' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                default => 'DATETIME',
            },
            'time' => $d === 'pgsql' ? 'TIME(0) WITHOUT TIME ZONE' : 'TIME',
            'decimal' => 'DECIMAL('.($column->length ? preg_replace('/[^0-9,]/', '', (string) $column->length) : '8,2').')',
            'float' => $d === 'mysql' ? 'FLOAT' : 'REAL',
            'double' => match ($d) {
                'mysql' => 'DOUBLE',
                'pgsql' => 'DOUBLE PRECISION',
                default => 'REAL',
            },
            'json' => $d === 'sqlite' ? 'TEXT' : 'JSON',
            'jsonb' => match ($d) {
                'pgsql' => 'JSONB',
                'mysql' => 'JSON',
                default => 'TEXT',
            },
            'uuid' => match ($d) {
                'mysql' => 'CHAR(36)',
                'pgsql' => 'UUID',
                default => 'TEXT',
            },
            default => 'VARCHAR(255)',
        };
    }

    private function defaultLiteral(string $value): string
    {
        if (is_numeric($value)) {
            return $value;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['true', 'false'], true)) {
            return $this->dialect === 'pgsql' ? strtoupper($lower) : ($lower === 'true' ? '1' : '0');
        }
        if (in_array($lower, ['current_timestamp', 'now()', 'null'], true)) {
            return $lower === 'now()' ? 'CURRENT_TIMESTAMP' : strtoupper($lower);
        }

        return $this->string($value);
    }

    private function string(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function q(string $identifier): string
    {
        return $this->dialect === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    private function name(SqlTable $table): string
    {
        return $table->name;
    }
}
