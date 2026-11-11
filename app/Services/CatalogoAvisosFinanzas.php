<?php

namespace App\Services;

use App\Exceptions\AvisoApiException;
use Illuminate\Support\Str;

class CatalogoAvisosFinanzas
{
    public const TIPOS = [
        'pago_evento_confirmado' => 'comprobante_financiero',
        'devolucion_confirmada' => 'comprobante_financiero',
        'beca_emitida' => 'bono_financiero',
    ];

    public const DESTINOS = [
        'comprobante_financiero' => ['/finanzas/comprobantes/transaction/', 'Ver comprobante'],
        'bono_financiero' => ['/finanzas/bonos/', 'Ver bono'],
    ];

    public static function esClienteFinanciero(string $cliente): bool
    {
        $esperado = config('comunidad.avisos_finanzas_client_id');

        return is_string($esperado) && $esperado !== '' && hash_equals($esperado, $cliente);
    }

    public function accion(string $tipo, array $accion): array
    {
        if ((self::TIPOS[$tipo] ?? null) !== ($accion['destino'] ?? null)
            || ! Str::isUuid($accion['id'] ?? '')) {
            throw new AvisoApiException(422, 'destino_invalido', 'El aviso requiere su comprobante o bono financiero canónico.');
        }
        [$url, $texto] = self::DESTINOS[$accion['destino']];

        return ['url' => $url.strtolower($accion['id']), 'texto' => $texto, 'obligatoria' => true];
    }
}
