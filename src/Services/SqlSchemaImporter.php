<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\CreateStatement;

class SqlSchemaImporter
{
    /**
     * Промежуточная модель разбора.
     *
     * @var array<string, array{name: string, columns: array<int, array<string, mixed>>}>
     */
    private array $tables = [];

    /**
     * @var array<int, array{from_table: string, from_column: string, to_table: string, to_column: string}>
     */
    private array $relations = [];

    public function import(string $content, string $name, ?Authenticatable $user = null): SqlSchema
    {
        $this->tables = [];
        $this->relations = [];

        if ($this->looksLikeLaravelMigration($content)) {
            $this->parseLaravelMigration($content);
        } else {
            $this->parseSql($content);
        }

        $this->finalizeColumns();

        return DB::transaction(fn () => $this->persist($name, $user));
    }

    /**
     * Оставить в дампе только структуру (CREATE/ALTER TABLE), выбросив данные
     * (INSERT), комментарии и служебные команды. Это и убирает лишнее, и
     * спасает парсер от переполнения памяти на больших дампах phpMyAdmin.
     */
    private function extractDdl(string $sql): string
    {
        preg_match_all('/CREATE\s+TABLE.*?;/is', $sql, $creates);

        return implode("\n\n", $creates[0]);
    }

    private function looksLikeLaravelMigration(string $content): bool
    {
        // Явный SQL-дамп определяем по CREATE TABLE — даже если в данных
        // (INSERT) попадаются PHP-подобные строки вроде «$table->».
        if (preg_match('/CREATE\s+TABLE/i', $content)) {
            return false;
        }

        return Str::contains($content, 'Schema::create') || Str::contains($content, '$table->');
    }

    // --- Сохранение ---------------------------------------------------------

    private function persist(string $name, ?Authenticatable $user): SqlSchema
    {
        $schema = SqlSchema::create([
            'user_id' => $user?->getAuthIdentifier(),
            'name' => $name,
            'dialect' => 'laravel',
            'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
        ]);

        $columnIdByKey = [];
        $index = 0;
        foreach ($this->tables as $tableData) {
            [$posX, $posY] = $this->gridPosition($index++);

            $table = $schema->tables()->create([
                'uid' => (string) Str::uuid(),
                'name' => $tableData['name'],
                'pos_x' => $posX,
                'pos_y' => $posY,
            ]);

            foreach (array_values($tableData['columns']) as $position => $col) {
                $column = $table->columns()->create([
                    'uid' => (string) Str::uuid(),
                    'position' => $position,
                    'name' => $col['name'],
                    'type' => $col['type'] ?? 'string',
                    'length' => $col['length'] ?? null,
                    'nullable' => $col['nullable'] ?? false,
                    'is_primary' => $col['is_primary'] ?? false,
                    'is_unique' => $col['is_unique'] ?? false,
                    'is_index' => $col['is_index'] ?? false,
                    'is_auto_increment' => $col['is_auto_increment'] ?? false,
                    'default_value' => $col['default_value'] ?? null,
                ]);
                $columnIdByKey[$this->columnKey($tableData['name'], $col['name'])] = $column->id;
            }
        }

        foreach ($this->relations as $relation) {
            $fromId = $columnIdByKey[$this->columnKey($relation['from_table'], $relation['from_column'])] ?? null;
            $toId = $columnIdByKey[$this->columnKey($relation['to_table'], $relation['to_column'])] ?? null;
            if (! $fromId || ! $toId) {
                continue;
            }

            $schema->relations()->create([
                'from_column_id' => $fromId,
                'to_column_id' => $toId,
                'type' => 'one_to_many',
            ]);
        }

        return $schema;
    }

