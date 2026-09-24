<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IdentidadComunidadApi
{
    public function estado(string $usuarioId): array
    {
        $config = config('identidad_comunidad');
        if (! $config['url'] || ! $config['client_id'] || ! $config['client_secret']) {
            throw new RuntimeException('La API de Identidad aún no está configurada.');
        }
        $http = Http::baseUrl(rtrim($config['url'], '/'))->acceptJson()->connectTimeout(2)->timeout(5);
        $token = $http->post('/api/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'], 'scope' => 'students:read',
        ])->throw()->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Identidad no devolvió un token válido.');
        }
        $data = $http->withToken($token)->get('/api/v1/students/'.rawurlencode($usuarioId).'/status')->throw()->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('Respuesta de Identidad inválida.');
        }
        return $data;
    }
}
