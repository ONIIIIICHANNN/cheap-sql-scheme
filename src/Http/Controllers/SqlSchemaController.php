<?php

namespace CheapSqlScheme\Http\Controllers;

use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Services\MigrationGenerator;
use CheapSqlScheme\Services\MigrationWriter;
use CheapSqlScheme\Services\PendingMigrations;
use CheapSqlScheme\Services\ProjectSchema;
use CheapSqlScheme\Services\SchemaDiff;
use CheapSqlScheme\Services\Snapshot;
use CheapSqlScheme\Services\SqlDumpExporter;
use CheapSqlScheme\Services\SqlSchemaExporter;
use CheapSqlScheme\Services\SqlSchemaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SqlSchemaController extends Controller
{
    public function index(Request $request, ProjectSchema $project)
    {
        // Схема базы самого проекта появляется при первом открытии редактора.
        $project->ensure();

        $schemas = SqlSchema::visibleTo($request->user())
            ->withCount('tables')
            ->orderByRaw("CASE WHEN source = 'database' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->get()
            ->each->makeHidden('baseline');

        return $this->success($schemas);
    }

    /**
     * GET /schemas/{id}/changes — что изменилось с последнего коммита:
     * список для окна коммита, готовая миграция и невыполненные миграции проекта.
     */
    public function changes(
        Request $request,
        SqlSchema $sqlSchema,
        MigrationGenerator $generator,
        PendingMigrations $pending,
    ) {
        $this->authorizeAccess($request, $sqlSchema);

        $diff = SchemaDiff::compare($sqlSchema->baseline, Snapshot::fromSchema($sqlSchema));

        return $this->success([
            'changes' => $diff->present(),
            'summary' => $diff->summary(),
            'empty' => $diff->isEmpty(),
            // По файлу на таблицу, в порядке выполнения.
            'migrations' => $diff->isEmpty() ? [] : $generator->files($diff),
            'pending' => $pending->list(),
            'required_without_default' => $diff->requiredWithoutDefault(),
            'can_write' => self::canSaveMigrations(),
            'committed_at' => $sqlSchema->committed_at,
        ]);
    }

    /**
     * POST /schemas/{id}/commit — зафиксировать изменения: собрать миграцию,
     * при write — записать её в проект, и сдвинуть базовый снимок.
     */
    public function commit(
        Request $request,
        SqlSchema $sqlSchema,
        MigrationGenerator $generator,
        MigrationWriter $writer,
        SqlSchemaExporter $exporter,
    ) {
        $this->authorizeAccess($request, $sqlSchema);
        $request->validate(['write' => 'boolean']);
        $write = $request->boolean('write');
        abort_if($write && ! self::canSaveMigrations(), 403, __('cheap-sql-scheme::messages.save_disabled'));

        $sqlSchema->load(['tables.columns', 'relations']);
        $current = Snapshot::fromSchema($sqlSchema);
        $diff = SchemaDiff::compare($sqlSchema->baseline, $current);
        abort_if($diff->isEmpty(), 422, __('cheap-sql-scheme::messages.nothing_to_commit'));

        try {
            $writer->assertSafe($sqlSchema);
            $writer->assertSafeSnapshot($sqlSchema->baseline);
        } catch (InvalidArgumentException $e) {
            abort(422, __('cheap-sql-scheme::messages.unsafe_name', ['name' => $e->getMessage()]));
        }

        // По секунде на файл: порядок миграций Laravel = порядок плана.
        $files = [];
        foreach ($generator->files($diff) as $i => $file) {
            $files[] = [
                'filename' => now()->addSeconds($i)->format('Y_m_d_His').'_'.$file['name'].'.php',
                'content' => $file['content'],
            ];
        }
        if ($write) {
            foreach ($files as $file) {
                $writer->put($file['filename'], $file['content']);
            }
        }

        $sqlSchema->update([
            'baseline' => $this->keepForeignKeyNames($sqlSchema->baseline, $current),
            'committed_at' => now(),
        ]);

        return $this->success([
            'files' => $files,
            'written' => $write,
            'path' => $write ? $this->relativePath($writer->directory()) : null,
            // Скачать несколько файлов разом браузер не даст — отдаём архивом.
            'zip' => ! $write && count($files) > 1 ? base64_encode($exporter->zipFiles($files)) : null,
        ]);
    }

    /**
     * GET /schemas/{id}/drift — изменилась ли база вне редактора (миграции в
     * обход него, правки руками). Пока есть невыполненные миграции, база
     * законно отстаёт от схемы — тогда не проверяем, чтобы не пугать зря.
     */
    public function drift(Request $request, SqlSchema $sqlSchema, ProjectSchema $project, PendingMigrations $pending)
    {
        $this->authorizeAccess($request, $sqlSchema);
        abort_unless($sqlSchema->isProjectSchema(), 422);

        $pendingList = $pending->list();
        if ($pendingList !== []) {
            return $this->success(['checked' => false, 'changed' => false, 'pending' => $pendingList]);
        }

        try {
            $diff = $project->drift($sqlSchema);
        } catch (Throwable $e) {
            report($e);

            return $this->success(['checked' => false, 'changed' => false, 'pending' => []]);
        }

        return $this->success([
            'checked' => true,
            'changed' => ! $diff->isEmpty(),
            'summary' => $diff->summary(),
            'changes' => $diff->present(),
            'pending' => [],
        ]);
    }

    /** POST /schemas/{id}/sync — подтянуть структуру из базы проекта. */
    public function sync(Request $request, SqlSchema $sqlSchema, ProjectSchema $project)
    {
        $this->authorizeAccess($request, $sqlSchema);
        abort_unless($sqlSchema->isProjectSchema(), 422);

        return $this->success($project->sync($sqlSchema));
    }

    /**
     * Новый базовый снимок. У внешних ключей, которые не менялись, остаются
     * настоящие имена из базы — по ним их потом и снимут.
     */
    private function keepForeignKeyNames(?array $base, array $current): array
    {
        $names = [];
        foreach ($base['relations'] ?? [] as $relation) {
            if (! empty($relation['name'])) {
                $names[$relation['from'].'>'.$relation['to']] = $relation['name'];
            }
        }
        foreach ($current['relations'] as &$relation) {
            $relation['name'] = $names[$relation['from'].'>'.$relation['to']] ?? null;
        }
        unset($relation);

        return $current;
    }

    private function relativePath(string $path): string
    {
        // Путь от корня проекта и с «/» — на Windows тоже.
        return str_replace('\\', '/', ltrim(Str::after($path, base_path()), '\\/'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'dialect' => 'nullable|string|max:32',
        ]);

        $schema = SqlSchema::create([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'dialect' => $validated['dialect'] ?? 'laravel',
            'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
        ]);

        return $this->success($schema);
    }

    /** Полная схема: таблицы с колонками + связи. */
    public function show(Request $request, SqlSchema $sqlSchema)
    {
        $this->authorizeAccess($request, $sqlSchema);

        $sqlSchema->load(['tables.columns', 'relations']);

        return $this->success($sqlSchema);
    }

    public function update(Request $request, SqlSchema $sqlSchema)
    {
        $this->authorizeAccess($request, $sqlSchema);

        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'dialect' => 'nullable|string|max:32',
            'canvas' => 'nullable|array',
        ]);

        // Обновляем только переданные поля — чтобы лёгкое сохранение камеры
        // (только canvas) не затирало описание/диалект схемы.
        $attributes = ['name' => $validated['name']];
        if ($request->has('description')) {
            $attributes['description'] = $validated['description'] ?? null;
        }
        if ($request->has('dialect')) {
            $attributes['dialect'] = $validated['dialect'] ?? $sqlSchema->dialect;
        }
        if ($request->has('canvas')) {
            $attributes['canvas'] = $validated['canvas'] ?? $sqlSchema->canvas;
        }

        $sqlSchema->update($attributes);

        return $this->success($sqlSchema);
    }

    public function destroy(Request $request, SqlSchema $sqlSchema)
    {
        $this->authorizeAccess($request, $sqlSchema);

        $sqlSchema->delete();

        return $this->success(null, __('cheap-sql-scheme::messages.deleted'));
    }

    /**
     * Массовое сохранение всего графа: таблицы, колонки и связи.
     * Сопоставление идёт по клиентским uid, чтобы связи ссылались на
     * колонки, созданные в этом же запросе.
     */
    public function saveDiagram(Request $request, SqlSchema $sqlSchema)
    {
        $this->authorizeAccess($request, $sqlSchema);

        $validated = $request->validate([
            'canvas' => 'nullable|array',
            'tables' => 'array',
            'tables.*.uid' => 'required|string',
            'tables.*.name' => 'required|string',
            'tables.*.pos_x' => 'required|integer',
            'tables.*.pos_y' => 'required|integer',
            'tables.*.color' => 'nullable|string|max:32',
            'tables.*.note' => 'nullable|string',
            'tables.*.columns' => 'array',
            'tables.*.columns.*.uid' => 'required|string',
            'tables.*.columns.*.name' => 'required|string',
            'tables.*.columns.*.type' => 'required|string|max:64',
            'tables.*.columns.*.length' => 'nullable|string|max:32',
            'tables.*.columns.*.nullable' => 'boolean',
            'tables.*.columns.*.is_primary' => 'boolean',
            'tables.*.columns.*.is_unique' => 'boolean',
            'tables.*.columns.*.is_index' => 'boolean',
            'tables.*.columns.*.is_auto_increment' => 'boolean',
            'tables.*.columns.*.default_value' => 'nullable|string',
            'tables.*.columns.*.note' => 'nullable|string',
            'relations' => 'array',
            'relations.*.from' => 'required|string',
            'relations.*.to' => 'required|string',
            'relations.*.type' => 'nullable|string|max:32',
            'relations.*.on_delete' => 'nullable|string|max:32',
            'relations.*.on_update' => 'nullable|string|max:32',
        ]);

        DB::transaction(function () use ($validated, $sqlSchema) {
            if (isset($validated['canvas'])) {
                $sqlSchema->update(['canvas' => $validated['canvas']]);
            }

            $keptTableIds = [];
            $columnIdByUid = [];

            foreach ($validated['tables'] ?? [] as $tablePayload) {
                $table = $sqlSchema->tables()->updateOrCreate(
                    ['uid' => $tablePayload['uid']],
                    [
                        'name' => $tablePayload['name'],
                        'pos_x' => $tablePayload['pos_x'],
                        'pos_y' => $tablePayload['pos_y'],
                        'color' => $tablePayload['color'] ?? null,
                        'note' => $tablePayload['note'] ?? null,
                    ]
                );
                $keptTableIds[] = $table->id;

                $keptColumnIds = [];
                foreach (array_values($tablePayload['columns'] ?? []) as $position => $columnPayload) {
                    $column = $table->columns()->updateOrCreate(
                        ['uid' => $columnPayload['uid']],
                        [
                            'position' => $position,
                            'name' => $columnPayload['name'],
                            'type' => $columnPayload['type'],
                            'length' => $columnPayload['length'] ?? null,
                            'nullable' => $columnPayload['nullable'] ?? false,
                            'is_primary' => $columnPayload['is_primary'] ?? false,
                            'is_unique' => $columnPayload['is_unique'] ?? false,
                            'is_index' => $columnPayload['is_index'] ?? false,
                            'is_auto_increment' => $columnPayload['is_auto_increment'] ?? false,
                            'default_value' => $columnPayload['default_value'] ?? null,
                            'note' => $columnPayload['note'] ?? null,
                        ]
                    );
                    $keptColumnIds[] = $column->id;
                    $columnIdByUid[$columnPayload['uid']] = $column->id;
                }

                $table->columns()->whereNotIn('id', $keptColumnIds ?: [0])->delete();
            }

            $sqlSchema->tables()->whereNotIn('id', $keptTableIds ?: [0])->delete();

            // Связи дешёвые — пересоздаём целиком по актуальной карте uid → id.
            $sqlSchema->relations()->delete();
            foreach ($validated['relations'] ?? [] as $relationPayload) {
                $fromId = $columnIdByUid[$relationPayload['from']] ?? null;
                $toId = $columnIdByUid[$relationPayload['to']] ?? null;
                if (! $fromId || ! $toId) {
                    continue;
                }

                $sqlSchema->relations()->create([
                    'from_column_id' => $fromId,
                    'to_column_id' => $toId,
                    'type' => $relationPayload['type'] ?? 'one_to_many',
                    'on_delete' => $relationPayload['on_delete'] ?? null,
                    'on_update' => $relationPayload['on_update'] ?? null,
                ]);
            }
        });

        $sqlSchema->load(['tables.columns', 'relations']);

        return $this->success($sqlSchema);
    }

    /** Экспорт схемы в миграцию Laravel. */
    public function export(Request $request, SqlSchema $sqlSchema, SqlSchemaExporter $exporter)
    {
        $this->authorizeAccess($request, $sqlSchema);

        $sqlSchema->load(['tables.columns', 'relations']);

        return $this->success([
            'filename' => Str::snake($sqlSchema->name).'_migration.php',
            'content' => $exporter->toLaravelMigration($sqlSchema),
            // Та же схема по файлу на таблицу — как это принято в Laravel.
            'files' => $exporter->toMigrationFiles($sqlSchema),
        ]);
    }

    /** GET /schemas/{id}/export-sql?dialect=mysql|pgsql|sqlite — структура как .sql-файл. */
    public function exportSql(Request $request, SqlSchema $sqlSchema, SqlDumpExporter $exporter)
    {
        $this->authorizeAccess($request, $sqlSchema);
        $validated = $request->validate(['dialect' => 'nullable|in:'.implode(',', SqlDumpExporter::DIALECTS)]);
        $dialect = $validated['dialect']
            ?? (in_array($sqlSchema->dialect, SqlDumpExporter::DIALECTS, true) ? $sqlSchema->dialect : 'mysql');

        $sqlSchema->load(['tables.columns', 'relations.fromColumn', 'relations.toColumn.sqlTable']);

        return $this->success([
            'dialect' => $dialect,
            'filename' => Str::snake($sqlSchema->name).'_'.$dialect.'.sql',
            'content' => $exporter->export($sqlSchema, $dialect),
        ]);
    }

    /** GET /schemas/{id}/export.zip — миграции отдельными файлами одним архивом. */
    public function exportZip(Request $request, SqlSchema $sqlSchema, SqlSchemaExporter $exporter)
    {
        $this->authorizeAccess($request, $sqlSchema);
        $sqlSchema->load(['tables.columns', 'relations']);

        return response($exporter->toZip($sqlSchema), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.Str::snake($sqlSchema->name).'_migrations.zip"',
        ]);
    }

    /**
     * Запись в проект — только где это безопасно: по умолчанию в local,
     * иначе как задано в конфиге save_migrations.
     */
    public static function canSaveMigrations(): bool
    {
        return (bool) (config('cheap-sql-scheme.save_migrations') ?? app()->environment('local'));
    }

    /** Импорт из SQL-дампа или миграции Laravel в новую схему. */
    public function import(Request $request, SqlSchemaImporter $importer)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'content' => 'required_without:file|string',
            'file' => 'required_without:content|file|max:'.(int) config('cheap-sql-scheme.import_max_kb', 5120),
        ]);

        $content = isset($validated['file'])
            ? file_get_contents($request->file('file')->getRealPath())
            : $validated['content'];

        $schema = $importer->import($content, $validated['name'], $request->user());

        $schema->load(['tables.columns', 'relations']);

        return $this->success($schema);
    }

    /** Конверт ответа { data, message, success } — его ждёт интерфейс. */
    private function success($data = null, string $message = 'success', int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
            'success' => true,
        ], $status);
    }

    private function authorizeAccess(Request $request, SqlSchema $sqlSchema): void
    {
        abort_unless(
            $sqlSchema->isVisibleTo($request->user()),
            403,
            __('cheap-sql-scheme::messages.forbidden'),
        );
    }
}
