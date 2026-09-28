<?php

namespace Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ResolveUserLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! filled($request->header('Accept-Language')) && in_array($request->user()?->locale, config('localization.supported'), true)) {
            App::setLocale($request->user()->locale);
        }

        $response = $next($request);
        $response->headers->set('Content-Language', App::currentLocale());

        return $response;
    }
}
