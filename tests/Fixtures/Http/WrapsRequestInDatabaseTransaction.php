<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application middleware that runs the whole request inside one database transaction, as
 * some applications do around every request.
 */
final class WrapsRequestInDatabaseTransaction
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(fn (): Response => $next($request));
    }
}
