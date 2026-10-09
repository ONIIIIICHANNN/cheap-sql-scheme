<?php

namespace CheapSqlScheme;

use Closure;
use Illuminate\Http\Request;

/**
 * Кто может открыть редактор. По умолчанию — все: закрыть его решает
 * приложение, в своём сервис-провайдере:
 *
 *     CheapSqlScheme::auth(fn (Request $request) => $request->user()?->is_admin);
 */
class CheapSqlScheme
{
    /** @var (Closure(Request): bool)|null */
    protected static ?Closure $authUsing = null;

    /** @param  Closure(Request): bool  $callback */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        return static::$authUsing === null || (bool) (static::$authUsing)($request);
    }
}
