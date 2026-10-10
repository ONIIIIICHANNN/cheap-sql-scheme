<?php

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

const API_FILES = '/sql-schemes/api/schemas';

beforeEach(function () {
    config([
        'cheap-sql-scheme.project_schema.enabled' => true,
        'cheap-sql-scheme.save_migrations' => true,
        'cheap-sql-scheme.migrations_path' => $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cheap-sql-scheme-'.uniqid(),
        'cheap-sql-scheme.project_schema.exclude' => [...config('cheap-sql-scheme.project_schema.exclude'), 'users'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('notes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->constrained();
        $table->text('text');
    });
    Schema::create('archive', function (Blueprint $table) {
        $table->id();
    });
    Schema::create('archive_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('archive_id')->constrained('archive');
    });

    $this->getJson(API_FILES)->assertOk();
    $this->schema = SqlSchema::first();
    $this->diagram = fn () => diagramOfFiles($this->getJson(API_FILES."/{$this->schema->id}")->json('data'));
    $this->save = fn (array $d) => $this->putJson(API_FILES."/{$this->schema->id}/diagram", $d)->assertOk();
    $this->names = fn () => collect($this->getJson(API_FILES."/{$this->schema->id}/changes")->json('data.migrations'))->pluck('name')->all();
    $this->commitAndMigrate = function () {
        $this->postJson(API_FILES."/{$this->schema->id}/commit", ['write' => true])->assertOk();
        $this->artisan('migrate', ['--path' => $this->dir, '--realpath' => true])->assertSuccessful();
    };
    $this->rollback = fn () => $this->artisan('migrate:rollback', ['--path' => $this->dir, '--realpath' => true])->assertSuccessful();
});

afterEach(fn () => File::deleteDirectory($this->dir));

function diagramOfFiles(array $schema): array
{
    $uid = [];
    foreach ($schema['tables'] as $table) {
        foreach ($table['columns'] as $column) {
            $uid[$column['id']] = $column['uid'];
        }
    }

    return [
        'tables' => array_map(fn ($t) => [
            'uid' => $t['uid'], 'name' => $t['name'], 'pos_x' => $t['pos_x'], 'pos_y' => $t['pos_y'],
            'columns' => array_map(fn ($c) => collect($c)->only([
                'uid', 'name', 'type', 'length', 'nullable', 'is_primary', 'is_unique', 'is_index', 'is_auto_increment', 'default_value',
            ])->all(), $t['columns']),
        ], $schema['tables']),
        'relations' => array_map(fn ($r) => [
            'from' => $uid[$r['from_column_id']], 'to' => $uid[$r['to_column_id']],
            'on_delete' => $r['on_delete'], 'on_update' => $r['on_update'],
        ], $schema['relations']),
    ];
}

function uidOf(array $diagram, string $table, string $column): string
{
    $t = collect($diagram['tables'])->firstWhere('name', $table);

    return collect($t['columns'])->firstWhere('name', $column)['uid'];
}

test('a new table and a new column elsewhere become two migrations', function () {
    $d = ($this->diagram)();
    $d['tables'][] = ['uid' => 'n-tags', 'name' => 'tags', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-tags-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
        ['uid' => 'n-tags-name', 'name' => 'name', 'type' => 'string'],
    ]];
    foreach ($d['tables'] as &$t) {
        if ($t['name'] === 'customers') {
            $t['columns'][] = ['uid' => 'n-phone', 'name' => 'phone', 'type' => 'string', 'nullable' => true];
        }
    }
    unset($t);
    ($this->save)($d);

    expect(($this->names)())->toBe(['create_tags_table', 'add_phone_to_customers_table']);

    ($this->commitAndMigrate)();
    expect(Schema::hasTable('tags'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'phone'))->toBeTrue();

    ($this->rollback)();
    expect(Schema::hasTable('tags'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'phone'))->toBeFalse();
});

test('a column that references a new table goes after that table is created', function () {
    $d = ($this->diagram)();
    $d['tables'][] = ['uid' => 'n-groups', 'name' => 'groups', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-groups-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
    ]];
    foreach ($d['tables'] as &$t) {
        if ($t['name'] === 'customers') {
            $t['columns'][] = ['uid' => 'n-group', 'name' => 'group_id', 'type' => 'bigInteger', 'nullable' => true];
        }
    }
    unset($t);
    $d['relations'][] = ['from' => 'n-group', 'to' => 'n-groups-id', 'on_delete' => 'set null'];
    ($this->save)($d);

    expect(($this->names)())->toBe(['create_groups_table', 'add_group_id_to_customers_table']);

    ($this->commitAndMigrate)();
    expect(collect(Schema::getForeignKeys('customers'))->pluck('foreign_table')->all())->toBe(['groups']);

    ($this->rollback)();
    expect(Schema::hasTable('groups'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'group_id'))->toBeFalse();
});

