<?php

return [

    /*
    | Адрес редактора: интерфейс открывается по /{path}, API — под /{path}/api.
    */

    'path' => env('CHEAP_SQL_SCHEME_PATH', 'sql-schemes'),

    /*
    | Язык интерфейса: en или ru.
    */

    'locale' => env('CHEAP_SQL_SCHEME_LOCALE', 'en'),

    /*
    | Middleware интерфейса и API. По умолчанию редактор открыт всем — закрыть
    | его можно здесь (например, добавить 'auth') или в своём сервис-провайдере:
    |
    |     CheapSqlScheme::auth(fn ($request) => $request->user()?->is_admin);
    */

    'middleware' => ['web'],

    /*
    | false — все, кого пустили в редактор, работают в общем пространстве и
    | видят все схемы. true — залогиненный пользователь видит только свои.
    */

    'per_user' => false,

    /*
    | Куда ведёт «← Назад» со списка схем. null — кнопки нет.
    */

    'back_url' => '/',

    /*
    | Модель автора схемы для связи SqlSchema::user().
    | null — берётся auth.providers.users.model.
    */

    'user_model' => null,

    /*
    | Максимальный размер импортируемого файла, КБ.
    */

    'import_max_kb' => 5120,

    /*
    | Кнопка «Сохранить в проект» — пишет миграции прямо в папку миграций.
    | null — только в local-окружении (на проде из браузера в код не пишем).
    | true / false — включить или выключить везде.
    */

    'save_migrations' => env('CHEAP_SQL_SCHEME_SAVE_MIGRATIONS'),

    /*
    | Куда писать миграции. null — database/migrations.
    */

    'migrations_path' => null,

    /*
    | Схема базы самого проекта: создаётся при первом открытии редактора,
    | обновляется кнопкой «Pull from database». Служебные таблицы Laravel и
    | таблицы пакета в неё не попадают.
    */

    'project_schema' => [
        'enabled' => env('CHEAP_SQL_SCHEME_PROJECT_SCHEMA', true),
        // null — «{APP_NAME} database».
        'name' => null,
        // Соединение из config/database.php; null — по умолчанию.
        'connection' => null,
        'exclude' => [
            'migrations',
            'sql_schemas', 'sql_tables', 'sql_columns', 'sql_relations',
            'password_reset_tokens', 'sessions',
            'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs',
        ],
    ],

];
