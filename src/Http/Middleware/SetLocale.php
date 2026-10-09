<?php

namespace CheapSqlScheme\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ответы редактора — на языке его интерфейса (config cheap-sql-scheme.locale). */
class SetLocale
{
    public const LOCALES = ['en', 'ru'];

    public static function locale(): string
    {
        $locale = (string) config('cheap-sql-scheme.locale', 'en');

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(self::locale());

        return $next($request);
    }
}
