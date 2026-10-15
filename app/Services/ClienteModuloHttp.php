<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class ClienteModuloHttp
{
    public function para(string $modulo, string $scope): PendingRequest
    {
        $c = match ($modulo) {
            'identidad_comunidad' => config('identidad_comunidad'),
            'inventario_identidad' => config('team4.identidad'),
            'marketplace_identity' => config('marketplace.identidad'),
            'marketplace_community' => config('marketplace.comunidad'),
            default => config('integracion_servicios.'.$modulo),
        };
        if (! $c || ! $c['url'] || ! $c['client_id'] || ! $c['client_secret']) {
            throw new RuntimeException('La conexión con '.$modulo.' no está configurada.');
        }
        $http = Http::baseUrl(rtrim($c['url'], '/'))->acceptJson()->connectTimeout(2)->timeout(10);
        $key = 'token-modulo:'.hash('sha256', $modulo.$c['url'].$c['client_id'].$c['client_secret'].$scope);
        $token = Cache::get($key);
        if (! $token) {
            $respuesta = $http->post('/api/oauth/token', ['grant_type' => 'client_credentials',
                'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'scope' => $scope])->throw()->json();
            $token = $respuesta['access_token'] ?? null;
            if (! is_string($token) || $token === '') { throw new RuntimeException('No se recibió un token de servicio válido.'); }
            Cache::put($key, $token, max(1, min(300, (int) ($respuesta['expires_in'] ?? 60) - 15)));
        }
        return $http->withToken($token);
    }
}
