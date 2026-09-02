<?php

use App\Exceptions\SessionNotReadableException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        // This is a token-based API with no session cookies, so channel
        // authorization runs under Sanctum, not the default 'web' guard.
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A session that is still processing is an expected client condition,
        // not an error to log on every poll.
        $exceptions->dontReport(SessionNotReadableException::class);

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => ['errors' => $exception->errors()],
                'code' => 422,
                'error' => true,
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => [],
                'code' => 401,
                'error' => true,
            ], 401);
        });

        $exceptions->render(function (SessionNotReadableException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'data' => [],
                'code' => 409,
                'error' => true,
            ], 409);
        });
    })->create();
