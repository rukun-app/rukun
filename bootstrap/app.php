<?php

use Core\Http\ApiResponse;
use Core\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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
        $middleware->alias(['active' => EnsureUserIsActive::class, 'permission' => PermissionMiddleware::class]);
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
            $message = match (true) {
                $exception instanceof AuthenticationException => 'Unauthenticated.',
                $exception instanceof AuthorizationException => 'You do not have permission to perform this action.',
                $exception instanceof UnauthorizedException => 'You do not have permission to perform this action.',
                $exception instanceof ModelNotFoundException => 'Resource not found.',
                $exception instanceof ValidationException => 'Validation failed.',
                $status === 404 => 'Resource not found.',
                $status === 405 => 'Method not allowed.',
                $status === 429 => 'Too many requests.',
                $status >= 500 => 'Server error.',
                default => $exception->getMessage() ?: 'Request failed.',
            };

            return ApiResponse::error($message, $status, $exception instanceof ValidationException ? $exception->errors() : []);
        });
    })->create();
