<?php

namespace CheapSqlScheme\Services;

use Illuminate\Support\Str;

/**
 * Миграции Laravel только с изменениями (см. SchemaDiff): по файлу на таблицу,
 * каждый со своим down(), в порядке, в котором их можно выполнить.
 */
class MigrationGenerator
{
    /** Типы, которые нельзя «изменить» через ->change() — это первичные ключи. */
    private const UNCHANGEABLE = ['id', 'bigincrements', 'increments'];

    /** «Колонка без индексов» — чтобы снять все индексы удаляемой колонки. */
    private const NO_INDEXES = ['is_unique' => false, 'is_index' => false];

    /** @var array<string, true> имена таблиц, которые уже есть в базе — их не трогаем */
    private array $existing = [];

    public function __construct(private SqlSchemaExporter $exporter) {}

    /**
     * Миграции по файлу на таблицу, в порядке, в котором их можно выполнить:
     *
     *   create_*  — новые таблицы, по зависимостям (на кого ссылаются — раньше),
     *               их внешние ключи прямо внутри;
     *   add_* / update_* — изменённые таблицы со своими колонками, индексами и
     *               ключами; файл, ставящий ключ на новую или переименованную
     *               таблицу, идёт после неё;
     *   add_foreign_keys — ключи, замкнутые в круг (A → B → A), отдельно;
     *   drop_*    — удаления таблиц, в конце: ссылки на них к этому моменту
     *               уже сняты. Сначала та, что ссылается, потом та, на которую.
     *
     * У каждого файла свой down(); откат идёт в обратном порядке.
     *
     * @return array<int, array{name: string, content: string}>
     */
    public function files(SchemaDiff $diff): array
    {
        $this->remember($diff);
        $nodes = $this->nodes($diff);

        [$ordered, $deferred] = $this->order($diff, $nodes);

        $files = [];
        foreach ($ordered as $node) {
            $files[] = $node['kind'] === 'create'
                ? $this->createFile($diff, $node)
                : $this->alterFile($diff, $node);
        }
        if ($deferred !== []) {
            $files[] = $this->deferredFile($diff, $deferred);
        }
        foreach ($this->orderDrops($diff) as $table) {
            $files[] = $this->dropFile($diff, $table);
        }

        return $this->uniqueNames($files);
    }

    /**
     * Узлы — будущие файлы create/alter. Внешние ключи раскладываются по
     * таблице, которой принадлежит колонка-ссылка.
     *
     * @return array<string, array<string, mixed>> uid таблицы → узел
     */
    private function nodes(SchemaDiff $diff): array
    {
        $nodes = [];
        foreach ($diff->addedTables as $table) {
            $nodes[$table['uid']] = ['kind' => 'create', 'uid' => $table['uid'], 'table' => $table, 'fk_add' => [], 'fk_drop' => []];
        }
        foreach ($diff->alteredTables as $t) {
            $nodes[$t['current']['uid']] = ['kind' => 'alter', 'uid' => $t['current']['uid'], 'alter' => $t, 'fk_add' => [], 'fk_drop' => []];
        }

        $removedUids = array_flip(array_column($diff->removedTables, 'uid'));
        $ensureAlter = function (string $uid) use (&$nodes, $diff) {
            $nodes[$uid] ??= [
                'kind' => 'alter',
                'uid' => $uid,
                // Таблица не менялась — только её ключи.
                'alter' => [
                    'base' => $diff->baseTables[$uid], 'current' => $diff->currentTables[$uid], 'renamed' => false,
                    'added' => [], 'removed' => [], 'renamed_columns' => [], 'changed' => [],
                ],
                'fk_add' => [],
                'fk_drop' => [],
            ];
        };

        foreach ([...$diff->removedRelations, ...array_column($diff->changedRelations, 'base')] as $relation) {
            $from = $diff->columnAt($relation['from'], true);
            // У удаляемой таблицы ключи уходят вместе с ней.
            if (! $from || isset($removedUids[$from['table']['uid']])) {
                continue;
            }
            $ensureAlter($from['table']['uid']);
            $nodes[$from['table']['uid']]['fk_drop'][] = $relation;
        }
        foreach ([...$diff->addedRelations, ...array_column($diff->changedRelations, 'current')] as $relation) {
            $from = $diff->columnAt($relation['from'], false);
            if (! $from) {
                continue;
            }
            if (! isset($nodes[$from['table']['uid']])) {
                $ensureAlter($from['table']['uid']);
            }
            $nodes[$from['table']['uid']]['fk_add'][] = $relation;
        }

        return $nodes;
    }

