<?php

namespace CheapSqlScheme\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Интерфейс редактора: одна страница React и её собранные файлы. Файлы
 * отдаются прямо из пакета — публиковать ассеты не нужно.
 */
class UiController extends Controller
{
    private const ASSETS = [
        'app.js' => 'application/javascript; charset=utf-8',
        'app.css' => 'text/css; charset=utf-8',
    ];

    public function app(Request $request)
    {
        $path = trim((string) config('cheap-sql-scheme.path'), '/');
        $user = $request->user();

        return view('cheap-sql-scheme::app', [
            'version' => self::version(),
            'assetUrl' => fn (string $file) => url($path.'/assets/'.$file).'?v='.self::version(),
            'config' => [
                'apiBase' => '/'.$path.'/api',
                'basePath' => '/'.$path,
                'csrfToken' => csrf_token(),
                'backUrl' => config('cheap-sql-scheme.back_url'),
                'user' => $user ? [
                    'id' => $user->getAuthIdentifier(),
                    'name' => $user->name ?? $user->email ?? 'Вы',
                ] : null,
            ],
        ]);
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::ASSETS[$file]), 404);

        return response()->file(self::dist($file), [
            'Content-Type' => self::ASSETS[$file],
            // Имя файла постоянное, но в ссылке есть ?v=… — кэшируем надолго.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private static function dist(string $file): string
    {
        return dirname(__DIR__, 3).'/dist/'.$file;
    }

    /** Версия сборки — для сброса кэша браузера после обновления пакета. */
    private static function version(): string
    {
        return substr(md5((string) @filemtime(self::dist('app.js')).(string) @filemtime(self::dist('app.css'))), 0, 10);
    }
}
