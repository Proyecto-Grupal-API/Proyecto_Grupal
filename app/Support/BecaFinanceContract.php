<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

    public static function budgetMapping(array $snapshot): ?array
    {
        $mapping = config('becas_finanzas.convocatorias', [])[$snapshot['convocatoria_id']] ?? null;
        if (! is_array($mapping) || ($mapping['organization_id'] ?? null) !== $snapshot['organizacion_id']
            || ! Str::isUuid($mapping['budget_id'] ?? null) || ! is_string($mapping['business_id'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $mapping['business_id'])) {
            return null;
        }

        return ['budget_id' => strtolower($mapping['budget_id']), 'business_id' => $mapping['business_id']];
    }

    /** Explicit server configuration owned by E2; never request input or academic inference. */
    public static function councilBudgetMatches(string $councilId, string $budgetId): bool
    {
        $mappings = config('becas_finanzas.convocatorias', []);
        if (! is_array($mappings)) return false;
        foreach ($mappings as $mapping) {
            if (is_array($mapping) && ($mapping['organization_id'] ?? null) === $councilId
                && ($mapping['budget_id'] ?? null) === $budgetId) {
                return true;
            }
        }

        return false;
    }

    public static function treasuryApprovalMatches(mixed $approval, array $snapshot, string $budgetId): bool
    {
        return is_array($approval)
            && ($approval['presidencia_id'] ?? null) !== ($approval['tesoreria_id'] ?? null)
            && self::isOtherActor($approval['presidencia_id'] ?? null, $snapshot)
            && self::isOtherActor($approval['tesoreria_id'] ?? null, $snapshot)
            && ($approval['monto_centavos'] ?? null) === $snapshot['monto_centavos']
            && ($approval['presupuesto_id'] ?? null) === $budgetId
            && ($approval['huella_dictamen'] ?? null) === self::fingerprint($snapshot);
    }

    private static function isOtherActor(mixed $actor, array $snapshot): bool
    {
        return is_string($actor) && $actor !== $snapshot['beneficiario_id']
            && preg_match('/\A'.self::ID_PATTERN.'\z/', $actor) === 1;
    }

    public static function authorizeService(Request $request, string $consumer): void
    {
        abort_unless(config('becas_finanzas.enabled') === true, 503);
        $id = config('becas_finanzas.'.$consumer.'.client_id');
        abort_unless(is_string($id) && $id !== '' && $id === $request->attributes->get('oauth_client_id'), 403);
    }
}
