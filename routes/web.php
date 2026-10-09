<?php

use CheapSqlScheme\Http\Controllers\SqlSchemaController;
use CheapSqlScheme\Http\Controllers\UiController;
use CheapSqlScheme\Http\Middleware\Authorize;
use CheapSqlScheme\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

$path = trim((string) config('cheap-sql-scheme.path'), '/');

// Собранные JS/CSS интерфейса — без входа, это просто статика.
Route::get($path.'/assets/{file}', [UiController::class, 'asset'])
    ->where('file', 'app\.(js|css)')
    ->name('cheap-sql-scheme.asset');

Route::prefix($path)
    ->middleware([...config('cheap-sql-scheme.middleware', ['web']), SetLocale::class, Authorize::class])
    ->name('cheap-sql-scheme.')
    ->group(function () {
        Route::prefix('api/schemas')->name('schemas.')->group(function () {
            Route::get('/', [SqlSchemaController::class, 'index'])->name('index');
            Route::post('/', [SqlSchemaController::class, 'store'])->name('store');
            Route::post('/import', [SqlSchemaController::class, 'import'])->name('import');
            Route::get('/{sqlSchema}', [SqlSchemaController::class, 'show'])->whereNumber('sqlSchema')->name('show');
            Route::put('/{sqlSchema}', [SqlSchemaController::class, 'update'])->whereNumber('sqlSchema')->name('update');
            Route::put('/{sqlSchema}/diagram', [SqlSchemaController::class, 'saveDiagram'])->whereNumber('sqlSchema')->name('diagram');
            Route::get('/{sqlSchema}/export', [SqlSchemaController::class, 'export'])->whereNumber('sqlSchema')->name('export');
            Route::get('/{sqlSchema}/export.zip', [SqlSchemaController::class, 'exportZip'])->whereNumber('sqlSchema')->name('export-zip');
            Route::post('/{sqlSchema}/save-migrations', [SqlSchemaController::class, 'saveMigrations'])->whereNumber('sqlSchema')->name('save-migrations');
            Route::get('/{sqlSchema}/changes', [SqlSchemaController::class, 'changes'])->whereNumber('sqlSchema')->name('changes');
            Route::post('/{sqlSchema}/commit', [SqlSchemaController::class, 'commit'])->whereNumber('sqlSchema')->name('commit');
            Route::get('/{sqlSchema}/drift', [SqlSchemaController::class, 'drift'])->whereNumber('sqlSchema')->name('drift');
            Route::post('/{sqlSchema}/sync', [SqlSchemaController::class, 'sync'])->whereNumber('sqlSchema')->name('sync');
            Route::get('/{sqlSchema}/export-sql', [SqlSchemaController::class, 'exportSql'])->whereNumber('sqlSchema')->name('export-sql');
            Route::delete('/{sqlSchema}', [SqlSchemaController::class, 'destroy'])->whereNumber('sqlSchema')->name('destroy');
        });

        // Всё остальное под /{path} — страницы React-роутера.
        Route::get('/{any?}', [UiController::class, 'app'])
            ->where('any', '^(?!api(/|$)|assets/).*')
            ->name('app');
    });
