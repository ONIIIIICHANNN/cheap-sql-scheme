<?php

use CheapSqlScheme\CheapSqlScheme;
use CheapSqlScheme\Models\SqlSchema;

const API = '/sql-schemes/api/schemas';

beforeEach(function () {
    $this->user = $this->makeUser();
    $this->actingAs($this->user);
});

// Правило доступа статическое — между тестами снова открываем редактор.
afterEach(fn () => CheapSqlScheme::auth(fn () => true));

function newSchema($test, array $attributes = []): SqlSchema
{
    return SqlSchema::create($attributes + ['user_id' => $test->user->id, 'name' => 'Shop', 'dialect' => 'laravel']);
}

test('the editor page and its assets are served', function () {
    $this->get('/sql-schemes')
        ->assertOk()
        ->assertSee('id="cheap-sql-scheme"', false)
        ->assertSee('/sql-schemes/assets/app.js', false);

    // Глубокие ссылки React-роутера отдают ту же страницу.
    $this->get('/sql-schemes/42')->assertOk()->assertSee('window.CheapSqlScheme', false);

    $this->get('/sql-schemes/assets/app.js')->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    $this->get('/sql-schemes/assets/app.css')->assertOk();
    $this->get('/sql-schemes/assets/secret.php')->assertNotFound();
});

test('by default the editor is open to guests', function () {
    auth()->logout();

    $this->get('/sql-schemes')->assertOk();
    $this->postJson(API, ['name' => 'Guest schema'])->assertOk();

    expect(SqlSchema::first()->user_id)->toBeNull();
});

test('the app can close the editor with CheapSqlScheme::auth()', function () {
    CheapSqlScheme::auth(fn ($request) => $request->user()?->email === 'admin@example.test');

    $this->get('/sql-schemes')->assertForbidden();
    $this->getJson(API)->assertForbidden();
    // Статика интерфейса — не секрет, её закрывать незачем.
    $this->get('/sql-schemes/assets/app.js')->assertOk();

    $this->actingAs($this->makeUser('admin@example.test'));
    $this->get('/sql-schemes')->assertOk();
    $this->getJson(API)->assertOk();
});

test('a user can create a sql schema', function () {
    $response = $this->postJson(API, ['name' => 'Shop']);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Shop');
    expect(SqlSchema::count())->toBe(1);
    expect(SqlSchema::first()->user_id)->toBe((string) $this->user->id);
});