    /**
     * Порядок файлов: топологическая сортировка по зависимостям ключей.
     * Если узлы ждут друг друга по кругу, их ключи на такие же узлы уходят в
     * отдельный файл add_foreign_keys.
     *
     * @return array{0: array<int, array>, 1: array<int, array>} [узлы по порядку, отложенные ключи]
     */
    private function order(SchemaDiff $diff, array $nodes): array
    {
        $alterRenamed = [];
        $removedColumns = [];
        foreach ($nodes as $uid => $node) {
            if ($node['kind'] === 'alter') {
                if ($node['alter']['renamed']) {
                    $alterRenamed[$uid] = true;
                }
                foreach ($node['alter']['removed'] as $column) {
                    $removedColumns[$column['uid']] = $uid;
                }
            }
        }

        // Ключ на таблицу $target требует, чтобы её файл был раньше, если она
        // новая или переименовывается.
        $needs = function (array $node) use ($diff, $nodes, $alterRenamed): array {
            $deps = [];
            foreach ($node['fk_add'] as $relation) {
                $to = $diff->columnAt($relation['to'], false);
                $target = $to['table']['uid'] ?? null;
                if ($target && $target !== $node['uid'] && isset($nodes[$target])
                    && ($nodes[$target]['kind'] === 'create' || isset($alterRenamed[$target]))) {
                    $deps[$target] = 'fk';
                }
            }

            return $deps;
        };

        $deps = [];
        foreach ($nodes as $uid => $node) {
            $deps[$uid] = $needs($node);
        }
        // Снять ключ на колонку, которую удаляет другой файл, — до этого файла.
        foreach ($nodes as $uid => $node) {
            foreach ($node['fk_drop'] as $relation) {
                $owner = $removedColumns[$relation['to']] ?? null;
                if ($owner && $owner !== $uid) {
                    $deps[$owner][$uid] = 'drop';
                }
            }
        }

        $ordered = [];
        $deferred = [];
        $left = $nodes;
        while ($left !== []) {
            $ready = null;
            foreach ($left as $uid => $node) {
                if (array_diff_key($deps[$uid], $ordered) === []) {
                    $ready = $uid;
                    break;
                }
            }

            if ($ready === null) {
                // Круг: у узлов, ждущих друг друга, ключи на оставшихся — откладываем.
                foreach ($left as $uid => $node) {
                    $keep = [];
                    foreach ($node['fk_add'] as $relation) {
                        $target = $diff->columnAt($relation['to'], false)['table']['uid'] ?? null;
                        if ($target !== $uid && isset($left[$target])) {
                            $deferred[] = $relation;
                        } else {
                            $keep[] = $relation;
                        }
                    }
                    $left[$uid]['fk_add'] = $keep;
                    $deps[$uid] = array_filter($deps[$uid], fn ($why, $dep) => $why !== 'fk' || ! isset($left[$dep]), ARRAY_FILTER_USE_BOTH);
                }
                // Если и без ключей ждут друг друга — просто по порядку.
                $ready = array_key_first(array_filter($left, fn ($n, $uid) => array_diff_key($deps[$uid], $ordered) === [], ARRAY_FILTER_USE_BOTH))
                    ?? array_key_first($left);
            }

            $ordered[$ready] = $left[$ready];
            unset($left[$ready]);
        }

        return [array_values($ordered), $deferred];
    }

