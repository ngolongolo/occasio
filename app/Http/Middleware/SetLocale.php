<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', config('app.locale', 'en'));
        if (! in_array($locale, ['en', 'sw'], true)) {
            $locale = 'en';
            $request->session()->forget('locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
