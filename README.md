# Cheap SQL Scheme

Визуальный редактор схем базы данных для Laravel. Ставится одной командой и открывается в браузере.

- таблицы, колонки и связи на холсте; связь тянется мышкой от колонки к колонке;
- PK, unique, index, nullable, автоинкремент, значения по умолчанию, перетаскивание колонок;
- мягкие или прямые линии, цветные зоны, выделение рамкой;
- **импорт** из SQL-дампа (в том числе phpMyAdmin) или из миграций Laravel;
- **экспорт** в одну миграцию Laravel, в миграции по файлу на таблицу (ZIP, в порядке зависимостей) или в `.sql` для MySQL, PostgreSQL и SQLite;
- автосохранение, светлая и тёмная тема.

## Установка

```bash
composer require oniichann/cheap-sql-scheme
php artisan migrate
```

Готово: откройте `/sql-schemes`.

Node.js не нужен: интерфейс уже собран и лежит в `dist/`, Laravel отдаёт его сам.

## Требования

- PHP 8.2+, расширение `zip`
- Laravel 11, 12 или 13

## Кто может открыть редактор

По умолчанию — **все**. Закрыть его решает ваше приложение, например в `AppServiceProvider::boot()`:

```php
use CheapSqlScheme\CheapSqlScheme;

// Только в локальной разработке:
CheapSqlScheme::auth(fn ($request) => app()->environment('local'));

// Только админам:
CheapSqlScheme::auth(fn ($request) => $request->user()?->is_admin);

// Через Gate:
CheapSqlScheme::auth(fn ($request) => Gate::allows('viewSqlSchemes'));
```

Можно и через middleware в конфиге: `'middleware' => ['web', 'auth']`.

Все, кого пустили, работают в общем пространстве и видят все схемы. Если залогиненный пользователь должен видеть только свои, включите `'per_user' => true`. Автор схемы запоминается в `sql_schemas.user_id`, если при создании кто-то был залогинен.

## Настройка

```bash
php artisan vendor:publish --tag=cheap-sql-scheme-config
```

`config/cheap-sql-scheme.php`:

| Ключ | По умолчанию | Что делает |
| --- | --- | --- |
| `path` | `sql-schemes` | Адрес редактора; API живёт под `/{path}/api` |
| `middleware` | `['web']` | Middleware интерфейса и API; например, `['web', 'auth']` |
| `per_user` | `false` | `true` — залогиненный видит только свои схемы |
| `back_url` | `/` | Куда ведёт «← Назад» со списка схем; `null` прячет кнопку |
| `user_model` | `null` | Модель автора для `SqlSchema::user()`; `null` берёт `auth.providers.users.model` |
| `import_max_kb` | `5120` | Максимальный размер импортируемого файла |

Адрес можно поменять и без публикации конфига: `CHEAP_SQL_SCHEME_PATH=db-designer` в `.env`.

Другие публикации:

```bash
php artisan vendor:publish --tag=cheap-sql-scheme-migrations   # миграции к себе в проект
php artisan vendor:publish --tag=cheap-sql-scheme-views        # Blade-обёртка страницы
```

## Таблицы

Пакет создаёт `sql_schemas`, `sql_tables`, `sql_columns` и `sql_relations`.

## Разработка

```bash
composer install
composer test                 # тесты Pest на Testbench

cd resources/js
npm install
npm run build                 # пересобрать интерфейс в dist/
```

Собранный `dist/` коммитится вместе с кодом: его получают те, кто ставит пакет.

## Лицензия

MIT
