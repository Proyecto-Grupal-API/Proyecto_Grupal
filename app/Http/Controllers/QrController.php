<?php

namespace App\Http\Controllers;

use App\Models\QrValidation;
use App\Services\IdentityService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Modulo 1.6 - Identidad QR.
 *
 * Expone:
 *  - Pantalla del QR fijo de identificacion + QR dinamico rotativo,
 *    con su codigo corto de respaldo para captura manual.
 *  - Endpoint para generar/rotar el token dinamico.
 *  - Endpoint de "validacion" que simula el contrato consumido por
 *    otros equipos (2, 5, 6) cuando escanean el QR de un estudiante.
 */
class QrController extends Controller
{
    private const HISTORY_PAGE_SIZE = 15;

    public function __construct(private IdentityService $identity)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $identificationToken = $this->identity->issueIdentificationQrToken($user);

        return Inertia::render('Security/QrIdentity', [
            'canValidate' => $request->user()->can('validate', QrValidation::class),
            'ttlSeconds' => (int) env('QR_IDENTITY_TTL_SECONDS', 30),
            'identificationPayload' => $this->identity->buildQrPayload($identificationToken),
            'recentValidations' => $this->mapValidations(
                $this->historyQuery($user)->limit(10)->get()
            ),
            'historyPageSize' => self::HISTORY_PAGE_SIZE,
        ]);
    }

    public function generate(Request $request)
    {
        $token = $this->identity->issueDynamicQrToken(
            $request->user(),
            $request->input('purpose') ?: null
        );

        return response()->json([
            'code' => $token->presentedCode,
            'short_code' => $token->shortCode,
            'qr_payload' => $this->identity->buildQrPayload($token),
            'expires_at' => $token->token->expires_at->toIso8601String(),
            'seconds_remaining' => $token->token->secondsRemaining(),
            'ttl_seconds' => (int) env('QR_IDENTITY_TTL_SECONDS', 30),
        ]);
    }

    /**
     * Historial paginado (scroll "cargar más") de validaciones sobre
     * los QR de este estudiante.
     */
    public function history(Request $request)
    {
        $offset = max(0, (int) $request->query('offset', 0));

        $items = $this->historyQuery($request->user())
            ->skip($offset)
            ->limit(self::HISTORY_PAGE_SIZE)
            ->get();

        return response()->json([
            'items' => $this->mapValidations($items),
            'next_offset' => $offset + self::HISTORY_PAGE_SIZE,
            'has_more' => $items->count() === self::HISTORY_PAGE_SIZE,
        ]);
    }

    /**
     * Simulador del contrato /api/v1/identity/qr-validate: permite
     * demostrar como otro dominio (biblioteca, evento, caja de
     * asociacion) validaria el QR de un estudiante. Acepta el codigo
     * pelado, el codigo corto de respaldo, o el payload completo (con
     * firma) tal como saldria de escanear el QR.
     */
    public function simulateValidation(Request $request)
    {
        $this->authorize('validate', QrValidation::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'context' => ['nullable', 'string', 'max:150'],
        ]);

        $result = $this->identity->validateQrCode(
            input: $data['code'],
            validatedBy: $request->user(),
            context: $data['context'] ?? 'simulador-interno',
            ip: $request->ip(),
            validatorLabel: $request->user()->name,
        );

        return response()->json($result);
    }

    private function historyQuery($user)
    {
        return QrValidation::where('user_id', (string) $user->_id)
            ->orderByDesc('created_at');
    }

    private function mapValidations($items)
    {
        return $items->map(fn (QrValidation $v) => [
            'id' => (string) $v->_id,
            'result' => $v->result,
            'context' => $v->context,
            'validator_label' => $v->validator_label,
            'ip_address' => $v->ip_address,
            'created_at' => optional($v->created_at)->diffForHumans(),
        ]);
    }
}
