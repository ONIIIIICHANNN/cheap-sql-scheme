<?php

namespace CheapSqlScheme\Services;

/**
 * Разница двух снимков схемы (см. Snapshot): что добавили, убрали,
 * переименовали и изменили. Сопоставление — по uid, поэтому
 * переименование колонки остаётся переименованием.
 */
class SchemaDiff
{
    /** @var array<string, array<string, mixed>> uid → таблица */
    public array $baseTables = [];

    /** @var array<string, array<string, mixed>> */
    public array $currentTables = [];

    /** @var array<int, array<string, mixed>> */
    public array $addedTables = [];

    /** @var array<int, array<string, mixed>> */
    public array $removedTables = [];

    /**
     * Таблицы, что есть и там и там, но изменились.
     *
     * @var array<int, array{base: array, current: array, renamed: bool, added: array, removed: array, renamed_columns: array, changed: array}>
     */
    public array $alteredTables = [];

    /** @var array<int, array<string, mixed>> внешние ключи текущей схемы, которых не было */
    public array $addedRelations = [];

    /** @var array<int, array<string, mixed>> внешние ключи базы, которых больше нет */
    public array $removedRelations = [];

    /** @var array<int, array{base: array, current: array}> тот же столбец, но другая цель или действия */
    public array $changedRelations = [];

    /** @var array<string, array{table: array, column: array}> uid колонки → где она в базовом снимке */
    private array $baseColumns = [];

    /** @var array<string, array{table: array, column: array}> */
    private array $currentColumns = [];

    /** Драйвер базы: если задан, колонки сравниваются «по смыслу» (см. Snapshot::canonical). */
    private ?string $driver = null;

    /**
     * @param  string|null  $driver  для сверки с живой базой — сравнивать колонки
     *                               так, как их хранит эта СУБД
     */
    public static function compare(?array $base, array $current, ?string $driver = null): self
    {
        $diff = new self;
        $diff->driver = $driver;
        $base ??= ['tables' => [], 'relations' => []];

        foreach ($base['tables'] ?? [] as $table) {
            $diff->baseTables[$table['uid']] = $table;
            foreach ($table['columns'] as $column) {
                $diff->baseColumns[$column['uid']] = ['table' => $table, 'column' => $column];
            }
        }
        foreach ($current['tables'] ?? [] as $table) {
            $diff->currentTables[$table['uid']] = $table;
            foreach ($table['columns'] as $column) {
                $diff->currentColumns[$column['uid']] = ['table' => $table, 'column' => $column];
            }
        }

        foreach ($diff->currentTables as $uid => $table) {
            if (! isset($diff->baseTables[$uid])) {
                $diff->addedTables[] = $table;
            }
        }
        foreach ($diff->baseTables as $uid => $table) {
            if (! isset($diff->currentTables[$uid])) {
                $diff->removedTables[] = $table;

                continue;
            }
            $altered = $diff->compareTable($table, $diff->currentTables[$uid]);
            if ($altered !== null) {
                $diff->alteredTables[] = $altered;
            }
        }

        $diff->compareRelations($base['relations'] ?? [], $current['relations'] ?? []);

        return $diff;
    }

    public function isEmpty(): bool
    {
        return $this->addedTables === [] && $this->removedTables === [] && $this->alteredTables === []
            && $this->addedRelations === [] && $this->removedRelations === [] && $this->changedRelations === [];
    }

