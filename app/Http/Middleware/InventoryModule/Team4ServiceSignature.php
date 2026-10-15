<?php
namespace App\Http\Middleware\InventoryModule;

use App\Services\InventoryModule\BusinessContext;
use Closure;
use Illuminate\Http\Request;

class Team4ServiceSignature
{
    public function handle(Request $request, Closure $next, string $consumer = 'equipo3', string $mode = 'default')
    {
        $secret = (string) config('team4.integration.secrets.'.$consumer, '');
        if ($secret === '') { return response()->json(['message' => 'Integración no configurada.'], 503); }
        $timestamp = (string) $request->header('X-Team-Timestamp', '');
        $signature = (string) $request->header('X-Team-Signature', '');
        $path = '/'.ltrim($request->path(), '/');
        $payload = $timestamp.'|'.$request->method().'|'.$path.'|'.hash('sha256', $request->getContent());
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > config('team4.integration.allowed_clock_skew', 300)
            || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return response()->json(['message' => 'Firma de integración inválida.'], 401);
        }
        $context = app(BusinessContext::class);
        if ($mode === 'registration') {
            abort_unless($consumer === 'equipo3',403);
            $request->attributes->set('inventory_service_actor','EQUIPO3');
            return $next($request);
        }
        $business = $mode === 'scoped' ? (string)$request->route('businessId') : (string)config('team4.integration.business_id');
        if (($mode === 'scoped' && $consumer !== 'equipo3') || !$context->exists($business)
            || ($request->has('business_id') && $request->input('business_id') !== $business)) {
            return response()->json(['message'=>'Negocio fuera del ámbito de esta integración.'],403);
        }
        $context->select($business);
        $request->attributes->set('inventory_service_actor', strtoupper($consumer));
        if ($consumer === 'equipo2' && !config('team4.integration.payment_events_enabled')) {
            return response()->json(['message' => 'La recepción de pagos aún no está habilitada.'], 503);
        }
        return $next($request);
    }
}
