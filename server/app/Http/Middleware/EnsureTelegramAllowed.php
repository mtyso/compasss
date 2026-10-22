<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Removing an id from ALLOWED_TELEGRAM_IDS locks that account out at once, tokens included. */
class EnsureTelegramAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isAllowed()) {
            return response()->json(['message' => __('login.not_allowed')], 403);
        }

        return $next($request);
    }
}
