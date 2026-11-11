<?php

namespace App\Services;

use App\Exceptions\AvisoApiException;

class CatalogoAvisosPlataforma
{
    public const TIPOS = [
        'puntos_acreditados' => 'mi_actividad_recompensas',
        'canje_confirmado' => 'mi_canje_recompensas',
        'canje_entregado' => 'mi_canje_recompensas',
        'canje_cancelado' => 'mi_canje_recompensas',
    ];

    public const DESTINOS = [
        'mi_actividad_recompensas' => ['/recompensas/alumno/actividad?movimiento=', 'Ver movimiento'],
        'mi_canje_recompensas' => ['/recompensas/alumno/canjes?canje=', 'Ver canje'],
    ];

    public static function esClientePlataforma(string $cliente): bool
    {
        $esperado = config('platform.notices.client_id');

        return is_string($esperado) && $esperado !== '' && hash_equals($esperado, $cliente);
    }

    public function accion(string $tipo, array $accion): array
    {
        if ((self::TIPOS[$tipo] ?? null) !== ($accion['destino'] ?? null)
            || ! is_string($accion['id'] ?? null) || ! preg_match('/\A[0-9a-f]{24}\z/', $accion['id'])) {
            throw new AvisoApiException(422, 'destino_invalido', 'El aviso requiere la referencia canónica del movimiento o canje.');
        }
        [$url, $texto] = self::DESTINOS[$accion['destino']];

        return ['url' => $url.$accion['id'], 'texto' => $texto, 'obligatoria' => true];
    }
}