test('by default everyone works in one shared space', function () {
    newSchema($this, ['name' => 'Mine']);
    $other = $this->makeUser('other@example.test');
    $theirs = SqlSchema::create(['user_id' => $other->id, 'name' => 'Theirs']);

    $names = collect($this->getJson(API)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Mine', 'Theirs']);
    $this->getJson(API."/{$theirs->id}")->assertOk();
});

test('with per_user a user sees only own schemas', function () {
    config(['cheap-sql-scheme.per_user' => true]);
    newSchema($this, ['name' => 'Mine']);
    $other = $this->makeUser('other@example.test');
    SqlSchema::create(['user_id' => $other->id, 'name' => 'Theirs']);

    $names = collect($this->getJson(API)->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Mine']);
});

test('saving a diagram upserts tables, columns and resolves relations by uid', function () {
    $schema = newSchema($this);

    $payload = [
        'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
        'tables' => [
            [
                'uid' => 'u-users', 'name' => 'users', 'pos_x' => 0, 'pos_y' => 0,
                'columns' => [
                    ['uid' => 'c-users-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
                ],
            ],
            [
                'uid' => 'u-orders', 'name' => 'orders', 'pos_x' => 400, 'pos_y' => 0,
                'columns' => [
                    ['uid' => 'c-orders-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
                    ['uid' => 'c-orders-user', 'name' => 'user_id', 'type' => 'bigInteger'],
                ],
            ],
        ],
        'relations' => [
            ['from' => 'c-orders-user', 'to' => 'c-users-id', 'type' => 'one_to_many'],
        ],
    ];

    $this->putJson(API."/{$schema->id}/diagram", $payload)->assertOk();
    expect($schema->tables()->count())->toBe(2);
    expect($schema->relations()->count())->toBe(1);

    $relation = $schema->relations()->first();
    expect($relation->fromColumn->name)->toBe('user_id');
    expect($relation->toColumn->name)->toBe('id');

    // Повторное сохранение не плодит дубликаты (upsert по uid).
    $this->putJson(API."/{$schema->id}/diagram", $payload)->assertOk();
    expect($schema->tables()->count())->toBe(2);
});

test('a schema can be exported to a laravel migration', function () {
    $schema = newSchema($this);
    $users = $schema->tables()->create(['uid' => 'u1', 'name' => 'users', 'pos_x' => 0, 'pos_y' => 0]);
    $userId = $users->columns()->create(['uid' => 'c1', 'position' => 0, 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true]);
    $orders = $schema->tables()->create(['uid' => 'u2', 'name' => 'orders', 'pos_x' => 400, 'pos_y' => 0]);
    $fk = $orders->columns()->create(['uid' => 'c2', 'position' => 0, 'name' => 'user_id', 'type' => 'bigInteger']);
    $schema->relations()->create(['from_column_id' => $fk->id, 'to_column_id' => $userId->id, 'on_delete' => 'cascade']);

    $content = $this->getJson(API."/{$schema->id}/export")->assertOk()->json('data.content');

    expect($content)->toContain("Schema::create('users'")
        ->toContain('$table->id(')
        ->toContain("->references('id')->on('users')->cascadeOnDelete()");
});

test('a laravel migration can be imported back into a schema', function () {
    $migration = <<<'PHP'
    <?php
    return new class extends Migration {
        public function up(): void {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->timestamps();
            });
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained();
                $table->timestamps();
            });
        }
    };
    PHP;

    $response = $this->postJson(API.'/import', ['name' => 'Imported', 'content' => $migration]);

    $response->assertOk();
    $schema = SqlSchema::with('tables.columns', 'relations')->find($response->json('data.id'));
    expect($schema->user_id)->toBe((string) $this->user->id);
    expect($schema->tables)->toHaveCount(2);
    expect($schema->relations)->toHaveCount(1);

    $relation = $schema->relations->first();
    expect($relation->fromColumn->name)->toBe('user_id');
    expect($relation->toColumn->sqlTable->name)->toBe('users');
});

