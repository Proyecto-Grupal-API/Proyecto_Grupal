<?php

namespace App\Http\Middleware;

use App\Exceptions\DirectoryFailure;
use App\Services\Directory\DirectoryInput;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use JsonException;
use MongoDB\Driver\Exception\Exception as MongoException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Outermost route middleware: isolated errors, including OAuth/grant failures. */
final class DirectoryTransport
{
    public static function matches(Request $request): bool
    {
        return str_starts_with($request->decodedPath(), 'api/v1/identity/directory/');
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Do not reflect an arbitrary header into audit/errors.
        $request->attributes->set('directory_request_id', (string) Str::uuid());
        try {
            if (strlen($request->getContent()) > DirectoryInput::MAX_REQUEST_BYTES) { throw new DirectoryFailure(413, 'directory_request_too_large'); }
            if ($request->query->count() !== 0) { throw new DirectoryFailure(422, 'directory_invalid_request'); }
            if (! $request->isJson()) { throw new DirectoryFailure(415, 'directory_json_required'); }
            try { $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new DirectoryFailure(422, 'directory_invalid_request'); }
            if (! $body instanceof \stdClass) { throw new DirectoryFailure(422, 'directory_invalid_request'); }
            // Preserve exact references; global TrimStrings/ConvertEmptyStrings must not change IDs.
            $request->attributes->set('directory_payload', json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $response = $next($request);
            if (strlen((string) $response->getContent()) > DirectoryInput::MAX_RESPONSE_BYTES) {
                throw new DirectoryFailure(503, 'directory_response_unavailable');
            }
        } catch (Throwable $error) {
            $response = self::failure($request, $error);
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function failure(Request $request, Throwable $error): Response
    {
            $status = match (true) {
                $error instanceof DirectoryFailure => $error->status,
                $error instanceof QueryException, $error instanceof MongoException => 503,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            $code = $error instanceof DirectoryFailure ? $error->errorCode : match ($status) {
                401 => 'directory_unauthenticated', 403 => 'directory_access_denied',
                404 => 'directory_not_found', 405 => 'directory_method_not_allowed',
                429 => 'directory_rate_limited', 503 => 'directory_unavailable',
                default => 'directory_internal_error',
            };
            $message = match ($status) {
                401 => 'A valid service token is required.', 403 => 'Service access denied.',
                404 => 'Not found.', 405 => 'Method not allowed.',
                409 => 'Directory reference cannot be resolved.', 413 => 'Request too large.',
                415 => 'JSON is required.', 422 => 'Invalid request.', 429 => 'Too many requests.',
                503 => 'Directory service unavailable.', default => 'Internal service error.',
            };
            $headers = [];
            if ($status === 401) { $headers['WWW-Authenticate'] = 'Bearer'; }
            if ($error instanceof DirectoryFailure && $error->retryAfter > 0) { $headers['Retry-After'] = (string) $error->retryAfter; }
            $response = new JsonResponse(['message' => $message, 'error_code' => $code,
                'meta' => ['request_id' => $request->attributes->get('directory_request_id') ?: (string) Str::uuid(), 'api_version' => 'v1']], $status, $headers);
            // No report($error): database errors may include credentials, queries or enrollment.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
