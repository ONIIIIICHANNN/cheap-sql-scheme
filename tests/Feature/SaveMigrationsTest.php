<?php

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cheap-sql-scheme-'.uniqid();
    config(['cheap-sql-scheme.migrations_path' => $this->dir, 'cheap-sql-scheme.save_migrations' => true]);

    $this->schema = SqlSchema::create(['name' => 'Shop']);
    $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [
            ['uid' => 't-orders', 'name' => 'orders', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'o-id', 'name' => 'id', 'type' => 'id'],
                ['uid' => 'o-user', 'name' => 'user_id', 'type' => 'bigInteger'],
            ]],
            ['uid' => 't-users', 'name' => 'users', 'pos_x' => 400, 'pos_y' => 0, 'columns' => [
                ['uid' => 'u-id', 'name' => 'id', 'type' => 'id'],
            ]],
        ],
        'relations' => [['from' => 'o-user', 'to' => 'u-id']],
    ])->assertOk();

    $this->save = fn (array $body = []) => $this->postJson("/sql-schemes/api/schemas/{$this->schema->id}/save-migrations", $body);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function migrationFiles(string $dir): array
{
    return collect(File::files($dir))->map->getFilename()->sort()->values()->all();
}

test('migrations are written into the project in dependency order', function () {
    $response = ($this->save)()->assertOk();

    $files = migrationFiles($this->dir);
    expect($files)->toHaveCount(2)
        ->and($response->json('data.files'))->toHaveCount(2)
        // users создаётся раньше orders, которая на неё ссылается.
        ->and($files[0])->toEndWith('_create_users_table.php')
        ->and($files[1])->toEndWith('_create_orders_table.php')
        ->and($files[0])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_/');

    expect(File::get($this->dir.'/'.$files[1]))->toContain("->references('id')->on('users')");
});

test('existing migrations of the same tables are reported, then overwritten on request', function () {
    File::ensureDirectoryExists($this->dir);
    File::put($this->dir.'/2020_01_01_000000_create_users_table.php', '<?php // old');

    ($this->save)()->assertStatus(409)
        ->assertJsonPath('errors.conflicts', ['2020_01_01_000000_create_users_table.php']);
    // Без согласия ничего не тронуто.
    expect(migrationFiles($this->dir))->toBe(['2020_01_01_000000_create_users_table.php']);

    ($this->save)(['overwrite' => true])->assertOk();

    $files = migrationFiles($this->dir);
    expect($files)->toHaveCount(2)
        ->and($files)->not->toContain('2020_01_01_000000_create_users_table.php');
});

test('names that would inject code are refused', function () {
    $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [
            ['uid' => 't-evil', 'name' => 'evil', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'e-name', 'name' => "x'); system('calc'); //", 'type' => 'string'],
            ]],
        ],
        'relations' => [],
    ])->assertOk();

    ($this->save)()->assertStatus(422);
    expect(File::isDirectory($this->dir) ? migrationFiles($this->dir) : [])->toBe([]);
});

test('lengths that would inject code are refused', function () {
    $this->putJson("/sql-schemes/api/schemas/{$this->schema->id}/diagram", [
        'tables' => [
            ['uid' => 't-p', 'name' => 'prices', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'p-total', 'name' => 'total', 'type' => 'decimal', 'length' => '8); exec(1'],
            ]],
        ],
        'relations' => [],
    ])->assertOk();

    ($this->save)()->assertStatus(422);
});

test('saving to the project is off outside local unless enabled', function () {
    config(['cheap-sql-scheme.save_migrations' => null]);

    ($this->save)()->assertForbidden();
    $this->get('/sql-schemes')->assertSee('"canSaveMigrations":false', false);

    // Вне testing Laravel проверяет CSRF и в тестах — он тут ни при чём.
    $this->withoutMiddleware(class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : ValidateCsrfToken::class);
    app()->detectEnvironment(fn () => 'local');
    $this->get('/sql-schemes')->assertSee('"canSaveMigrations":true', false);
    ($this->save)()->assertOk();
});
