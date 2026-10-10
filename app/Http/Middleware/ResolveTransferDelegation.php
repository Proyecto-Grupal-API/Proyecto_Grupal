<?php
namespace App\Http\Middleware;
use App\Domains\Financial\Contracts\TransferDelegationProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
class ResolveTransferDelegation
{
    public function __construct(private readonly TransferDelegationProvider $delegation) {}
    public static function digest(Request $request): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = $canonical($item); } }
            return $value;
        };
        return hash('sha256', json_encode([
            'method' => strtoupper($request->method()), 'path' => '/' . $request->path(),
            'input' => $canonical($request->all()),
            'idempotency_key' => strtolower(trim((string) $request->header('Idempotency-Key', ''))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $client = $request->attributes->get('oauth_client_id');

        if (!is_string($client) || trim($client) === '') {
            throw new UnauthorizedHttpException('Bearer', 'Se requiere un cliente de servicio válido.');
        }

        return app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle(
            $request,
            fn (Request $limitedRequest) => $this->delegated($limitedRequest, $next, $action),
            'student-transfer-api'
        );
    }

    private function delegated(Request $request, Closure $next, string $action): Response
    {
        $client = $request->attributes->get('oauth_client_id');
        if (!is_string($client) || trim($client) === '') { throw new UnauthorizedHttpException('Bearer', 'Se requiere un cliente de servicio válido.'); }
        $proof = $request->header('X-User-Authorization');
        if (!is_string($proof) || trim($proof) === '' || strlen($proof) > 16384) {
            throw new UnauthorizedHttpException('Bearer', 'Se requiere autorización delegada del usuario.');
        }
        foreach (['sender_id', 'actor_id', 'user_id', 'source_wallet_id'] as $field) {
            if ($request->exists($field)) { return response()->json(['message' => 'La identidad y wallet de origen provienen de la delegación verificada.'], 422); }
        }
        try {
            $actor = $this->delegation->resolve($client, $proof, $action, self::digest($request));
            if (!preg_match('/^[a-fA-F0-9]{24}$/', $actor)) {
                throw new FinancialDependencyUnavailableException('El contrato de identidad devolvió una cuenta inválida.');
            }
        } catch (FinancialDependencyUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
        $request->attributes->set('transfer_delegated_user_id', strtolower($actor));
        return $next($request);
    }
}
