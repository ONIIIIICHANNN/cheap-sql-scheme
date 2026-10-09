<?php

return [

    /*
    | Адрес редактора: интерфейс открывается по /{path}, API — под /{path}/api.
    */

    'path' => env('CHEAP_SQL_SCHEME_PATH', 'sql-schemes'),

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

];
