<?php

namespace CheapSqlScheme\Services;

use Illuminate\Support\Str;

/**
 * Миграция Laravel только с изменениями (см. SchemaDiff) — с up() и
 * обратным ему down(). Порядок шагов подобран так, чтобы имена ключей и
 * индексов были верными в момент обращения к ним:
 *
 *   up:   снять старые внешние ключи и индексы → переименовать таблицы →
 *         создать новые → переименовать, добавить, изменить, удалить колонки
 *         и поставить индексы → удалить таблицы → поставить внешние ключи;
 *   down: всё то же в обратную сторону.
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

    public function generate(SchemaDiff $diff): string
    {
        $this->remember($diff);

        return $this->exporter->migrationFile(
            $this->join($this->up($diff)),
            $this->join($this->down($diff)),
        );
    }

    /** Имя миграции по сути изменений: create_posts_table, add_phone_to_users_table… */
    public function suggestName(SchemaDiff $diff): string
    {
        $this->remember($diff);
        $tables = count($diff->addedTables) + count($diff->removedTables) + count($diff->alteredTables);
        $relationOnly = $tables === 0;

        if (! $relationOnly && $tables === 1) {
            if ($diff->addedTables) {
                return 'create_'.$this->name($diff->addedTables[0]['name']).'_table';
            }
            if ($diff->removedTables) {
                return 'drop_'.$this->name($diff->removedTables[0]['name']).'_table';
            }
            $t = $diff->alteredTables[0];
            $name = $this->name($t['current']['name']);
            $onlyAdded = $t['added'] !== [] && ! $t['removed'] && ! $t['changed'] && ! $t['renamed_columns'] && ! $t['renamed'];
            if ($onlyAdded && count($t['added']) <= 2) {
                return 'add_'.implode('_and_', array_map(fn ($c) => $this->name($c['name']), $t['added'])).'_to_'.$name.'_table';
            }
            $onlyRemoved = $t['removed'] !== [] && ! $t['added'] && ! $t['changed'] && ! $t['renamed_columns'] && ! $t['renamed'];
            if ($onlyRemoved && count($t['removed']) <= 2) {
                return 'drop_'.implode('_and_', array_map(fn ($c) => $this->name($c['name']), $t['removed'])).'_from_'.$name.'_table';
            }

            return 'update_'.$name.'_table';
        }

        return $relationOnly ? 'update_foreign_keys' : 'update_database_schema';
    }

    /** @return array<int, string> блоки кода up() */
    private function up(SchemaDiff $diff): array
    {
        $blocks = [];

        // 1. Снять внешние ключи и индексы — пока у таблиц и колонок старые имена.
        $drops = [];
        foreach ([...$diff->removedRelations, ...array_column($diff->changedRelations, 'base')] as $relation) {
            if ($line = $this->dropForeignLine($diff, $relation, true)) {
                $drops[$line[0]][] = $line[1];
            }
        }
        foreach ($diff->alteredTables as $t) {
            foreach ($t['changed'] as $ch) {
                foreach ($this->indexDrops($ch['base'], $ch['current']) as $line) {
                    $drops[$this->name($t['base']['name'])][] = $line;
                }
            }
            // Индексы удаляемых колонок снимаем явно: SQLite иначе не даст удалить колонку.
            foreach ($t['removed'] as $column) {
                foreach ($this->indexDrops($column, self::NO_INDEXES) as $line) {
                    $drops[$this->name($t['base']['name'])][] = $line;
                }
            }
        }
        $blocks = [...$blocks, ...$this->tableBlocks($drops)];

        // 2. Переименовать таблицы.
        foreach ($diff->alteredTables as $t) {
            if ($t['renamed']) {
                $blocks[] = "        Schema::rename('{$this->name($t['base']['name'])}', '{$this->name($t['current']['name'])}');";
            }
        }

        // 3. Новые таблицы — без внешних ключей, их ставим в конце.
        foreach ($diff->addedTables as $table) {
            $blocks[] = $this->exporter->tableBlock(Snapshot::tableModel($table), [], $this->name($table['name']));
        }

        // 4. Колонки изменённых таблиц. Сначала удалить — это освобождает имена:
        //    колонку можно переименовать в имя удалённой или добавить новую под ним.
        foreach ($diff->alteredTables as $t) {
            $name = $this->name($t['current']['name']);

            if ($t['removed'] !== []) {
                $blocks = [...$blocks, ...$this->tableBlocks([$name => [
                    '$table->dropColumn(['.implode(', ', array_map(fn ($c) => "'{$c['name']}'", $t['removed'])).'])',
                ]])];
            }

            $renames = array_map(
                fn ($r) => "\$table->renameColumn('{$r['base']['name']}', '{$r['current']['name']}')",
                $t['renamed_columns'],
            );
            $blocks = [...$blocks, ...$this->tableBlocks([$name => $renames])];

            $lines = [];
            foreach ($t['added'] as $column) {
                $lines[] = $this->exporter->columnLine(Snapshot::columnModel($column));
            }
            foreach ($t['changed'] as $ch) {
                if ($line = $this->changeLine($ch['base'], $ch['current'])) {
                    $lines[] = $line;
                }
            }
            foreach ($t['changed'] as $ch) {
                $lines = [...$lines, ...$this->indexAdds($ch['base'], $ch['current'])];
            }
            $blocks = [...$blocks, ...$this->tableBlocks([$name => $lines])];
        }

        // 5. Удалить таблицы.
        foreach ($diff->removedTables as $table) {
            $blocks[] = "        Schema::dropIfExists('{$this->name($table['name'])}');";
        }

        // 6. Новые внешние ключи.
        $adds = [];
        foreach ([...$diff->addedRelations, ...array_column($diff->changedRelations, 'current')] as $relation) {
            if ($line = $this->foreignLine($diff, $relation, false)) {
                $adds[$line[0]][] = $line[1];
            }
        }

        return [...$blocks, ...$this->tableBlocks($adds)];
    }

    /** @return array<int, string> блоки кода down() */
    private function down(SchemaDiff $diff): array
    {
        $blocks = [];

        // 1. Снять внешние ключи, поставленные в up().
        $drops = [];
        foreach ([...$diff->addedRelations, ...array_column($diff->changedRelations, 'current')] as $relation) {
            if ($line = $this->dropForeignLine($diff, $relation, false)) {
                $drops[$line[0]][] = $line[1];
            }
        }
        $blocks = [...$blocks, ...$this->tableBlocks($drops)];

        // 2. Вернуть удалённые таблицы.
        foreach ($diff->removedTables as $table) {
            $blocks[] = $this->exporter->tableBlock(Snapshot::tableModel($table), [], $this->name($table['name']));
        }

        // 3. Колонки изменённых таблиц — пока у таблицы новое имя.
        foreach ($diff->alteredTables as $t) {
            $name = $this->name($t['current']['name']);

            $indexDrops = [];
            foreach ($t['changed'] as $ch) {
                $indexDrops = [...$indexDrops, ...$this->indexDrops($ch['current'], $ch['base'])];
            }
            foreach ($t['added'] as $column) {
                $indexDrops = [...$indexDrops, ...$this->indexDrops($column, self::NO_INDEXES)];
            }
            $blocks = [...$blocks, ...$this->tableBlocks([$name => $indexDrops])];

            // Добавленные колонки удаляем до обратного переименования: их имя
            // могло достаться переименованной колонке.
            if ($t['added'] !== []) {
                $blocks = [...$blocks, ...$this->tableBlocks([$name => [
                    '$table->dropColumn(['.implode(', ', array_map(fn ($c) => "'{$c['name']}'", $t['added'])).'])',
                ]])];
            }

            $renames = array_map(
                fn ($r) => "\$table->renameColumn('{$r['current']['name']}', '{$r['base']['name']}')",
                $t['renamed_columns'],
            );
            $blocks = [...$blocks, ...$this->tableBlocks([$name => $renames])];

            $lines = [];
            foreach ($t['removed'] as $column) {
                $lines[] = $this->exporter->columnLine(Snapshot::columnModel($column));
            }
            foreach ($t['changed'] as $ch) {
                if ($line = $this->changeLine($ch['current'], $ch['base'])) {
                    $lines[] = $line;
                }
            }
            $blocks = [...$blocks, ...$this->tableBlocks([$name => $lines])];
        }

        // 4. Удалить созданные таблицы.
        foreach (array_reverse($diff->addedTables) as $table) {
            $blocks[] = "        Schema::dropIfExists('{$this->name($table['name'])}');";
        }

        // 5. Вернуть имена таблиц.
        foreach ($diff->alteredTables as $t) {
            if ($t['renamed']) {
                $blocks[] = "        Schema::rename('{$this->name($t['current']['name'])}', '{$this->name($t['base']['name'])}');";
            }
        }

        // 6. Вернуть индексы и внешние ключи, снятые в up().
        $adds = [];
        foreach ($diff->alteredTables as $t) {
            foreach ($t['changed'] as $ch) {
                foreach ($this->indexAdds($ch['current'], $ch['base']) as $line) {
                    $adds[$this->name($t['base']['name'])][] = $line;
                }
            }
        }
        foreach ([...$diff->removedRelations, ...array_column($diff->changedRelations, 'base')] as $relation) {
            if ($line = $this->foreignLine($diff, $relation, true)) {
                $adds[$line[0]][] = $line[1];
            }
        }

        return [...$blocks, ...$this->tableBlocks($adds)];
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
