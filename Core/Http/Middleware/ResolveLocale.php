<?php

namespace Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Modules\Settings\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveLocale
{
    public function __construct(private Settings $settings) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('localization.supported');
        $requested = filled($request->header('Accept-Language')) ? $request->getPreferredLanguage($supported) : null;
        $userLocale = null;
        try {
            $configured = $this->settings->get('app.locale');
        } catch (Throwable) {
            $configured = config('app.locale');
        }
        $locale = $requested ?: (in_array($userLocale, $supported, true) ? $userLocale : null) ?: (in_array($configured, $supported, true) ? $configured : config('app.fallback_locale'));

        App::setLocale($locale);
        $response = $next($request);
        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }
}
