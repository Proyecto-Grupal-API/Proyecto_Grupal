<?php

namespace App\Http\Controllers\StudentServices\Benefits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Formato de respuesta de las APIs del Equipo 5 (acordado con el
 * Equipo 6): éxito en `data` + `meta`; error con `codigo`, `mensaje` y
 * `correlacion_id`.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function data(Request $request, mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'api_version' => 'v1',
                'correlacion_id' => self::correlationId($request),
                ...$meta,
            ],
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(Request $request, string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $body = [
            'codigo' => $code,
            'mensaje' => $message,
            'correlacion_id' => self::correlationId($request),
        ];

        if ($details !== []) {
            $body['detalles'] = $details;
        }

        return response()->json($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function correlationId(Request $request): string
    {
        $id = $request->attributes->get('correlation_id');

        if (! is_string($id) || $id === '') {
            $id = (string) Str::uuid();
            $request->attributes->set('correlation_id', $id);
        }

        return $id;
    }
}
