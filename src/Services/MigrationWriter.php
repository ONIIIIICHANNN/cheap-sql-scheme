<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Записывает миграции схемы (по файлу на таблицу) прямо в папку миграций
 * приложения. Имена берутся из экспорта, но метка времени — текущая, чтобы
 * новые файлы шли после уже существующих миграций проекта.
 */
class MigrationWriter
{
    public function __construct(private SqlSchemaExporter $exporter) {}

    public function directory(): string
    {
        return config('cheap-sql-scheme.migrations_path') ?? database_path('migrations');
    }

    /**
     * Таблицы, для которых в проекте уже есть миграция с тем же именем
     * (create_users_table.php и т. п.): имя таблицы-файла → существующий файл.
     *
     * @return array<string, string>
     */
    public function conflicts(SqlSchema $schema): array
    {
        $conflicts = [];
        foreach ($this->exporter->toMigrationFiles($schema) as $file) {
            $existing = $this->existing($this->suffix($file['filename']));
            if ($existing !== null) {
                $conflicts[$this->suffix($file['filename'])] = basename($existing);
            }
        }

        return $conflicts;
    }

    /**
     * Записать миграции. Существующие миграции тех же таблиц удаляются и
     * пишутся заново — так весь набор схемы остаётся в порядке зависимостей.
     *
     * @return array<int, string> имена записанных файлов
     */
    public function write(SqlSchema $schema): array
    {
        $this->assertSafe($schema);

        $directory = $this->directory();
        File::ensureDirectoryExists($directory);

        $written = [];
        $now = now();
        foreach ($this->exporter->toMigrationFiles($schema) as $i => $file) {
            $suffix = $this->suffix($file['filename']);
            if (! preg_match('/^[A-Za-z0-9_]+\.php$/', $suffix)) {
                throw new InvalidArgumentException($file['filename']);
            }

            if ($existing = $this->existing($suffix)) {
                File::delete($existing);
            }

            // По секунде на файл: порядок миграций = порядок зависимостей.
            $filename = $now->copy()->addSeconds($i)->format('Y_m_d_His').'_'.$suffix;
            File::put($directory.DIRECTORY_SEPARATOR.$filename, $file['content']);
            $written[] = $filename;
        }

        return $written;
    }

    /** Записать один готовый файл миграции. */
    public function put(string $filename, string $content): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+\.php$/', $filename)) {
            throw new InvalidArgumentException($filename);
        }
        File::ensureDirectoryExists($this->directory());
        File::put($path = $this->directory().DIRECTORY_SEPARATOR.$filename, $content);

        return $path;
    }

    /**
     * Имена таблиц и колонок попадают в PHP-код миграции, который потом
     * выполнится. Пишем в проект только обычные идентификаторы и числовые
     * длины — иначе через имя можно было бы подсунуть код.
     *
     * @throws InvalidArgumentException с первым неподходящим именем
     */
    public function assertSafe(SqlSchema $schema): void
    {
        $identifier = '/^[A-Za-z_][A-Za-z0-9_]*$/';

        foreach ($schema->tables as $table) {
            if (! preg_match($identifier, $table->name)) {
                throw new InvalidArgumentException($table->name);
            }
            foreach ($table->columns as $column) {
                if (! preg_match($identifier, $column->name)) {
                    throw new InvalidArgumentException($table->name.'.'.$column->name);
                }
                if ($column->length !== null && $column->length !== ''
                    && ! preg_match('/^\d+(\s*,\s*\d+)?$/', (string) $column->length)) {
                    throw new InvalidArgumentException($table->name.'.'.$column->name.' ('.$column->length.')');
                }
            }
        }
    }

    /**
     * То же для базового снимка: старые имена (при переименовании и
     * удалении) и имена внешних ключей тоже попадают в код миграции.
     */
    public function assertSafeSnapshot(?array $snapshot): void
    {
        $identifier = '/^[A-Za-z_][A-Za-z0-9_]*$/';
        foreach ($snapshot['tables'] ?? [] as $table) {
            foreach ([$table['name'], ...array_column($table['columns'], 'name')] as $name) {
                if (! preg_match($identifier, (string) $name)) {
                    throw new InvalidArgumentException((string) $name);
                }
            }
        }
        foreach ($snapshot['relations'] ?? [] as $relation) {
            if (! empty($relation['name']) && ! preg_match($identifier, (string) $relation['name'])) {
                throw new InvalidArgumentException((string) $relation['name']);
            }
        }
    }

    /** «2026_10_09_000001_create_users_table.php» → «create_users_table.php». */
    private function suffix(string $filename): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
    }

    private function existing(string $suffix): ?string
    {
        $matches = glob($this->directory().DIRECTORY_SEPARATOR.'*_'.$suffix) ?: [];

        return $matches[0] ?? null;
    }
}
