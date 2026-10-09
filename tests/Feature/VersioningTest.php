<?php

use CheapSqlScheme\Models\SqlSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

const SCHEMAS = '/sql-schemes/api/schemas';

beforeEach(function () {
    config([
        'cheap-sql-scheme.project_schema.enabled' => true,
        'cheap-sql-scheme.save_migrations' => true,
        'cheap-sql-scheme.migrations_path' => $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cheap-sql-scheme-'.uniqid(),
        // users — таблица тестового окружения, в схеме проекта она не нужна.
        'cheap-sql-scheme.project_schema.exclude' => [...config('cheap-sql-scheme.project_schema.exclude'), 'users'],
    ]);

    Schema::create('authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('author_id')->constrained()->cascadeOnDelete();
        $table->string('title');
        $table->text('body')->nullable();
        $table->integer('views')->default(0);
        $table->timestamps();
    });
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Схема проекта как полезная нагрузка PUT /diagram (связи — по uid колонок). */
function diagramOf(array $schema): array
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

function &tableIn(array &$diagram, string $name): array
{
    foreach ($diagram['tables'] as &$table) {
        if ($table['name'] === $name) {
            return $table;
        }
    }
    throw new RuntimeException("no table {$name}");
}

function &columnIn(array &$table, string $name): array
{
    foreach ($table['columns'] as &$column) {
        if ($column['name'] === $name) {
            return $column;
        }
    }
    throw new RuntimeException("no column {$name}");
}

function columnUid(array $diagram, string $table, string $column): string
{
    $t = collect($diagram['tables'])->firstWhere('name', $table);

    return collect($t['columns'])->firstWhere('name', $column)['uid'];
}

test('the project database becomes a schema on first open', function () {
    $list = $this->getJson(SCHEMAS)->assertOk()->json('data');

    expect($list)->toHaveCount(1)
        ->and($list[0]['source'])->toBe('database')
        ->and($list[0]['tables_count'])->toBe(2);

    $schema = SqlSchema::with('tables.columns', 'relations')->first();
    $posts = $schema->tables->firstWhere('name', 'posts');
    expect($posts->columns->pluck('name')->all())->toBe(['id', 'author_id', 'title', 'body', 'views', 'created_at', 'updated_at'])
        ->and($posts->columns->firstWhere('name', 'id')->type)->toBe('id')
        ->and($posts->columns->firstWhere('name', 'title')->type)->toBe('string')
        ->and($posts->columns->firstWhere('name', 'body')->nullable)->toBeTrue()
        ->and($posts->columns->firstWhere('name', 'views')->default_value)->toBe('0')
        ->and($schema->tables->firstWhere('name', 'authors')->columns->firstWhere('name', 'email')->is_unique)->toBeTrue()
        ->and($schema->relations)->toHaveCount(1)
        ->and($schema->relations->first()->on_delete)->toBe('cascade');

    // Сразу после синхронизации коммитить нечего.
    $this->getJson(SCHEMAS."/{$schema->id}/changes")->assertOk()->assertJsonPath('data.empty', true);

    // Второй заход не плодит копий.
    $this->getJson(SCHEMAS)->assertOk();
    expect(SqlSchema::count())->toBe(1);
});

test('a commit generates a migration with only the changes, and it really runs both ways', function () {
    $this->getJson(SCHEMAS)->assertOk();
    $schema = SqlSchema::first();
    $diagram = diagramOf($this->getJson(SCHEMAS."/{$schema->id}")->json('data'));

    // Правки на холсте.
    $posts = &tableIn($diagram, 'posts');
    columnIn($posts, 'title')['name'] = 'headline';                              // переименование
    $views = &columnIn($posts, 'views');
    $views['type'] = 'bigInteger';                                               // смена типа
    $views['nullable'] = true;
    unset($views);
    $posts['columns'] = array_values(array_filter($posts['columns'], fn ($c) => $c['name'] !== 'body')); // удаление
    $posts['columns'][] = ['uid' => 'new-slug', 'name' => 'slug', 'type' => 'string', 'is_unique' => true, 'nullable' => true];
    unset($posts);
    tableIn($diagram, 'authors')['name'] = 'writers';                            // переименование таблицы
    $diagram['tables'][] = ['uid' => 'new-comments', 'name' => 'comments', 'pos_x' => 0, 'pos_y' => 600, 'columns' => [
        ['uid' => 'nc-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
        ['uid' => 'nc-post', 'name' => 'post_id', 'type' => 'bigInteger'],
        ['uid' => 'nc-text', 'name' => 'text', 'type' => 'text'],
    ]];
    $diagram['relations'][] = ['from' => 'nc-post', 'to' => columnUid($diagram, 'posts', 'id'), 'on_delete' => 'cascade'];
    $diagram['relations'][0]['on_delete'] = 'restrict';                          // другой on delete

    $this->putJson(SCHEMAS."/{$schema->id}/diagram", $diagram)->assertOk();

    // Окно коммита: что поменялось.
    $changes = $this->getJson(SCHEMAS."/{$schema->id}/changes")->assertOk()->json('data');
    $byTable = collect($changes['changes'])->keyBy('name');
    $postItems = collect($byTable['posts']['items'])->keyBy(fn ($i) => $i['kind'].':'.$i['name']);

    expect($changes['empty'])->toBeFalse()
        ->and($byTable['comments']['status'])->toBe('added')
        ->and($byTable['writers']['status'])->toBe('renamed')
        ->and($byTable['writers']['old_name'])->toBe('authors')
        ->and($postItems['column:headline']['status'])->toBe('renamed')
        ->and($postItems['column:headline']['old_name'])->toBe('title')
        ->and($postItems['column:views']['status'])->toBe('changed')
        ->and($postItems['column:views']['before'])->toBe('integer · default 0')
        ->and($postItems['column:views']['after'])->toBe('bigInteger · nullable · default 0')
        ->and($postItems['column:body']['status'])->toBe('removed')
        ->and($postItems['column:slug']['status'])->toBe('added')
        ->and($postItems['foreign:author_id']['status'])->toBe('changed')
        ->and($changes['migration'])
        ->toContain("\$table->renameColumn('title', 'headline')")
        ->toContain("\$table->bigInteger('views')->nullable()->default(0)->change()")
        ->toContain("\$table->dropColumn(['body'])")
        ->toContain("\$table->string('slug')->nullable()->unique()")
        ->toContain("Schema::rename('authors', 'writers')")
        ->toContain("Schema::create('comments'")
        ->not->toContain("Schema::create('posts'");

    // Коммит: файл в проекте, базовый снимок сдвинулся.
    $commit = $this->postJson(SCHEMAS."/{$schema->id}/commit", ['write' => true, 'name' => 'blog_changes'])->assertOk()->json('data');
    expect($commit['filename'])->toEndWith('_blog_changes.php')
        ->and(File::exists($this->dir.'/'.$commit['filename']))->toBeTrue();
    $this->getJson(SCHEMAS."/{$schema->id}/changes")->assertJsonPath('data.empty', true);

    // Миграция действительно выполняется на базе…
    $migration = require $this->dir.'/'.$commit['filename'];
    $migration->up();

    expect(Schema::hasTable('writers'))->toBeTrue()
        ->and(Schema::hasTable('authors'))->toBeFalse()
        ->and(Schema::getColumnListing('posts'))->toContain('headline', 'slug')
        ->and(Schema::getColumnListing('posts'))->not->toContain('title', 'body')
        ->and(Schema::hasTable('comments'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('comments'))->pluck('foreign_table')->all())->toBe(['posts'])
        ->and(collect(Schema::getForeignKeys('posts'))->first()['on_delete'])->toBe('restrict')
        ->and(collect(Schema::getIndexes('posts'))->contains(fn ($i) => $i['unique'] && $i['columns'] === ['slug']))->toBeTrue()
        ->and(Schema::getColumnType('posts', 'views'))->toBe('integer'); // в SQLite bigint — это integer

    // …и откатывается обратно.
    $migration->down();

    expect(Schema::hasTable('authors'))->toBeTrue()
        ->and(Schema::hasTable('writers'))->toBeFalse()
        ->and(Schema::hasTable('comments'))->toBeFalse()
        ->and(Schema::getColumnListing('posts'))->toContain('title', 'body')
        ->and(Schema::getColumnListing('posts'))->not->toContain('headline', 'slug')
        ->and(collect(Schema::getForeignKeys('posts'))->first()['on_delete'])->toBe('cascade');
});

test('a name freed by a rename or a drop can be reused in the same commit', function () {
    $this->getJson(SCHEMAS)->assertOk();
    $schema = SqlSchema::first();
    $diagram = diagramOf($this->getJson(SCHEMAS."/{$schema->id}")->json('data'));

    $posts = &tableIn($diagram, 'posts');
    // title → headline, и новая колонка забирает имя title.
    columnIn($posts, 'title')['name'] = 'headline';
    $posts['columns'][] = ['uid' => 'new-title', 'name' => 'title', 'type' => 'string', 'nullable' => true];
    // body удалена, а views переименована в body.
    $posts['columns'] = array_values(array_filter($posts['columns'], fn ($c) => $c['name'] !== 'body'));
    columnIn($posts, 'views')['name'] = 'body';
    unset($posts);

    $this->putJson(SCHEMAS."/{$schema->id}/diagram", $diagram)->assertOk();
    $commit = $this->postJson(SCHEMAS."/{$schema->id}/commit", ['write' => true])->assertOk()->json('data');
    $migration = require $this->dir.'/'.$commit['filename'];

    $migration->up();
    expect(Schema::getColumnListing('posts'))->toContain('headline', 'title', 'body')
        ->and(Schema::getColumnListing('posts'))->not->toContain('views')
        ->and(Schema::getColumnType('posts', 'body'))->toBe('integer');

    $migration->down();
    expect(Schema::getColumnListing('posts'))->toContain('title', 'body', 'views')
        ->and(Schema::getColumnListing('posts'))->not->toContain('headline')
        ->and(Schema::getColumnType('posts', 'body'))->toBe('text');
});

test('pulling from the database picks up changes made elsewhere and keeps canvas positions', function () {
    $this->getJson(SCHEMAS)->assertOk();
    $schema = SqlSchema::first();
    $posts = $schema->tables()->where('name', 'posts')->first();
    $posts->update(['pos_x' => 999, 'pos_y' => 777]);
    $titleUid = $posts->columns()->where('name', 'title')->value('uid');

    Schema::table('posts', fn (Blueprint $t) => $t->string('subtitle')->nullable());
    Schema::create('tags', fn (Blueprint $t) => $t->id());

    $synced = $this->postJson(SCHEMAS."/{$schema->id}/sync")->assertOk()->json('data');
    $tables = collect($synced['tables'])->keyBy('name');
    $postsCols = collect($tables['posts']['columns'])->keyBy('name');

    expect($tables->keys()->sort()->values()->all())->toBe(['authors', 'posts', 'tags'])
        ->and($tables['posts']['pos_x'])->toBe(999)
        ->and($postsCols)->toHaveKey('subtitle')
        ->and($postsCols['title']['uid'])->toBe($titleUid);

    $this->getJson(SCHEMAS."/{$schema->id}/changes")->assertJsonPath('data.empty', true);
});

test('changes made to the database outside the editor are detected', function () {
    $this->getJson(SCHEMAS)->assertOk();
    $id = SqlSchema::first()->id;

    $this->getJson(SCHEMAS."/{$id}/drift")->assertOk()
        ->assertJsonPath('data.checked', true)
        ->assertJsonPath('data.changed', false);

    // Миграция в обход редактора.
    Schema::table('posts', function (Blueprint $t) {
        $t->string('subtitle')->nullable();
        $t->dropColumn('body');
    });
    Schema::create('tags', fn (Blueprint $t) => $t->id());

    $drift = $this->getJson(SCHEMAS."/{$id}/drift")->assertOk()->json('data');
    $byTable = collect($drift['changes'])->keyBy('name');
    $postItems = collect($byTable['posts']['items'])->keyBy('name');

    expect($drift['changed'])->toBeTrue()
        ->and($byTable['tags']['status'])->toBe('added')
        ->and($postItems['subtitle']['status'])->toBe('added')
        ->and($postItems['body']['status'])->toBe('removed')
        ->and($drift['summary'])->toBe(['added' => 2, 'removed' => 1, 'changed' => 0]);

    // После обновления из базы расхождений нет.
    $this->postJson(SCHEMAS."/{$id}/sync")->assertOk();
    $this->getJson(SCHEMAS."/{$id}/drift")->assertJsonPath('data.changed', false);
});

test('after a commit is migrated the database is not reported as changed', function () {
    $this->getJson(SCHEMAS)->assertOk();
    $schema = SqlSchema::first();
    $diagram = diagramOf($this->getJson(SCHEMAS."/{$schema->id}")->json('data'));

    // Типы, которые в базе читаются иначе, чем пишутся в редакторе.
    $diagram['tables'][] = ['uid' => 'n-tags', 'name' => 'tags', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
        ['uid' => 'n-id', 'name' => 'id', 'type' => 'id', 'is_primary' => true, 'is_auto_increment' => true],
        ['uid' => 'n-name', 'name' => 'name', 'type' => 'string'],
        ['uid' => 'n-meta', 'name' => 'meta', 'type' => 'json', 'nullable' => true],
        ['uid' => 'n-price', 'name' => 'price', 'type' => 'decimal', 'nullable' => true],
        ['uid' => 'n-on', 'name' => 'is_on', 'type' => 'boolean', 'default_value' => 'true'],
        ['uid' => 'n-post', 'name' => 'post_id', 'type' => 'foreignId', 'nullable' => true],
        ['uid' => 'n-c', 'name' => 'created_at', 'type' => 'timestamp', 'nullable' => true],
        ['uid' => 'n-u', 'name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true],
    ]];
    $diagram['relations'][] = ['from' => 'n-post', 'to' => columnUid($diagram, 'posts', 'id')];
    $this->putJson(SCHEMAS."/{$schema->id}/diagram", $diagram)->assertOk();

    $this->postJson(SCHEMAS."/{$schema->id}/commit", ['write' => true])->assertOk();

    // До migrate база законно отстаёт — проверку не делаем.
    $this->getJson(SCHEMAS."/{$schema->id}/drift")->assertJsonPath('data.checked', false);

    $this->artisan('migrate', ['--path' => $this->dir, '--realpath' => true])->assertSuccessful();

    $drift = $this->getJson(SCHEMAS."/{$schema->id}/drift")->assertOk()->json('data');
    expect($drift['checked'])->toBeTrue()
        ->and($drift['changes'])->toBe([])
        ->and($drift['changed'])->toBeFalse();
});

test('drift is not checked while project migrations are pending', function () {
    $this->getJson(SCHEMAS)->assertOk();
    File::ensureDirectoryExists($this->dir);
    $this->artisan('migrate:install');
    File::put($this->dir.'/2030_01_01_000000_not_run_yet.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
    Schema::create('tags', fn (Blueprint $t) => $t->id());

    $this->getJson(SCHEMAS.'/'.SqlSchema::first()->id.'/drift')->assertOk()
        ->assertJsonPath('data.checked', false)
        ->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.pending', ['2030_01_01_000000_not_run_yet']);
});

test('a fresh schema commits as create migrations for everything', function () {
    config(['cheap-sql-scheme.project_schema.enabled' => false]);
    $schema = SqlSchema::create(['name' => 'Shop']);
    $this->putJson(SCHEMAS."/{$schema->id}/diagram", [
        'tables' => [['uid' => 't', 'name' => 'products', 'pos_x' => 0, 'pos_y' => 0, 'columns' => [
            ['uid' => 'c', 'name' => 'id', 'type' => 'id'],
        ]]],
        'relations' => [],
    ])->assertOk();

    $changes = $this->getJson(SCHEMAS."/{$schema->id}/changes")->json('data');
    expect($changes['name'])->toBe('create_products_table')
        ->and($changes['changes'][0]['status'])->toBe('added');

    $this->postJson(SCHEMAS."/{$schema->id}/commit")->assertOk();
    $this->postJson(SCHEMAS."/{$schema->id}/commit")->assertStatus(422); // нечего коммитить
});

test('pending migrations of the project are reported', function () {
    File::ensureDirectoryExists($this->dir);
    $this->artisan('migrate:install');
    File::put($this->dir.'/2030_01_01_000000_not_run_yet.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
    $this->getJson(SCHEMAS)->assertOk();

    $pending = $this->getJson(SCHEMAS.'/'.SqlSchema::first()->id.'/changes')->json('data.pending');

    expect($pending)->toContain('2030_01_01_000000_not_run_yet');
});