test('a phpmyadmin sql dump imports structure only, ignoring data', function () {
    $dump = <<<'SQL'
    -- phpMyAdmin SQL Dump
    SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

    CREATE TABLE `users` (
      `id` bigint UNSIGNED NOT NULL,
      `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT '1'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    INSERT INTO `users` (`id`, `email`, `is_active`) VALUES
    (1, 'a@b.com', 1), (2, 'c@d.com', 0);

    CREATE TABLE `orders` (
      `id` bigint UNSIGNED NOT NULL,
      `user_id` bigint UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    INSERT INTO `orders` (`id`, `user_id`) VALUES (1, 1);

    ALTER TABLE `users`
      ADD PRIMARY KEY (`id`),
      ADD UNIQUE KEY `users_email_unique` (`email`);

    ALTER TABLE `orders`
      ADD PRIMARY KEY (`id`),
      ADD KEY `orders_user_id_foreign` (`user_id`);

    ALTER TABLE `users` MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
    ALTER TABLE `orders` MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

    ALTER TABLE `orders`
      ADD CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
    SQL;

    $response = $this->postJson(API.'/import', ['name' => 'Dump', 'content' => $dump]);

    $response->assertOk();
    $schema = SqlSchema::with('tables.columns', 'relations')->find($response->json('data.id'));

    expect($schema->tables)->toHaveCount(2);
    expect($schema->relations)->toHaveCount(1);

    $users = $schema->tables->firstWhere('name', 'users');
    $id = $users->columns->firstWhere('name', 'id');
    expect($id->type)->toBe('id');
    expect($id->is_primary)->toBeTrue();
    expect($id->is_auto_increment)->toBeTrue();
    expect($users->columns->firstWhere('name', 'email')->is_unique)->toBeTrue();
    expect($users->columns->firstWhere('name', 'is_active')->type)->toBe('boolean');
});

test('a canvas-only update preserves description and dialect', function () {
    $schema = newSchema($this, [
        'description' => 'Keep me',
        'dialect' => 'mysql',
        'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
    ]);

    $this->putJson(API."/{$schema->id}", [
        'name' => 'Shop',
        'canvas' => ['zoom' => 2, 'offsetX' => 40, 'offsetY' => 12],
    ])->assertOk();

    $fresh = $schema->fresh();
    expect($fresh->description)->toBe('Keep me');
    expect($fresh->dialect)->toBe('mysql');
    expect($fresh->canvas['zoom'])->toBe(2);
});

test('with per_user a user cannot touch a schema of another user', function () {
    config(['cheap-sql-scheme.per_user' => true]);
    $other = $this->makeUser('other@example.test');
    $schema = SqlSchema::create(['user_id' => $other->id, 'name' => 'Private']);

    $this->getJson(API."/{$schema->id}")->assertForbidden();
    $this->putJson(API."/{$schema->id}", ['name' => 'Hacked'])->assertForbidden();
    $this->deleteJson(API."/{$schema->id}")->assertForbidden();
    expect($schema->fresh()->name)->toBe('Private');
});

test('a schema can be deleted', function () {
    $schema = newSchema($this);

    $this->deleteJson(API."/{$schema->id}")->assertOk();

    expect(SqlSchema::count())->toBe(0);
});

test('a schema exports as one migration per table, referenced tables first', function () {
    $schema = newSchema($this);
    $col = fn (string $uid, string $name, string $type = 'bigInteger') => ['uid' => $uid, 'name' => $name, 'type' => $type];

    // orders стоит на холсте первой, но ссылается на users — её файл должен идти после.
    // users ↔ teams ссылаются друг на друга — один из ключей уходит в отдельную миграцию.
    $this->putJson(API."/{$schema->id}/diagram", [
        'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
        'tables' => [
            ['uid' => 't-orders', 'name' => 'orders', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                $col('o-id', 'id', 'id'), $col('o-user', 'user_id'), $col('o-created', 'created_at', 'timestamp'), $col('o-updated', 'updated_at', 'timestamp'),
            ]],
            ['uid' => 't-users', 'name' => 'users', 'pos_x' => 400, 'pos_y' => 0, 'columns' => [
                $col('u-id', 'id', 'id'), $col('u-team', 'team_id'),
            ]],
            ['uid' => 't-teams', 'name' => 'teams', 'pos_x' => 800, 'pos_y' => 0, 'columns' => [
                $col('t-id', 'id', 'id'), $col('t-owner', 'owner_id'),
            ]],
        ],
        'relations' => [
            ['from' => 'o-user', 'to' => 'u-id', 'type' => 'one_to_many', 'on_delete' => 'cascade'],
            ['from' => 'u-team', 'to' => 't-id', 'type' => 'one_to_many'],
            ['from' => 't-owner', 'to' => 'u-id', 'type' => 'one_to_many'],
        ],
    ])->assertOk();

    $files = collect($this->getJson(API."/{$schema->id}/export")->assertOk()->json('data.files'));
    $names = $files->pluck('filename')->map(fn ($f) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $f))->all();

    expect($names)->toBe([
        'create_users_table.php',
        'create_orders_table.php',
        'create_teams_table.php',
        'add_foreign_keys_to_shop_tables.php',
    ]);

    $orders = $files[1]['content'];
    expect($orders)->toContain("\$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();")
        ->toContain('$table->timestamps();')
        ->and($files[3]['content'])->toContain("Schema::table('users'")
        ->toContain("dropForeign(['team_id'])");

    $zip = $this->get(API."/{$schema->id}/export.zip")->assertOk();
    expect($zip->headers->get('Content-Type'))->toBe('application/zip')
        ->and(substr($zip->getContent(), 0, 2))->toBe('PK');
});

/** Схема «users ← orders» для проверок .sql-экспорта. */
function dumpSchema($test): int
{
    $schema = newSchema($test);
    $test->putJson(API."/{$schema->id}/diagram", [
        'canvas' => ['zoom' => 1, 'offsetX' => 0, 'offsetY' => 0],
        'tables' => [
            ['uid' => 't-users', 'name' => 'users', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
                ['uid' => 'u-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
                ['uid' => 'u-email', 'name' => 'email', 'type' => 'string', 'is_unique' => true],
                ['uid' => 'u-active', 'name' => 'is_active', 'type' => 'boolean', 'default_value' => 'true'],
                ['uid' => 'u-created', 'name' => 'created_at', 'type' => 'timestamp', 'nullable' => true],
            ]],
            ['uid' => 't-orders', 'name' => 'orders', 'pos_x' => 400, 'pos_y' => 0, 'columns' => [
                ['uid' => 'o-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
                ['uid' => 'o-user', 'name' => 'user_id', 'type' => 'bigInteger'],
                ['uid' => 'o-total', 'name' => 'total', 'type' => 'decimal', 'length' => '10, 2', 'is_index' => true],
                ['uid' => 'o-note', 'name' => 'note', 'type' => 'text', 'nullable' => true],
            ]],
        ],
        'relations' => [
            ['from' => 'o-user', 'to' => 'u-id', 'type' => 'one_to_many', 'on_delete' => 'cascade'],
        ],
    ])->assertOk();

    return $schema->id;
}

test('the sqlite .sql export runs in an empty database and keeps foreign keys', function () {
    $id = dumpSchema($this);
    $sql = $this->getJson(API."/{$id}/export-sql?dialect=sqlite")->assertOk()->json('data.content');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec($sql);

    $pdo->exec("INSERT INTO users (email) VALUES ('a@example.test')");
    $pdo->exec('INSERT INTO orders (user_id, total) VALUES (1, 10.5)');
    expect((int) $pdo->query('SELECT is_active FROM users')->fetchColumn())->toBe(1);

    // Ссылка на несуществующего пользователя — ошибка: ключ работает.
    expect(fn () => $pdo->exec('INSERT INTO orders (user_id, total) VALUES (99, 1)'))->toThrow(PDOException::class);

    // ON DELETE CASCADE удаляет заказы вместе с пользователем.
    $pdo->exec('DELETE FROM users');
    expect((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn())->toBe(0);
});

test('mysql and postgres .sql exports use the right types and keys', function () {
    $id = dumpSchema($this);

    $mysql = $this->getJson(API."/{$id}/export-sql?dialect=mysql")->assertOk();
    expect($mysql->json('data.filename'))->toBe('shop_mysql.sql')
        ->and($mysql->json('data.content'))
        ->toContain('`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT')
        // Ссылка на id — того же типа, иначе MySQL не создаст ключ.
        ->toContain('`user_id` BIGINT UNSIGNED NOT NULL')
        ->toContain('`email` VARCHAR(255) NOT NULL UNIQUE')
        ->toContain('`total` DECIMAL(10,2) NOT NULL')
        ->toContain('CREATE INDEX `orders_total_index` ON `orders` (`total`);')
        ->toContain('ALTER TABLE `orders` ADD CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;');

    $pg = $this->getJson(API."/{$id}/export-sql?dialect=pgsql")->json('data.content');
    expect($pg)->toContain('"id" BIGSERIAL')
        ->toContain('"is_active" BOOLEAN NOT NULL DEFAULT TRUE')
        ->toContain('"user_id" BIGINT NOT NULL')
        ->toContain('BEGIN;')
        ->toContain('COMMIT;');

    $this->getJson(API."/{$id}/export-sql?dialect=oracle")->assertStatus(422);
});