    /**
     * Удаляемые таблицы: сначала те, что ссылаются на другие удаляемые.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderDrops(SchemaDiff $diff): array
    {
        $removed = collect($diff->removedTables)->keyBy('uid');
        $referencedBy = [];
        foreach ($diff->removedRelations as $relation) {
            $from = $diff->columnAt($relation['from'], true)['table']['uid'] ?? null;
            $to = $diff->columnAt($relation['to'], true)['table']['uid'] ?? null;
            if ($from && $to && $from !== $to && isset($removed[$from], $removed[$to])) {
                $referencedBy[$to][$from] = true;
            }
        }

        $ordered = [];
        $left = $removed->all();
        while ($left !== []) {
            $next = array_key_first(array_filter(
                $left,
                fn ($t, $uid) => array_diff_key($referencedBy[$uid] ?? [], $ordered) === [],
                ARRAY_FILTER_USE_BOTH,
            )) ?? array_key_first($left);
            $ordered[$next] = $left[$next];
            unset($left[$next]);
        }

        return array_values($ordered);
    }

    /** @return array{name: string, content: string} */
    private function createFile(SchemaDiff $diff, array $node): array
    {
        $name = $this->name($node['table']['name']);
        $foreign = [];
        foreach ($node['fk_add'] as $relation) {
            if ($line = $this->foreignLine($diff, $relation, false)) {
                $foreign[] = $line[1];
            }
        }

        return [
            'name' => "create_{$name}_table",
            'content' => $this->exporter->migrationFile(
                $this->exporter->tableBlock(Snapshot::tableModel($node['table']), $foreign, $name),
                "        Schema::dropIfExists('{$name}');",
            ),
        ];
    }

    /**
     * Изменения одной таблицы. Порядок внутри подобран так, чтобы имена ключей
     * и индексов были верными в момент обращения к ним, а освобождённое имя
     * колонки можно было тут же занять.
     *
     * @return array{name: string, content: string}
     */
    private function alterFile(SchemaDiff $diff, array $node): array
    {
        $t = $node['alter'];
        $old = $this->name($t['base']['name']);
        $new = $this->name($t['current']['name']);
        $list = fn (array $columns) => implode(', ', array_map(fn ($c) => "'{$c['name']}'", $columns));

        // up
        $pre = []; // по старому имени таблицы: снять ключи и индексы
        foreach ($node['fk_drop'] as $relation) {
            if ($line = $this->dropForeignLine($diff, $relation, true)) {
                $pre[] = $line[1];
            }
        }
        foreach ($t['changed'] as $ch) {
            $pre = [...$pre, ...$this->indexDrops($ch['base'], $ch['current'])];
        }
        foreach ($t['removed'] as $column) {
            $pre = [...$pre, ...$this->indexDrops($column, self::NO_INDEXES)];
        }

        $up = $this->tableBlocks([$old => $pre]);
        if ($t['renamed']) {
            $up[] = "        Schema::rename('{$old}', '{$new}');";
        }
        if ($t['removed'] !== []) {
            $up = [...$up, ...$this->tableBlocks([$new => ['$table->dropColumn(['.$list($t['removed']).'])']])];
        }
        $up = [...$up, ...$this->tableBlocks([$new => array_map(
            fn ($r) => "\$table->renameColumn('{$r['base']['name']}', '{$r['current']['name']}')",
            $t['renamed_columns'],
        )])];
        $main = [];
        foreach ($t['added'] as $column) {
            $main[] = $this->exporter->columnLine(Snapshot::columnModel($column));
        }
        foreach ($t['changed'] as $ch) {
            if ($line = $this->changeLine($ch['base'], $ch['current'])) {
                $main[] = $line;
            }
        }
        foreach ($t['changed'] as $ch) {
            $main = [...$main, ...$this->indexAdds($ch['base'], $ch['current'])];
        }
        foreach ($node['fk_add'] as $relation) {
            if ($line = $this->foreignLine($diff, $relation, false)) {
                $main[] = $line[1];
            }
        }
        $up = [...$up, ...$this->tableBlocks([$new => $main])];

        // down — то же в обратную сторону
        $undo = [];
        foreach ($node['fk_add'] as $relation) {
            if ($line = $this->dropForeignLine($diff, $relation, false)) {
                $undo[] = $line[1];
            }
        }
        foreach ($t['changed'] as $ch) {
            $undo = [...$undo, ...$this->indexDrops($ch['current'], $ch['base'])];
        }
        foreach ($t['added'] as $column) {
            $undo = [...$undo, ...$this->indexDrops($column, self::NO_INDEXES)];
        }
        $down = $this->tableBlocks([$new => $undo]);
        if ($t['added'] !== []) {
            $down = [...$down, ...$this->tableBlocks([$new => ['$table->dropColumn(['.$list($t['added']).'])']])];
        }
        $down = [...$down, ...$this->tableBlocks([$new => array_map(
            fn ($r) => "\$table->renameColumn('{$r['current']['name']}', '{$r['base']['name']}')",
            $t['renamed_columns'],
        )])];
        $restore = [];
        foreach ($t['removed'] as $column) {
            $restore[] = $this->exporter->columnLine(Snapshot::columnModel($column));
        }
        foreach ($t['changed'] as $ch) {
            if ($line = $this->changeLine($ch['current'], $ch['base'])) {
                $restore[] = $line;
            }
        }
        $down = [...$down, ...$this->tableBlocks([$new => $restore])];
        if ($t['renamed']) {
            $down[] = "        Schema::rename('{$new}', '{$old}');";
        }
        $post = [];
        foreach ($t['changed'] as $ch) {
            $post = [...$post, ...$this->indexAdds($ch['current'], $ch['base'])];
        }
        foreach ($node['fk_drop'] as $relation) {
            if ($line = $this->foreignLine($diff, $relation, true)) {
                $post[] = $line[1];
            }
        }
        $down = [...$down, ...$this->tableBlocks([$old => $post])];

        return [
            'name' => $this->alterName($t, $node),
            'content' => $this->exporter->migrationFile($this->join($up), $this->join($down)),
        ];
    }

