<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Fields shared by the approved award and the receipt from Finanzas. */
final class BecaFinanceContract
{
    public const COMMUNITY_SCOPE = 'community:beca-award:read';
    public const FINANCIAL_SCOPE = 'financial:beca-issuance:read';
    public const ID_PATTERN = '[a-f0-9]{24}';

    public static function reference(string $id): string
    {
        return 'beca:'.$id;
    }

    public static function snapshot(array $data, string $id): ?array
    {
        $validator = Validator::make($data, [
            'solicitud_id' => ['required', 'in:'.$id],
            'beneficiario_id' => ['required', 'regex:/\A'.self::ID_PATTERN.'\z/'],
            'organizacion_id' => ['required', 'regex:/\A'.self::ID_PATTERN.'\z/'],
            'convocatoria_id' => ['required', 'regex:/\A'.self::ID_PATTERN.'\z/'],
            'monto_centavos' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'moneda' => ['required', 'in:MXN'],
            'vigencia_inicio' => ['required', 'string', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'vigencia_fin_exclusiva' => ['required', 'string', 'date', 'after:vigencia_inicio', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
        ]);
        if ($validator->fails() || ! is_int($data['monto_centavos'] ?? null)) {
            return null;
        }

        // Validated fields only, in a stable order and with the original ISO strings.
        return $validator->validated();
    }

    public static function fingerprint(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function authorizeService(Request $request, string $consumer): void
    {
        abort_unless(config('becas_finanzas.enabled') === true, 503);
        $id = config('becas_finanzas.'.$consumer.'.client_id');
        abort_unless(is_string($id) && $id !== '' && $id === $request->attributes->get('oauth_client_id'), 403);
    }
}
