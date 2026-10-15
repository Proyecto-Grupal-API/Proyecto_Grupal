<?php
namespace App\Services;

use RuntimeException;

class IdentidadComunidadApi
{
    public function estado(string $usuarioId): array
    {
        $data = app(ClienteModuloHttp::class)->para('identidad_comunidad', 'students:read')
            ->get('/api/v1/students/'.rawurlencode($usuarioId).'/status')->throw()->json('data');
        if (! is_array($data)) { throw new RuntimeException('Respuesta de Identidad inválida.'); }
        return $data;
    }
}
