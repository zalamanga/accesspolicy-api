<?php
use App\Exceptions\ApiProblem;
use App\Http\Middleware\JsonBoundary;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: '')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(JsonBoundary::class);
        $middleware->remove([
            Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
            Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $error) => true);
        $exceptions->dontReport([ApiProblem::class]);
        $exceptions->render(function (Throwable $error, Request $request) {
            [$status, $code, $message, $details] = match (true) {
                $error instanceof ApiProblem => [$error->status, $error->problemCode, $error->getMessage(), $error->details],
                $error instanceof ValidationException => [422, 'validation_failed', 'Request validation failed.', $error->errors()],
                $error instanceof AuthenticationException => [401, 'unauthenticated', 'A valid bearer token is required.', null],
                $error instanceof HttpExceptionInterface => [$error->getStatusCode(), 'http_error', match ($error->getStatusCode()) {404 => 'Resource not found.', 405 => 'Method not allowed.', default => 'Request rejected.'}, null],
                default => [500, 'internal_error', 'The request could not be completed.', null],
            };
            return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status);
        });
    })->create();
