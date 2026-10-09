<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Models\SqlTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Схема самой базы приложения: создаётся при первом открытии редактора и
 * обновляется из базы по кнопке. После синхронизации базовый снимок равен
 * базе — дальше всё, что поменяли на холсте, и есть изменения для миграции.
 */
class ProjectSchema
{
    public function __construct(private DatabaseIntrospector $introspector) {}

    /** Создать схему проекта, если её ещё нет. Ошибка чтения базы список не ломает. */
    public function ensure(): ?SqlSchema
    {
        if (! config('cheap-sql-scheme.project_schema.enabled', true)) {
            return null;
        }

        $existing = SqlSchema::where('source', SqlSchema::SOURCE_DATABASE)->first();
        if ($existing) {
            return $existing;
        }

        try {
            $tables = $this->introspector->read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return DB::transaction(function () use ($tables) {
            $schema = SqlSchema::create([
                'name' => config('cheap-sql-scheme.project_schema.name') ?? config('app.name', 'Project').' database',
                'source' => SqlSchema::SOURCE_DATABASE,
                'dialect' => 'laravel',
                'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
            ]);
            $this->apply($schema, $tables);

            return $schema;
        });
    }

    /** Подтянуть структуру из базы: холст и базовый снимок становятся как в базе. */
    public function sync(SqlSchema $schema): SqlSchema
    {
        $tables = $this->introspector->read();

        DB::transaction(fn () => $this->apply($schema, $tables));

        return $schema->fresh(['tables.columns', 'relations']);
    }

    /**
     * Что в базе не так, как в последнем коммите: миграции, выполненные в
     * обход редактора, правки руками. Таблицы и колонки базы сопоставляются со
     * снимком по имени и получают его uid — дальше обычный SchemaDiff.
     */
    public function drift(SqlSchema $schema): SchemaDiff
    {
        $baseline = $schema->baseline ?? ['tables' => [], 'relations' => []];

        $driver = DB::connection(config('cheap-sql-scheme.project_schema.connection'))->getDriverName();

        return SchemaDiff::compare($baseline, $this->snapshotOf($this->introspector->read(), $baseline), $driver);
    }

    /** Структура базы в формате снимка, с uid из $baseline там, где имена совпали. */
    private function snapshotOf(array $tables, array $baseline): array
    {
        $baseTables = collect($baseline['tables'] ?? [])->keyBy(fn ($t) => strtolower($t['name']));
        $baseRelations = collect($baseline['relations'] ?? [])->keyBy(fn ($r) => $r['from'].'>'.$r['to']);

        $snapshot = ['tables' => [], 'relations' => []];
        $uidOf = [];
        foreach ($tables as $data) {
            $base = $baseTables->get(strtolower($data['name']));
            $baseColumns = collect($base['columns'] ?? [])->keyBy(fn ($c) => strtolower($c['name']));
            $columns = [];
            foreach ($data['columns'] as $col) {
                $uid = $baseColumns->get(strtolower($col['name']))['uid'] ?? 'db:'.$data['name'].'.'.$col['name'];
                $uidOf[strtolower($data['name'].'.'.$col['name'])] = $uid;
                $columns[] = ['uid' => $uid] + collect($col)->only(array_merge(['name'], Snapshot::COLUMN_FIELDS))->all();
            }
            $snapshot['tables'][] = [
                'uid' => $base['uid'] ?? 'db:'.$data['name'],
                'name' => $data['name'],
                'columns' => $columns,
            ];
        }

        foreach ($tables as $data) {
            foreach ($data['foreign'] as $fk) {
                $from = $uidOf[strtolower($data['name'].'.'.$fk['column'])] ?? null;
                $to = $uidOf[strtolower($fk['table'].'.'.$fk['references'])] ?? null;
                if ($from && $to) {
                    $snapshot['relations'][] = [
                        'from' => $from,
                        'to' => $to,
                        'on_delete' => $fk['on_delete'],
                        'on_update' => $fk['on_update'],
                        'name' => $baseRelations->get($from.'>'.$to)['name'] ?? $fk['name'],
                    ];
                }
            }
        }

        return $snapshot;
    }

    /**
     * Разложить структуру базы в схему. Таблицы и колонки сопоставляются по
     * имени: у знакомых сохраняются uid и место на холсте.
     */
    private function apply(SqlSchema $schema, array $tables): void
    {
        $schema->load('tables.columns');
        $existing = $schema->tables->keyBy(fn (SqlTable $t) => strtolower($t->name));
        $nextSlot = $schema->tables->count();

        $keptTables = [];
        $columnId = [];
        foreach ($tables as $data) {
            $table = $existing->get(strtolower($data['name']));
            if (! $table) {
                [$x, $y] = $this->gridPosition($nextSlot++);
                $table = $schema->tables()->create([
                    'uid' => (string) Str::uuid(),
                    'name' => $data['name'],
                    'pos_x' => $x,
                    'pos_y' => $y,
                ]);
                $table->setRelation('columns', collect());
            } elseif ($table->name !== $data['name']) {
                $table->update(['name' => $data['name']]);
            }
            $keptTables[] = $table->id;

            $columns = $table->columns->keyBy(fn ($c) => strtolower($c->name));
            $keptColumns = [];
            foreach (array_values($data['columns']) as $position => $col) {
                $attributes = ['position' => $position] + collect($col)->only(array_merge(['name'], Snapshot::COLUMN_FIELDS))->all();
                $column = $columns->get(strtolower($col['name']));
                if ($column) {
                    $column->update($attributes);
                } else {
                    $column = $table->columns()->create(['uid' => (string) Str::uuid()] + $attributes);
                }
                $keptColumns[] = $column->id;
                $columnId[strtolower($data['name']).'.'.strtolower($col['name'])] = $column->id;
            }
            $table->columns()->whereNotIn('id', $keptColumns ?: [0])->delete();
        }
        $schema->tables()->whereNotIn('id', $keptTables ?: [0])->delete();

        $schema->relations()->delete();
        $fkNames = [];
        foreach ($tables as $data) {
            foreach ($data['foreign'] as $fk) {
                $from = $columnId[strtolower($data['name']).'.'.strtolower($fk['column'])] ?? null;
                $to = $columnId[strtolower($fk['table']).'.'.strtolower($fk['references'])] ?? null;
                if (! $from || ! $to) {
                    continue; // ссылка на исключённую таблицу
                }
                $schema->relations()->create([
                    'from_column_id' => $from,
                    'to_column_id' => $to,
                    'type' => 'one_to_many',
                    'on_delete' => $fk['on_delete'],
                    'on_update' => $fk['on_update'],
                ]);
                $fkNames[$from] = $fk['name'];
            }
        }

        // Базовый снимок = база. Настоящие имена внешних ключей запоминаем:
        // их снимают по имени, а оно не всегда совпадает с соглашением Laravel.
        $schema->unsetRelation('tables');
        $schema->unsetRelation('relations');
        $baseline = Snapshot::fromSchema($schema);
        $uidToId = $schema->tables->flatMap(fn ($t) => $t->columns)->pluck('id', 'uid');
        foreach ($baseline['relations'] as &$relation) {
            $relation['name'] = $fkNames[$uidToId[$relation['from']] ?? 0] ?? null;
        }
        unset($relation);

        $schema->update(['baseline' => $baseline, 'committed_at' => now()]);
        $schema->touch();
    }

    /** @return array{0: int, 1: int} */
    private function gridPosition(int $index): array
    {
        $perRow = 4;

        return [80 + ($index % $perRow) * 320, 80 + intdiv($index, $perRow) * 300];
    }
}
