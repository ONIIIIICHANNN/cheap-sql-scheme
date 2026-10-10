<?php

namespace CheapSqlScheme\Services;

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Кладёт закоммиченную миграцию в папку миграций приложения и проверяет, что
 * в её код не попадёт ничего, кроме обычных имён.
 */
class MigrationWriter
{
    public function directory(): string
    {
        return config('cheap-sql-scheme.migrations_path') ?? database_path('migrations');
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
}
