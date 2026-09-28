<?php

use Core\Http\ApiResponse;
use Core\Http\Middleware\AddRequestLogContext;
use Core\Http\Middleware\AssignRequestId;
use Core\Http\Middleware\EnsureIdempotency;
use Core\Http\Middleware\EnsureUserIsActive;
use Core\Http\Middleware\ResolveLocale;
use Core\Http\Middleware\ResolveUserLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignRequestId::class, ResolveLocale::class]);
        $middleware->api(append: [AddRequestLogContext::class]);
        $middleware->appendToPriorityList(Authenticate::class, AddRequestLogContext::class);
        $middleware->appendToPriorityList(Authenticate::class, ResolveUserLocale::class);
        $middleware->alias(['active' => EnsureUserIsActive::class, 'idempotent' => EnsureIdempotency::class, 'locale' => ResolveUserLocale::class, 'permission' => PermissionMiddleware::class]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $code = match (true) {
                $exception instanceof AuthenticationException => 'auth.unauthenticated',
                $exception instanceof AuthorizationException, $exception instanceof UnauthorizedException => 'auth.forbidden',
                $exception instanceof ModelNotFoundException, $status === 404 => 'resource.not_found',
                $exception instanceof ValidationException => 'validation.failed',
                $status === 405 => 'request.method_not_allowed',
                $status === 429 => 'request.rate_limited',
                $status >= 500 => 'server.error',
                default => 'request.failed',
            };
            $message = match ($code) {
                'auth.unauthenticated' => __('api.errors.unauthenticated'),
                'auth.forbidden' => __('api.errors.forbidden'),
                'resource.not_found' => __('api.errors.not_found'),
                'validation.failed' => __('api.errors.validation'),
                'request.method_not_allowed' => __('api.errors.method_not_allowed'),
                'request.rate_limited' => __('api.errors.rate_limited'),
                'server.error' => __('api.errors.server'),
                default => $exception->getMessage() ?: __('api.errors.request_failed'),
            };

            $response = ApiResponse::error($message, $status, $exception instanceof ValidationException ? $exception->errors() : [], $code);
            if ($exception instanceof HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }

            return $response;
        });
    })->create();
