<?php
namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;

final class JsonBoundary
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $raw = $request->getContent();
        if (strlen($raw) > 16384 || (int) $request->headers->get('Content-Length', 0) > 16384) {
            throw new ApiProblem(413, 'body_too_large', 'JSON body must be at most 16384 bytes.');
        }
        if ($raw !== '') {
            if (!$request->isJson()) {
                throw new ApiProblem(415, 'json_required', 'Use Content-Type: application/json.');
            }
            try {
                $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiProblem(400, 'invalid_json', 'Body must contain valid JSON.');
            }
            if (!$value instanceof \stdClass) {
                throw new ApiProblem(400, 'object_required', 'JSON body must be an object.');
            }
        }
        if (in_array($request->method(), ['GET', 'HEAD'], true) && $raw !== '') {
            throw new ApiProblem(400, 'unexpected_body', 'This method does not accept a body.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Request-ID', bin2hex(random_bytes(16)));
        return $response;
    }
}
