<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The app sends its UI language in Accept-Language, so API messages match the screen. */
class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($request->getPreferredLanguage(User::LOCALES) ?? 'ru');

        return $next($request);
    }
}
