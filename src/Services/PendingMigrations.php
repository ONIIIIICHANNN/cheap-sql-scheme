<?php

namespace CheapSqlScheme\Services;

use Throwable;

/**
 * Файлы миграций проекта, которые ещё не выполнены (нет в таблице
 * migrations). Пока они есть, база отстаёт от кода — об этом стоит знать
 * до нового коммита.
 */
class PendingMigrations
{
    /** @return array<int, string> имена без .php */
    public function list(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return [];
            }

            $paths = array_unique(array_filter([
                database_path('migrations'),
                config('cheap-sql-scheme.migrations_path'),
                ...$migrator->paths(),
            ]));
            $files = array_keys($migrator->getMigrationFiles($paths));
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff($files, $ran));
        } catch (Throwable) {
            return [];
        }
    }
}