    /** @return array{name: string, content: string} */
    private function dropFile(SchemaDiff $diff, array $table): array
    {
        $name = $this->name($table['name']);
        // Вернуть таблицу вместе с её собственными ключами.
        $foreign = [];
        foreach ($diff->removedRelations as $relation) {
            $from = $diff->columnAt($relation['from'], true);
            if (($from['table']['uid'] ?? null) === $table['uid'] && ($line = $this->foreignLine($diff, $relation, true))) {
                $foreign[] = $line[1];
            }
        }

        return [
            'name' => "drop_{$name}_table",
            'content' => $this->exporter->migrationFile(
                "        Schema::dropIfExists('{$name}');",
                $this->exporter->tableBlock(Snapshot::tableModel($table), $foreign, $name),
            ),
        ];
    }

    /** @return array{name: string, content: string} */
    private function deferredFile(SchemaDiff $diff, array $relations): array
    {
        $adds = [];
        $drops = [];
        foreach ($relations as $relation) {
            if ($line = $this->foreignLine($diff, $relation, false)) {
                $adds[$line[0]][] = $line[1];
            }
            if ($line = $this->dropForeignLine($diff, $relation, false)) {
                $drops[$line[0]][] = $line[1];
            }
        }
        $tables = array_keys($adds);

        return [
            'name' => count($tables) === 1 ? "add_foreign_keys_to_{$tables[0]}_table" : 'add_foreign_keys_to_'.implode('_and_', array_slice($tables, 0, 2)).'_tables',
            'content' => $this->exporter->migrationFile(
                $this->join($this->tableBlocks($adds)),
                $this->join($this->tableBlocks($drops)),
            ),
        ];
    }

    /** add_phone_to_users_table, drop_body_from_posts_table, rename_authors_to_writers_table… */
    private function alterName(array $t, array $node): string
    {
        $table = $this->name($t['current']['name']);
        $columns = fn (array $list) => implode('_and_', array_map(fn ($c) => $this->name($c['name']), $list));
        $columnsOnly = ! $t['renamed'] && ! $t['changed'] && ! $t['renamed_columns'] && $node['fk_drop'] === [];

        if ($columnsOnly && $t['added'] !== [] && $t['removed'] === [] && count($t['added']) <= 2) {
            return "add_{$columns($t['added'])}_to_{$table}_table";
        }
        if ($columnsOnly && $t['removed'] !== [] && $t['added'] === [] && $node['fk_add'] === [] && count($t['removed']) <= 2) {
            return "drop_{$columns($t['removed'])}_from_{$table}_table";
        }
        $onlyTableRename = $t['renamed'] && ! $t['added'] && ! $t['removed'] && ! $t['changed'] && ! $t['renamed_columns']
            && $node['fk_add'] === [] && $node['fk_drop'] === [];
        if ($onlyTableRename) {
            return 'rename_'.$this->name($t['base']['name'])."_to_{$table}_table";
        }

        return "update_{$table}_table";
    }

