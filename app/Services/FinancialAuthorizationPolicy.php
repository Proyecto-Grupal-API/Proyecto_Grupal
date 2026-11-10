<?php

namespace App\Services;

use App\Contracts\BonusAuthorization;
use App\Contracts\FinancialAuthorizationDecision;
use App\Contracts\FinancialContextValidator;
use App\Enums\FinancialContextValidation;
use App\Exceptions\FinancialAuthorizationUnavailable;
use Illuminate\Database\QueryException;
use MongoDB\Driver\Exception\Exception as MongoException;

/** Application policy above the unchanged exact-scope evaluator. No persistence or grants. */
final class FinancialAuthorizationPolicy implements FinancialAuthorizationDecision
{
    public function __construct(
        private readonly BonusAuthorization $exactScope,
        private readonly ?FinancialContextValidator $contexts = null,
    ) {}

    public function allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        if ($operation !== 'issue' || ! array_key_exists($bonusType, FinancialAuthorizationService::BONUS_CAPABILITIES)
            || ! $this->validIdentifier($subjectId) || (($scopeType === null) !== ($scopeId === null))) {
            return false;
        }

        if ($scopeType === null) {
            return $this->evaluate($subjectId, $bonusType, $operation);
        }

        // Council BECA is exact-only: a global or academic grant never substitutes it.
        if ($bonusType === 'BECA' && $scopeType === 'council') {
            return preg_match('/\A[0-9a-f]{24}\z/', (string) $scopeId) === 1
                && $this->evaluate($subjectId, $bonusType, $operation, $scopeType, $scopeId);
        }

        // BECA/program and CAMPANIA/campaign remain blocked, including for global grant holders.
        if ($bonusType !== 'NEGOCIO' || $scopeType !== 'business' || ! $this->validIdentifier($scopeId)) {
            return false;
        }
        if ($this->contexts === null) {
            throw new FinancialAuthorizationUnavailable;
        }

        try {
            $validation = $this->contexts->validate($scopeType, $scopeId);
        } catch (QueryException|MongoException $exception) {
            throw new FinancialAuthorizationUnavailable($exception);
        }
        if ($validation === FinancialContextValidation::Invalid) {
            return false;
        }
        if ($validation === FinancialContextValidation::Unavailable) {
            throw new FinancialAuthorizationUnavailable;
        }

        // Preserve the caller's context for the exact branch; never normalize it into a global request.
        return $this->evaluate($subjectId, $bonusType, $operation)
            || $this->evaluate($subjectId, $bonusType, $operation, $scopeType, $scopeId);
    }

    private function validIdentifier(?string $id): bool
    {
        return $id !== null && $id !== '' && strlen($id) <= 100 && ! preg_match('/[\x00-\x20\x7f]/', $id);
    }

    private function evaluate(string $subjectId, string $type, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        try {
            return $this->exactScope->allows($subjectId, $type, $operation, $scopeType, $scopeId);
        } catch (QueryException|MongoException $exception) {
            throw new FinancialAuthorizationUnavailable($exception);
        }
    }
}
