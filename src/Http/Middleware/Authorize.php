<?php

namespace CheapSqlScheme\Http\Middleware;

use CheapSqlScheme\CheapSqlScheme;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Пускает в редактор и его API только тех, кого пускает CheapSqlScheme::auth(). */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CheapSqlScheme::check($request), 403);

        return $next($request);
    }
}
