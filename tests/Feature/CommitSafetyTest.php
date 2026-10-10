<?php

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cheap-sql-scheme-'.uniqid();
    config(['cheap-sql-scheme.migrations_path' => $this->dir, 'cheap-sql-scheme.save_migrations' => true]);

    // Писать в проект можно только схему его базы.
    $this->schema = SqlSchema::create(['name' => 'Project', 'source' => SqlSchema::SOURCE_DATABASE]);
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

test('a schema drawn for another project is never written into this one', function () {
    $other = SqlSchema::create(['name' => 'Someone else']);
    $this->putJson("/sql-schemes/api/schemas/{$other->id}/diagram", [
        'tables' => [['uid' => 't', 'name' => 'invoices', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
            ['uid' => 'c', 'name' => 'id', 'type' => 'id'],
        ]]],
        'relations' => [],
    ])->assertOk();

    $changes = $this->getJson("/sql-schemes/api/schemas/{$other->id}/changes")->assertOk()->json('data');
    expect($changes['is_project'])->toBeFalse()
        ->and($changes['can_write'])->toBeFalse()
        // Миграции этого проекта к чужой схеме отношения не имеют.
        ->and($changes['pending'])->toBe([]);

    $this->postJson("/sql-schemes/api/schemas/{$other->id}/commit", ['write' => true])->assertStatus(422);
    expect(writtenFiles($this->dir))->toBe([])
        ->and($other->fresh()->baseline)->toBeNull();

    // Скачать — можно: это миграции для того проекта.
    $this->postJson("/sql-schemes/api/schemas/{$other->id}/commit", ['write' => false])->assertOk()
        ->assertJsonPath('data.files.0.content', fn ($c) => str_contains($c, "Schema::create('invoices'"));
    expect(writtenFiles($this->dir))->toBe([]);
});

test('a new table with a foreign key counts as one change', function () {
    $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [['uid' => 't', 'name' => 'products', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
            ['uid' => 'c', 'name' => 'id', 'type' => 'id'],
        ]]],
        'relations' => [],
    ])->assertOk();
    $this->postJson("/sql-schemes/api/schemas/{$this->schema->id}/commit", ['write' => true])->assertOk();

    // Новая таблица со ссылкой на products и новая колонка со ссылкой в products.
    $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [
            ['uid' => 't', 'name' => 'products', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'c', 'name' => 'id', 'type' => 'id'],
                ['uid' => 'p-parent', 'name' => 'parent_id', 'type' => 'bigInteger', 'nullable' => true],
            ]],
            ['uid' => 'r', 'name' => 'reviews', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'r-id', 'name' => 'id', 'type' => 'id'],
                ['uid' => 'r-product', 'name' => 'product_id', 'type' => 'bigInteger'],
            ]],
        ],
        'relations' => [
            ['from' => 'r-product', 'to' => 'c'],
            ['from' => 'p-parent', 'to' => 'c'],
        ],
    ])->assertOk();

    $this->getJson("/sql-schemes/api/schemas/{$this->schema->id}/changes")
        ->assertJsonPath('data.summary', ['added' => 2, 'removed' => 0, 'changed' => 0]);
});
