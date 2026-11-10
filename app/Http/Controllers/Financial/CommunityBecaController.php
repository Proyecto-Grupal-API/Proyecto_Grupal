<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusBudgetCommitment;
use App\Domains\Financial\Models\InstitutionalBonusBudget;
use App\Domains\Financial\Models\InstitutionalBudgetOperation;
use App\Domains\Financial\Services\InstitutionalBecaIssuanceService;
use App\Domains\Financial\Services\InstitutionalBudgetReadAccess;
use App\Domains\Financial\Services\WalletCheckoutConsent;
use App\Services\ClienteModuloHttp;
use App\Support\BecaFinanceContract;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CommunityBecaController
{
    public function review(Request $request, string $solicitud, WalletCheckoutConsent $consent)
    {
        [$award, $mapping, , $approval] = $this->authorizedAward($request, $solicitud);
        $budget = InstitutionalBonusBudget::where('public_id', $mapping['budget_id'])->firstOrFail();
        $fingerprint = BecaFinanceContract::fingerprint($award);
        $receipt = $this->proof($solicitud);

        if ($receipt !== null) {
            abort_unless(hash_equals($receipt['fingerprint'], $fingerprint), 409);

            return $this->response(['receipt' => $receipt]);
        }

        $this->requireTreasuryApproval($approval, $award, $mapping['budget_id']);
        abort_unless($budget->status === 'ACTIVE' && $budget->bonus_type === 'BECA' && $budget->currency === 'MXN'
            && now()->gte($budget->valid_from) && now()->lt($budget->expires_at), 409, 'El presupuesto no está vigente.');
        abort_unless(now()->lt($award['vigencia_fin_exclusiva']), 409, 'La vigencia de la beca ya terminó.');
        $available = $budget->approved_amount_cents - $budget->consumed_amount_cents - $budget->committed_amount_cents;

        return $this->response([
            'award' => $award, 'budget' => $budget->purpose, 'budget_version' => (string) $budget->version,
            'available_cents' => (string) $available, 'can_issue' => $available >= $award['monto_centavos'],
            'business_id' => $mapping['business_id'],
            'review_token' => $this->reviewToken($consent, $request, $solicitud, $fingerprint, $mapping, $budget->version),
        ]);
    }

    public function issue(Request $request, string $solicitud, WalletCheckoutConsent $consent, InstitutionalBecaIssuanceService $issuance)
    {
        abort_if(array_diff(array_keys($request->all()), ['budget_version', 'review_token', 'confirm']) !== [], 422);
        $input = $request->validate([
            'budget_version' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'review_token' => ['required', 'string', 'size:64'],
            'confirm' => ['required', 'accepted'],
        ]);
        [$award, $mapping, $actor, $approval] = $this->authorizedAward($request, $solicitud);
        $fingerprint = BecaFinanceContract::fingerprint($award);
        $version = (int) $input['budget_version'];
        abort_unless(hash_equals($this->reviewToken($consent, $request, $solicitud, $fingerprint, $mapping, $version), $input['review_token']),
            409, 'Vuelve a revisar los datos de la beca.');

        if ($this->proof($solicitud) === null) {
            $this->requireTreasuryApproval($approval, $award, $mapping['budget_id']);
        }
        $reference = BecaFinanceContract::reference($solicitud);
        $data = [
            'award_reference' => $reference, 'beneficiary_id' => $award['beneficiario_id'],
            'type' => 'BECA', 'amount_cents' => $award['monto_centavos'],
            'valid_from' => $award['vigencia_inicio'], 'expires_at' => $award['vigencia_fin_exclusiva'],
            'combinable' => false, 'allows_partial_use' => true,
            'external_reference' => $reference, 'reason' => 'Beca aprobada por Comunidad',
            'restrictions' => [['type' => 'NEGOCIO', 'target_id' => $mapping['business_id']]],
        ];
        try {
            $result = $issuance->issue($actor, $mapping['budget_id'], $version, $data, $reference, [
                'identity_session_id' => $request->session()->get('cd_session_id'),
                'native_session_hash' => hash('sha256', $request->session()->getId()),
            ], $fingerprint);
        } catch (InvalidArgumentException $exception) {
            abort(409, $exception->getMessage());
        }
        $receipt = $this->proof($solicitud);
        abort_unless($receipt, 503, 'Consulta la solicitud para recuperar el comprobante.');

        return $this->response(['receipt' => $receipt, 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function receipt(Request $request, string $solicitud)
    {
        BecaFinanceContract::authorizeService($request, 'e6');
        $proof = $this->proof($solicitud);
        abort_unless($proof !== null, 404);

        return $this->response($proof);
    }

    private function authorizedAward(Request $request, string $id): array
    {
        abort_unless(config('becas_finanzas.enabled') === true, 503);
        $actor = app(InstitutionalBudgetReadAccess::class)->actor($request);
        abort_unless(app(FinancialWebAuthorizer::class)->allows($actor, 'bonus.issue'), 403);
        try {
            $result = app(ClienteModuloHttp::class)->para('finanzas_becas_e2', BecaFinanceContract::COMMUNITY_SCOPE)
                ->get('/api/v1/comunidad/becas/'.$id.'/bono');
        } catch (\Exception) {
            abort(503, 'No se pudo consultar el dictamen en Comunidad.');
        }

        abort_if($result->notFound(), 404, 'La solicitud no tiene un bono aprobado en Comunidad.');
        abort_unless($result->ok(), 503, 'Comunidad no confirmó el dictamen.');
        $data = $result->json('data');
        $award = is_array($data) ? BecaFinanceContract::snapshot($data, $id) : null;
        abort_unless($award, 503, 'Comunidad devolvió una asignación inválida.');
        $mapping = BecaFinanceContract::budgetMapping($award);
        abort_unless($mapping, 409, 'La convocatoria no tiene presupuesto y negocio autorizados.');
        abort_unless(app(InstitutionalBudgetAuthorization::class)->allows($actor, 'budget.commit', $mapping['budget_id']), 403);

        return [$award, $mapping, $actor, $data['aval_tesoreria'] ?? null];
    }

    private function requireTreasuryApproval(mixed $approval, array $award, string $budgetId): void
    {
        abort_unless(BecaFinanceContract::treasuryApprovalMatches($approval, $award, $budgetId),
            409, 'Tesorería debe avalar este importe y presupuesto después de la aprobación de Presidencia.');
    }

    private function reviewToken(WalletCheckoutConsent $consent, Request $request, string $id, string $fingerprint, array $mapping, int $version): string
    {
        $review = hash('sha256', implode('|', [$fingerprint, $mapping['budget_id'], $mapping['business_id'], $version]));

        return $consent->reviewToken($request, BecaFinanceContract::reference($id), $review);
    }

    private function proof(string $id): ?array
    {
        $reference = BecaFinanceContract::reference($id);
        $link = BonusBudgetCommitment::where('award_reference', $reference)->first();
        if (! $link) {
            return null;
        }
        $op = InstitutionalBudgetOperation::where('public_id', $link->issue_operation_id)->first();
        $bonus = Bonus::where('public_id', $link->bonus_id)->first();
        $fingerprint = $op?->after_data['community_award_fingerprint'] ?? null;
        abort_unless($bonus && $bonus->type === BonusType::BECA && $bonus->external_reference === $reference
            && $op?->action === 'ISSUE_BECA' && $op->idempotency_key === $reference
            && ($op->after_data['award_reference'] ?? null) === $reference
            && ($op->after_data['bonus_id'] ?? null) === strtolower($bonus->public_id)
            && strcasecmp($op->budget_id, $link->budget_id) === 0
            && strcasecmp((string) $bonus->getRawOriginal('institutional_budget_id'), $link->budget_id) === 0
            && ($op->after_data['committed_delta_cents'] ?? null) === $link->amount_cents
            && $link->amount_cents === $bonus->original_amount_cents && $link->currency === 'MXN' && $bonus->currency === 'MXN'
            && is_string($fingerprint) && preg_match('/\A[0-9a-f]{64}\z/', $fingerprint), 409, 'La emisión requiere revisión.');

        return ['reference' => $reference, 'fingerprint' => $fingerprint,
            'bonus_id' => strtolower($bonus->public_id), 'operation_id' => strtolower($op->public_id)];
    }

    private function response(array $data, int $status = 200)
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }
}