    /**
     * Одинаковые имена в одном коммите (две «update_users_table» быть не
     * может, но add_foreign_keys — вдруг) — с суффиксом.
     *
     * @param  array<int, array{name: string, content: string}>  $files
     * @return array<int, array{name: string, content: string}>
     */
    private function uniqueNames(array $files): array
    {
        $seen = [];
        foreach ($files as &$file) {
            $base = $file['name'];
            $n = $seen[$base] = ($seen[$base] ?? 0) + 1;
            if ($n > 1) {
                $file['name'] = "{$base}_{$n}";
            }
        }
        unset($file);

        return $files;
    }

    /** ->change() с полным описанием колонки; null — менять нечего (только индексы) или нельзя. */
    private function changeLine(array $from, array $to): ?string
    {
        $fields = array_diff(Snapshot::changedFields($from, $to), ['is_unique', 'is_index', 'is_primary']);
        if ($fields === [] || in_array(strtolower($to['type']), self::UNCHANGEABLE, true)) {
            return null;
        }

        return $this->exporter->columnLine(Snapshot::columnModel($to), false).'->change()';
    }

    /** Индексы, которые были у $from и пропали у $to. Имена — как у колонки в $from. */
    private function indexDrops(array $from, array $to): array
    {
        $lines = [];
        if ($from['is_unique'] && ! $to['is_unique']) {
            $lines[] = "\$table->dropUnique(['{$from['name']}'])";
        }
        if ($from['is_index'] && ! $to['is_index']) {
            $lines[] = "\$table->dropIndex(['{$from['name']}'])";
        }

        return $lines;
    }

    /** Индексы, которых не было у $from и появились у $to. */
    private function indexAdds(array $from, array $to): array
    {
        $lines = [];
        if (! $from['is_unique'] && $to['is_unique']) {
            $lines[] = "\$table->unique('{$to['name']}')";
        }
        if (! $from['is_index'] && $to['is_index'] && ! $to['is_unique']) {
            $lines[] = "\$table->index('{$to['name']}')";
        }

        return $lines;
    }

    /** @return array{0: string, 1: string}|null [таблица, строка] */
    private function foreignLine(SchemaDiff $diff, array $relation, bool $base): ?array
    {
        $from = $diff->columnAt($relation['from'], $base);
        $to = $diff->columnAt($relation['to'], $base);
        if (! $from || ! $to) {
            return null;
        }

        $line = "\$table->foreign('{$from['column']['name']}')"
            ."->references('{$to['column']['name']}')->on('{$this->name($to['table']['name'])}')"
            .$this->exporter->referentialAction((string) ($relation['on_delete'] ?? ''), 'OnDelete')
            .$this->exporter->referentialAction((string) ($relation['on_update'] ?? ''), 'OnUpdate');

        return [$this->name($from['table']['name']), $line];
    }

    /** Снять внешний ключ: по настоящему имени из базы, если оно известно. */
    private function dropForeignLine(SchemaDiff $diff, array $relation, bool $base): ?array
    {
        $from = $diff->columnAt($relation['from'], $base);
        if (! $from) {
            return null;
        }
        $name = $relation['name'] ?? null;
        $line = $name ? "\$table->dropForeign('{$name}')" : "\$table->dropForeign(['{$from['column']['name']}'])";

        return [$this->name($from['table']['name']), $line];
    }

    /**
     * @param  array<string, array<int, string>>  $linesByTable
     * @return array<int, string>
     */
    private function tableBlocks(array $linesByTable): array
    {
        $blocks = [];
        foreach ($linesByTable as $table => $lines) {
            if ($lines === []) {
                continue;
            }
            $body = implode("\n", array_map(fn ($l) => '            '.$l.';', $lines));
            $blocks[] = "        Schema::table('{$table}', function (Blueprint \$table) {\n{$body}\n        });";
        }

        return $blocks;
    }

    /** @param  array<int, string>  $blocks */
    private function join(array $blocks): string
    {
        return $blocks === [] ? '        //' : implode("\n\n", $blocks);
    }

    private function remember(SchemaDiff $diff): void
    {
        $this->existing = [];
        foreach ($diff->baseTables as $table) {
            $this->existing[$table['name']] = true;
        }
    }

    /**
     * Имя таблицы в коде миграции. Таблицы из базы — как есть (там может быть
     * и userPosts); новые имена — в snake_case, как при экспорте.
     */
    private function name(string $name): string
    {
        return isset($this->existing[$name]) ? $name : Str::snake($name);
    }
}
