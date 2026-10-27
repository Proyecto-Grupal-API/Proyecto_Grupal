<?php

namespace App\Http\Middleware;

use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Support\FinancialCorrelation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ResolveReceiptDelegation
{
    public static function digest(Request $request): string
    {
        $sort = function (array $items) use (&$sort): array {
            if (! array_is_list($items)) {
                ksort($items, SORT_STRING);
            }
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $sort($item);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode(['method' => strtoupper($request->method()),
            'path' => '/'.$request->path(), 'query' => $sort($request->query())],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function handle(Request $request, Closure $next, string $action)
    {
        $client = $request->attributes->get('oauth_client_id');
        $proof = $request->header('X-User-Authorization');
        if (! is_string($client) || trim($client) === '' || strlen($client) > 255
            || ! is_string($proof) || trim($proof) === '' || strlen($proof) > 16384) {
            throw new UnauthorizedHttpException('Bearer', 'Se requiere autorización delegada del usuario.');
        }
        // This API has no write payload or arbitrary identity selector.
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), ['page', 'per_page'])) {
            return response()->json(['message' => 'Solo se admiten parámetros de paginación; la identidad proviene de la delegación.'], 422);
        }
        $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:50']);
        try {
            $user = app(ReceiptDelegationProvider::class)->resolve($client, $proof, $action, self::digest($request));
            if (! preg_match('/^[a-f0-9]{24}$/i', $user)) {
                throw new FinancialDependencyUnavailableException('Identidad devolvió una cuenta inválida.');
            }
        } catch (UnauthorizedHttpException|AccessDeniedHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logger()->error('Receipt delegation dependency failed.', ['exception_type' => $e::class, 'correlation_id' => app(FinancialCorrelation::class)->id()]);

            return response()->json(['message' => 'La autorización de comprobantes no está disponible.'], 503);
        }
        $request->attributes->set('receipt_delegated_user_id', strtolower($user));

        return $next($request);
    }
}
