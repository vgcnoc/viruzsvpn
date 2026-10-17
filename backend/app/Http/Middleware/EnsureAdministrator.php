<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'administrator') {
            return response()->json(['message' => 'Administrator access is required.'], 403);
        }

        return $next($request);
    }
}
