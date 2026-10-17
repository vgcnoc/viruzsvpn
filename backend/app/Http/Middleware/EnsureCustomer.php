<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'customer' || ! $request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'A verified customer account is required.'], 403);
        }

        return $next($request);
    }
}
