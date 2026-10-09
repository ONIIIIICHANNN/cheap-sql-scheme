<?php

namespace CheapSqlScheme\Http\Controllers;

use CheapSqlScheme\Models\SqlSchema;
use CheapSqlScheme\Services\SqlDumpExporter;
use CheapSqlScheme\Services\SqlSchemaExporter;
use CheapSqlScheme\Services\SqlSchemaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SqlSchemaController extends Controller
{
    public function index(Request $request)
    {
        $schemas = SqlSchema::visibleTo($request->user())
            ->withCount('tables')
            ->orderByDesc('updated_at')
            ->get();

        return $this->success($schemas);
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

        return $this->success(null, 'Схема удалена');
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
            'Нет доступа к этой схеме',
        );
    }
}