    /** Где колонка: в базовом снимке ($base) или в текущем. */
    public function columnAt(string $uid, bool $base): ?array
    {
        return ($base ? $this->baseColumns : $this->currentColumns)[$uid] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function compareTable(array $base, array $current): ?array
    {
        $baseCols = collect($base['columns'])->keyBy('uid');
        $curCols = collect($current['columns'])->keyBy('uid');

        $added = $curCols->diffKeys($baseCols)->values()->all();
        $removed = $baseCols->diffKeys($curCols)->values()->all();
        $renamed = [];
        $changed = [];
        foreach ($curCols->intersectByKeys($baseCols) as $uid => $col) {
            $old = $baseCols[$uid];
            if ($old['name'] !== $col['name']) {
                $renamed[] = ['base' => $old, 'current' => $col];
            }
            $fields = $this->driver
                ? Snapshot::changedFields(Snapshot::canonical($old, $this->driver), Snapshot::canonical($col, $this->driver))
                : Snapshot::changedFields($old, $col);
            if ($fields !== []) {
                $changed[] = ['base' => $old, 'current' => $col, 'fields' => $fields];
            }
        }

        $tableRenamed = $base['name'] !== $current['name'];
        if (! $tableRenamed && $added === [] && $removed === [] && $renamed === [] && $changed === []) {
            return null;
        }

        return [
            'base' => $base,
            'current' => $current,
            'renamed' => $tableRenamed,
            'added' => $added,
            'removed' => $removed,
            'renamed_columns' => $renamed,
            'changed' => $changed,
        ];
    }

    private function compareRelations(array $base, array $current): void
    {
        // У колонки максимум один внешний ключ — ключ сравнения from.
        $base = collect($base)->keyBy('from');
        $current = collect($current)->keyBy('from');

        foreach ($current as $from => $relation) {
            if (! isset($base[$from])) {
                $this->addedRelations[] = $relation;
            } elseif ($base[$from]['to'] !== $relation['to']
                || Snapshot::action($base[$from]['on_delete'] ?? null) !== Snapshot::action($relation['on_delete'] ?? null)
                || Snapshot::action($base[$from]['on_update'] ?? null) !== Snapshot::action($relation['on_update'] ?? null)) {
                $this->changedRelations[] = ['base' => $base[$from], 'current' => $relation];
            }
        }
        foreach ($base as $from => $relation) {
            if (! isset($current[$from])) {
                $this->removedRelations[] = $relation;
            }
        }
    }

    /**
     * Изменения для окна коммита: по таблицам, с «было → стало».
     *
     * @return array<int, array<string, mixed>>
     */
    public function present(): array
    {
        $rows = [];

        foreach ($this->addedTables as $table) {
            $rows[$table['uid']] = [
                'uid' => $table['uid'],
                'name' => $table['name'],
                'old_name' => null,
                'status' => 'added',
                'items' => array_map(fn ($c) => $this->columnItem('added', null, $c), $table['columns']),
            ];
        }

        foreach ($this->alteredTables as $t) {
            $items = [];
            foreach ($t['added'] as $c) {
                $items[] = $this->columnItem('added', null, $c);
            }
            $renamedUids = [];
            foreach ($t['renamed_columns'] as $r) {
                $renamedUids[$r['current']['uid']] = true;
            }
            $changedUids = [];
            foreach ($t['changed'] as $ch) {
                $changedUids[$ch['current']['uid']] = true;
                $items[] = $this->columnItem('changed', $ch['base'], $ch['current']);
            }
            foreach ($t['renamed_columns'] as $r) {
                if (! isset($changedUids[$r['current']['uid']])) {
                    $items[] = $this->columnItem('renamed', $r['base'], $r['current']);
                }
            }
            foreach ($t['removed'] as $c) {
                $items[] = $this->columnItem('removed', $c, null);
            }

            $rows[$t['current']['uid']] = [
                'uid' => $t['current']['uid'],
                'name' => $t['current']['name'],
                'old_name' => $t['renamed'] ? $t['base']['name'] : null,
                'status' => $items === [] ? 'renamed' : 'changed',
                'items' => $items,
            ];
        }

        foreach ($this->removedTables as $table) {
            $rows[$table['uid']] = [
                'uid' => $table['uid'],
                'name' => $table['name'],
                'old_name' => null,
                'status' => 'removed',
                'items' => array_map(fn ($c) => $this->columnItem('removed', $c, null), $table['columns']),
            ];
        }

        // Внешние ключи — в строку своей таблицы (той, где колонка-ссылка).
        $relationRows = [
            ...array_map(fn ($r) => ['added', null, $r], $this->addedRelations),
            ...array_map(fn ($r) => ['changed', $r['base'], $r['current']], $this->changedRelations),
            ...array_map(fn ($r) => ['removed', $r, null], $this->removedRelations),
        ];
        foreach ($relationRows as [$status, $before, $after]) {
            $from = $this->columnAt(($after ?? $before)['from'], $after === null);
            if (! $from) {
                continue;
            }
            $table = $from['table'];
            $rows[$table['uid']] ??= [
                'uid' => $table['uid'],
                'name' => $table['name'],
                'old_name' => null,
                'status' => 'changed',
                'items' => [],
            ];
            if ($rows[$table['uid']]['status'] === 'renamed') {
                $rows[$table['uid']]['status'] = 'changed';
            }
            $rows[$table['uid']]['items'][] = [
                'kind' => 'foreign',
                'status' => $status,
                'name' => $from['column']['name'],
                'old_name' => null,
                'before' => $before ? $this->describeRelation($before, true) : null,
                'after' => $after ? $this->describeRelation($after, false) : null,
            ];
        }

        return array_values($rows);
    }

    /**
     * Колонки, которые добавляются в существующую таблицу как NOT NULL без
     * значения по умолчанию: если в таблице есть строки, миграция упадёт
     * (а SQLite не даст так добавить колонку вовсе).
     *
     * @return array<int, string> «posts.slug»
     */
    public function requiredWithoutDefault(): array
    {
        $columns = [];
        foreach ($this->alteredTables as $t) {
            foreach ($t['added'] as $column) {
                if (! $column['nullable'] && ($column['default_value'] ?? null) === null && ! $column['is_auto_increment']) {
                    $columns[] = $t['current']['name'].'.'.$column['name'];
                }
            }
        }

        return $columns;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $added = count($this->addedTables) + count($this->addedRelations);
        $removed = count($this->removedTables) + count($this->removedRelations);
        $changed = count($this->changedRelations);
        foreach ($this->alteredTables as $t) {
            $added += count($t['added']);
            $removed += count($t['removed']);
            $uids = [];
            foreach ([...$t['changed'], ...$t['renamed_columns']] as $c) {
                $uids[$c['current']['uid']] = true;
            }
            $changed += count($uids) + ($t['renamed'] ? 1 : 0);
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /** @return array<string, mixed> */
    private function columnItem(string $status, ?array $before, ?array $after): array
    {
        return [
            'kind' => 'column',
            'status' => $status,
            'name' => ($after ?? $before)['name'],
            'old_name' => $before && $after && $before['name'] !== $after['name'] ? $before['name'] : null,
            'before' => $before ? self::describe($before) : null,
            'after' => $after ? self::describe($after) : null,
        ];
    }

    /** «string(255) · nullable · unique · default 'x'». */
    public static function describe(array $column): string
    {
        $parts = [$column['type'].($column['length'] ? '('.$column['length'].')' : '')];
        if ($column['is_primary']) {
            $parts[] = 'PK';
        }
        if ($column['is_auto_increment'] && $column['type'] !== 'id') {
            $parts[] = 'auto increment';
        }
        if ($column['nullable']) {
            $parts[] = 'nullable';
        }
        if ($column['is_unique']) {
            $parts[] = 'unique';
        }
        if ($column['is_index']) {
            $parts[] = 'index';
        }
        if ($column['default_value'] !== null && $column['default_value'] !== '') {
            $parts[] = 'default '.$column['default_value'];
        }

        return implode(' · ', $parts);
    }

    private function describeRelation(array $relation, bool $base): string
    {
        $target = $this->columnAt($relation['to'], $base) ?? $this->columnAt($relation['to'], ! $base);
        $text = '→ '.($target ? $target['table']['name'].'.'.$target['column']['name'] : '?');
        if ($action = Snapshot::action($relation['on_delete'] ?? null)) {
            $text .= ' · on delete '.$action;
        }
        if ($action = Snapshot::action($relation['on_update'] ?? null)) {
            $text .= ' · on update '.$action;
        }

        return $text;
    }
}
