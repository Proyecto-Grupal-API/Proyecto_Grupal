<?php
namespace App\Http\Middleware;
use App\Domains\Financial\Models\CashAdministrativeRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class AuthorizeCashAdministrativeScope {
    public function handle(Request $request, Closure $next): Response {
        // Outer oauth.service has already validated signature, issuer, audience and expiry.
        $id = $request->route('administrativeId');
        $operation = $id ? CashAdministrativeRequest::where('public_id', $id)
            ->where('operator_id', 'service:'.$request->attributes->get('oauth_client_id'))
            ->whereHas('shift', fn ($q) => $q->where('public_id', $request->route('shiftId'))->whereHas('cashRegister',
                fn ($register) => $register->where('association_id', $request->route('associationId'))))->value('operation') : $request->input('operation');
        $scopes = preg_split('/\s+/', trim((string) $request->attributes->get('oauth_scope', '')), -1, PREG_SPLIT_NO_EMPTY);
        if ($operation === null && $id) {
            abort_unless(in_array('financial:cash:adjust', $scopes, true) || in_array('financial:cash:recover', $scopes, true), 403);
            abort(404);
        }
        $scope = $operation === 'WITHDRAWAL_RECOVERY' ? 'financial:cash:recover' : 'financial:cash:adjust';
        abort_unless(in_array($scope, $scopes, true), 403, 'El token no tiene el permiso específico para esta operación administrativa.');
        return $next($request);
    }
}