test('new tables that reference each other get their keys in a separate last file', function () {
    $d = ($this->diagram)();
    $d['tables'][] = ['uid' => 'n-a', 'name' => 'teams', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-a-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
        ['uid' => 'n-a-owner', 'name' => 'owner_id', 'type' => 'bigInteger', 'nullable' => true],
    ]];
    $d['tables'][] = ['uid' => 'n-b', 'name' => 'members', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-b-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
        ['uid' => 'n-b-team', 'name' => 'team_id', 'type' => 'bigInteger', 'nullable' => true],
    ]];
    $d['relations'][] = ['from' => 'n-a-owner', 'to' => 'n-b-id'];
    $d['relations'][] = ['from' => 'n-b-team', 'to' => 'n-a-id'];
    ($this->save)($d);

    $names = ($this->names)();
    expect($names)->toHaveCount(3)
        ->and($names[0])->toStartWith('create_')
        ->and($names[1])->toStartWith('create_')
        ->and($names[2])->toStartWith('add_foreign_keys_to_');

    ($this->commitAndMigrate)();
    expect(collect(Schema::getForeignKeys('teams'))->pluck('foreign_table')->all())->toBe(['members'])
        ->and(collect(Schema::getForeignKeys('members'))->pluck('foreign_table')->all())->toBe(['teams']);

    ($this->rollback)();
    expect(Schema::hasTable('teams'))->toBeFalse()->and(Schema::hasTable('members'))->toBeFalse();
});

test('dropping linked tables removes the referencing one first and rolls back', function () {
    $d = ($this->diagram)();
    $d['tables'] = array_values(array_filter($d['tables'], fn ($t) => ! in_array($t['name'], ['archive', 'archive_items'], true)));
    $d['relations'] = array_values(array_filter(
        $d['relations'],
        fn ($r) => $r['from'] !== uidOf(($this->diagram)(), 'archive_items', 'archive_id'),
    ));
    ($this->save)($d);

    expect(($this->names)())->toBe(['drop_archive_items_table', 'drop_archive_table']);

    ($this->commitAndMigrate)();
    expect(Schema::hasTable('archive'))->toBeFalse()->and(Schema::hasTable('archive_items'))->toBeFalse();

    ($this->rollback)();
    expect(Schema::hasTable('archive'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('archive_items'))->pluck('foreign_table')->all())->toBe(['archive']);
});

test('removing a column that another table references drops that key first', function () {
    $d = ($this->diagram)();
    // У notes снимаем ссылку, а у customers удаляем колонку, на которую ссылались бы.
    $notesFk = uidOf($d, 'notes', 'customer_id');
    $d['relations'] = array_values(array_filter($d['relations'], fn ($r) => $r['from'] !== $notesFk));
    foreach ($d['tables'] as &$t) {
        if ($t['name'] === 'notes') {
            $t['columns'] = array_values(array_filter($t['columns'], fn ($c) => $c['name'] !== 'customer_id'));
        }
    }
    unset($t);
    ($this->save)($d);

    expect(($this->names)())->toBe(['update_notes_table']);

    ($this->commitAndMigrate)();
    expect(Schema::hasColumn('notes', 'customer_id'))->toBeFalse()
        ->and(Schema::getForeignKeys('notes'))->toBe([]);

    ($this->rollback)();
    expect(collect(Schema::getForeignKeys('notes'))->pluck('foreign_table')->all())->toBe(['customers']);
});

test('several files can be downloaded as one zip', function () {
    $d = ($this->diagram)();
    $d['tables'][] = ['uid' => 'n-x', 'name' => 'x_items', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-x-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
    ]];
    foreach ($d['tables'] as &$t) {
        if ($t['name'] === 'customers') {
            $t['columns'][] = ['uid' => 'n-mail', 'name' => 'mail', 'type' => 'string', 'nullable' => true];
        }
    }
    unset($t);
    ($this->save)($d);

    $commit = $this->postJson(API_FILES."/{$this->schema->id}/commit", ['write' => false])->assertOk()->json('data');

    expect($commit['files'])->toHaveCount(2)
        ->and($commit['written'])->toBeFalse()
        ->and(substr(base64_decode($commit['zip']), 0, 2))->toBe('PK')
        ->and(File::isDirectory($this->dir))->toBeFalse();
});