    private function columnKey(string $table, string $column): string
    {
        return strtolower($table).'.'.strtolower($column);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function gridPosition(int $index): array
    {
        $perRow = 4;
        $column = $index % $perRow;
        $row = intdiv($index, $perRow);

        return [80 + $column * 320, 80 + $row * 300];
    }

    private function ensureTable(string $name): void
    {
        $key = strtolower($name);
        if (! isset($this->tables[$key])) {
            $this->tables[$key] = ['name' => $name, 'columns' => []];
        }
    }

    private function addColumn(string $table, array $column): void
    {
        $this->ensureTable($table);
        $this->tables[strtolower($table)]['columns'][] = $column;
    }

    // --- Разбор SQL через phpmyadmin/sql-parser -----------------------------

    private function parseSql(string $sql): void
    {
        // Парсим только структуру — без данных и служебных команд.
        $parser = new Parser($this->extractDdl($sql));

        foreach ($parser->statements as $statement) {
            if (! $statement instanceof CreateStatement || $statement->name === null) {
                continue;
            }
            if (! in_array('TABLE', (array) $statement->options?->options, true) && $statement->fields === null) {
                continue;
            }

            $tableName = $statement->name->table;
            if (! $tableName) {
                continue;
            }
            $this->ensureTable($tableName);

            foreach ($statement->fields ?? [] as $field) {
                if ($field->name !== null && $field->type !== null) {
                    $this->addColumn($tableName, $this->columnFromField($field));
                }

                if ($field->key !== null) {
                    $this->applyKey($tableName, $field);
                }

                if ($field->references !== null && $field->name !== null) {
                    $this->addReferenceFromField($tableName, $field);
                }
            }
        }

        // Дампы phpMyAdmin выносят ключи и автоинкремент в отдельные ALTER TABLE.
        $this->parseAlterStatements($sql);
    }

    /**
     * Разбор ALTER TABLE (формат phpMyAdmin): первичные/уникальные ключи,
     * индексы, внешние ключи и AUTO_INCREMENT.
     */
    private function parseAlterStatements(string $sql): void
    {
        preg_match_all('/ALTER\s+TABLE\s+`?(?<table>\w+)`?(?<body>.*?);/is', $sql, $alters, PREG_SET_ORDER);

        foreach ($alters as $alter) {
            $table = $alter['table'];
            $body = $alter['body'];

            $this->flagKeyColumns($table, $body, '/ADD\s+PRIMARY\s+KEY\s*\((?<cols>[^)]+)\)/i', 'is_primary');
            $this->flagKeyColumns($table, $body, '/ADD\s+UNIQUE\s+(?:KEY|INDEX)\s+`?\w+`?\s*\((?<cols>[^)]+)\)/i', 'is_unique');
            $this->flagKeyColumns($table, $body, '/ADD\s+(?:KEY|INDEX)\s+`?\w+`?\s*\((?<cols>[^)]+)\)/i', 'is_index');

            if (preg_match('/MODIFY\s+`?(?<col>\w+)`?[^,;]*?AUTO_INCREMENT/is', $body, $ai)) {
                $this->flagColumn($table, $ai['col'], 'is_auto_increment');
            }

            preg_match_all(
                '/FOREIGN\s+KEY\s*\(`?(?<col>\w+)`?\)\s*REFERENCES\s+`?(?<rtable>\w+)`?\s*\(`?(?<rcol>\w+)`?\)/i',
                $body,
                $fks,
                PREG_SET_ORDER,
            );
            foreach ($fks as $fk) {
                $this->relations[] = [
                    'from_table' => $table,
                    'from_column' => $fk['col'],
                    'to_table' => $fk['rtable'],
                    'to_column' => $fk['rcol'],
                ];
            }
        }

        // Отдельные MODIFY ... AUTO_INCREMENT встречаются и вне общего блока.
        preg_match_all(
            '/ALTER\s+TABLE\s+`?(?<table>\w+)`?\s+MODIFY\s+`?(?<col>\w+)`?[^;]*?AUTO_INCREMENT/is',
            $sql,
            $mods,
            PREG_SET_ORDER,
        );
        foreach ($mods as $mod) {
            $this->flagColumn($mod['table'], $mod['col'], 'is_auto_increment');
        }
    }

    /**
     * Пометить флагом все колонки, перечисленные в скобках ключа.
     */
    private function flagKeyColumns(string $table, string $body, string $pattern, string $flag): void
    {
        if (! preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $match) {
            foreach (explode(',', $match['cols']) as $column) {
                $column = trim($column, " `\t\n");
                if ($column !== '') {
                    $this->flagColumn($table, $column, $flag);
                }
            }
        }
    }

    /**
     * Финальная нормализация: колонка «первичный ключ + автоинкремент»
     * становится Laravel-типом id.
     */
    private function finalizeColumns(): void
    {
        foreach ($this->tables as &$table) {
            foreach ($table['columns'] as &$column) {
                if (($column['is_primary'] ?? false) && ($column['is_auto_increment'] ?? false)) {
                    $column['type'] = 'id';
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function columnFromField(object $field): array
    {
        $rawType = strtolower($field->type->name ?? 'string');
        $parameters = $field->type->parameters ?? [];
        $type = $this->normalizeType($rawType, $parameters);
        $options = $field->options?->options ?? [];
        $optionValues = array_map(
            fn ($option) => is_array($option) ? strtoupper((string) ($option['name'] ?? '')) : strtoupper((string) $option),
            $options,
        );

        return [
            'name' => $field->name,
            'type' => $type,
            'length' => $this->normalizeLength($type, $parameters),
            'nullable' => ! in_array('NOT NULL', $optionValues, true),
            'is_primary' => in_array('PRIMARY KEY', $optionValues, true),
            'is_unique' => in_array('UNIQUE', $optionValues, true),
            'is_auto_increment' => in_array('AUTO_INCREMENT', $optionValues, true),
            'default_value' => $this->extractDefault($options),
        ];
    }

    /**
     * Привести тип из дампа к каноничному набору планнера (методам Blueprint).
     *
     * @param  array<int, mixed>  $parameters
     */
    private function normalizeType(string $rawType, array $parameters): string
    {
        // tinyint(1) — это булев флаг.
        if ($rawType === 'tinyint' && (int) ($parameters[0] ?? 0) === 1) {
            return 'boolean';
        }

        return match ($rawType) {
            'bigint' => 'bigInteger',
            'int', 'integer', 'mediumint', 'smallint', 'tinyint' => 'integer',
            'varchar', 'char' => 'string',
            'text', 'tinytext', 'mediumtext', 'longtext' => 'text',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'decimal', 'numeric' => 'decimal',
            'float', 'double', 'real' => 'float',
            'json' => 'json',
            'uuid' => 'uuid',
            'boolean', 'bool' => 'boolean',
            'enum', 'set' => 'string',
            default => 'string',
        };
    }

    /**
     * Длина/точность имеет смысл только для строк и decimal; для enum и
     * прочего параметры в скобках — это не длина.
     *
     * @param  array<int, mixed>  $parameters
     */
    private function normalizeLength(string $type, array $parameters): ?string
    {
        if (empty($parameters)) {
            return null;
        }
        if ($type === 'string') {
            return (string) $parameters[0];
        }
        if ($type === 'decimal') {
            return implode(', ', array_map('strval', $parameters));
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function extractDefault(array $options): ?string
    {
        foreach ($options as $option) {
            if (is_array($option) && strtoupper((string) ($option['name'] ?? '')) === 'DEFAULT') {
                return isset($option['value']) ? trim((string) $option['value'], "'\"") : null;
            }
        }

        return null;
    }

    private function applyKey(string $tableName, object $field): void
    {
        $key = $field->key;
        $type = strtoupper((string) ($key->type ?? ''));
        $columns = array_map(
            fn ($column) => is_array($column) ? ($column['name'] ?? null) : $column,
            $key->columns ?? [],
        );

        foreach (array_filter($columns) as $columnName) {
            $this->flagColumn($tableName, (string) $columnName, match (true) {
                Str::contains($type, 'PRIMARY') => 'is_primary',
                Str::contains($type, 'UNIQUE') => 'is_unique',
                Str::contains($type, 'FOREIGN') => null,
                default => 'is_index',
            });
        }

        if (Str::contains($type, 'FOREIGN') && $field->references !== null) {
            $refTable = $field->references->table->table ?? null;
            $refColumns = $field->references->columns ?? [];
            foreach ($columns as $i => $columnName) {
                if ($refTable && isset($refColumns[$i]) && $columnName) {
                    $this->relations[] = [
                        'from_table' => $tableName,
                        'from_column' => (string) $columnName,
                        'to_table' => $refTable,
                        'to_column' => (string) $refColumns[$i],
                    ];
                }
            }
        }
    }

    private function addReferenceFromField(string $tableName, object $field): void
    {
        $refTable = $field->references->table->table ?? null;
        $refColumns = $field->references->columns ?? [];
        if ($refTable && ! empty($refColumns)) {
            $this->relations[] = [
                'from_table' => $tableName,
                'from_column' => (string) $field->name,
                'to_table' => $refTable,
                'to_column' => (string) $refColumns[0],
            ];
        }
    }

    private function flagColumn(string $tableName, string $columnName, ?string $flag): void
    {
        if ($flag === null) {
            return;
        }
        $key = strtolower($tableName);
        if (! isset($this->tables[$key])) {
            return;
        }
        // Индексная запись, а не foreach по ссылке: «?? []» скопировал бы
        // массив и потерял изменения.
        foreach ($this->tables[$key]['columns'] as $index => $column) {
            if (strtolower($column['name']) === strtolower($columnName)) {
                $this->tables[$key]['columns'][$index][$flag] = true;
            }
        }
    }

    // --- Разбор миграции Laravel (Blueprint) --------------------------------

    private function parseLaravelMigration(string $content): void
    {
        preg_match_all(
            '/Schema::create\(\s*[\'"](?<table>\w+)[\'"]\s*,\s*function\s*\([^)]*\)\s*\{(?<body>.*?)\}\s*\)\s*;/s',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $tableName = $match['table'];
            $this->ensureTable($tableName);
            $this->parseBlueprintBody($tableName, $match['body']);
        }
    }

    private function parseBlueprintBody(string $tableName, string $body): void
    {
        foreach (preg_split('/;\s*\n/', $body) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || ! Str::contains($statement, '$table->')) {
                continue;
            }

            if (! preg_match('/\$table->(?<method>\w+)\((?<args>[^)]*)\)/', $statement, $call)) {
                continue;
            }

            $method = $call['method'];
            $args = $this->splitArgs($call['args']);
            $columnName = isset($args[0]) ? trim($args[0], "'\" ") : null;

            // Методы с именем колонки по умолчанию.
            if ($columnName === null || $columnName === '') {
                $columnName = match ($method) {
                    'id' => 'id',
                    'uuid', 'ulid' => 'uuid',
                    'rememberToken' => 'remember_token',
                    default => null,
                };
            }

            if ($method === 'timestamps') {
                $this->addColumn($tableName, ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true]);
                $this->addColumn($tableName, ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true]);

                continue;
            }

            if ($method === 'foreign') {
                $this->parseForeignStatement($tableName, $columnName, $statement);

                continue;
            }

            if ($columnName === null || in_array($method, ['index', 'unique', 'primary'], true)) {
                if ($columnName !== null) {
                    $this->flagColumn($tableName, $columnName, 'is_'.$method);
                }

                continue;
            }

            $column = [
                'name' => $columnName,
                'type' => $this->blueprintType($method),
                'length' => (isset($args[1]) && is_numeric(trim($args[1]))) ? trim($args[1]) : null,
                'nullable' => Str::contains($statement, '->nullable('),
                'is_unique' => Str::contains($statement, '->unique('),
                'is_index' => Str::contains($statement, '->index('),
                'is_primary' => $method === 'id' || Str::contains($statement, '->primary('),
                'is_auto_increment' => in_array($method, ['id', 'bigIncrements', 'increments'], true),
                'default_value' => $this->extractBlueprintDefault($statement),
            ];
            $this->addColumn($tableName, $column);

            // foreignId('user_id')->constrained() → связь к таблице users.
            if (($method === 'foreignId' || $method === 'foreignIdFor') && Str::contains($statement, '->constrained(')) {
                $this->addConstrainedRelation($tableName, $columnName, $statement);
            }
        }
    }

    private function parseForeignStatement(string $tableName, ?string $columnName, string $statement): void
    {
        if ($columnName === null) {
            return;
        }
        if (preg_match('/->references\(\s*[\'"](?<ref>\w+)[\'"]\s*\).*?->on\(\s*[\'"](?<table>\w+)[\'"]\s*\)/s', $statement, $ref)) {
            $this->relations[] = [
                'from_table' => $tableName,
                'from_column' => $columnName,
                'to_table' => $ref['table'],
                'to_column' => $ref['ref'],
            ];
        }
    }

    private function addConstrainedRelation(string $tableName, string $columnName, string $statement): void
    {
        if (preg_match('/->constrained\(\s*[\'"](?<table>\w+)[\'"]/', $statement, $constrained)) {
            $refTable = $constrained['table'];
        } else {
            // user_id → users
            $refTable = Str::plural(Str::beforeLast($columnName, '_id'));
        }

        $this->relations[] = [
            'from_table' => $tableName,
            'from_column' => $columnName,
            'to_table' => $refTable,
            'to_column' => 'id',
        ];
    }

    private function extractBlueprintDefault(string $statement): ?string
    {
        if (preg_match('/->default\(\s*(?<value>[^)]*)\)/', $statement, $match)) {
            return trim($match['value'], "'\" ");
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function splitArgs(string $args): array
    {
        if (trim($args) === '') {
            return [];
        }

        return array_map('trim', explode(',', $args));
    }

    private function blueprintType(string $method): string
    {
        return match ($method) {
            'id', 'bigIncrements' => 'bigInteger',
            'increments' => 'integer',
            'unsignedBigInteger', 'foreignId', 'foreignIdFor' => 'bigInteger',
            'unsignedInteger' => 'integer',
            'dateTime', 'dateTimeTz' => 'datetime',
            'longText' => 'longtext',
            'mediumText' => 'mediumtext',
            default => strtolower($method),
        };
    }
}
