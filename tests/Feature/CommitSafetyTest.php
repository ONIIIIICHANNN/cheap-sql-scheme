<?php

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cheap-sql-scheme-'.uniqid();
    config(['cheap-sql-scheme.migrations_path' => $this->dir, 'cheap-sql-scheme.save_migrations' => true]);

    $this->schema = SqlSchema::create(['name' => 'Shop']);
    $this->diagram = fn (array $columns) => $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [['uid' => 't', 'name' => 'products', 'pos_x' => 0, 'pos_y' => 0, 'columns' => $columns]],
        'relations' => [],
    ])->assertOk();
    $this->commit = fn () => $this->postJson("/sql-schemes/api/schemas/{$this->schema->id}/commit", ['write' => true]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function writtenFiles(string $dir): array
{
    return File::isDirectory($dir) ? collect(File::files($dir))->map->getFilename()->all() : [];
}

test('names that would inject code are refused', function () {
    ($this->diagram)([['uid' => 'c', 'name' => "x'); system('calc'); //", 'type' => 'string']]);

    ($this->commit)()->assertStatus(422);

    expect(writtenFiles($this->dir))->toBe([])
        // Ничего не закоммичено — изменения ждут исправления имени.
        ->and($this->schema->fresh()->baseline)->toBeNull();
});

test('lengths that would inject code are refused', function () {
    ($this->diagram)([['uid' => 'c', 'name' => 'total', 'type' => 'decimal', 'length' => '8); exec(1']]);

    ($this->commit)()->assertStatus(422);
    expect(writtenFiles($this->dir))->toBe([]);
});

test('writing into the project is off outside local unless enabled', function () {
    config(['cheap-sql-scheme.save_migrations' => null]);
    ($this->diagram)([['uid' => 'c', 'name' => 'id', 'type' => 'id']]);

    ($this->commit)()->assertForbidden();
    $this->get('/sql-schemes')->assertSee('"canSaveMigrations":false', false);
    // Скачать коммит можно и так.
    $this->postJson("/sql-schemes/api/schemas/{$this->schema->id}/commit", ['write' => false])->assertOk();

    // Вне testing Laravel проверяет CSRF и в тестах — он тут ни при чём.
    $this->withoutMiddleware(class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : ValidateCsrfToken::class);
    app()->detectEnvironment(fn () => 'local');
    $this->get('/sql-schemes')->assertSee('"canSaveMigrations":true', false);
    ($this->diagram)([['uid' => 'c', 'name' => 'id', 'type' => 'id'], ['uid' => 'n', 'name' => 'name', 'type' => 'string']]);
    ($this->commit)()->assertOk();
    expect(writtenFiles($this->dir))->toHaveCount(1);
});
