<?php

namespace App\Console\Commands;

use App\Domains\Financial\Data\BonusIssuerContext;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Domains\Financial\Integrations\Identity\IdentityFinancialAuthorizationClient;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class CheckIdentityBonusAuthorization extends Command
{
    protected $signature = 'financial:bonus-authorization-check
        {subject : ID canonico del sujeto, no del cliente OAuth}
        {type : BECA, CAMPANIA, BENEFICIO, CATEGORIA o NEGOCIO}
        {--scope-type= : Solo business para NEGOCIO; omitir ambos para global}
        {--scope-id= : ID canonico del negocio, nunca un ID inventado}';

    protected $description = 'Consultar capacidad tecnica de emision en E1; no emite bonos, no prueba consentimiento y no mueve dinero.';

    public function handle(IdentityFinancialAuthorizationClient $client): int
    {
        try {
            $type = BonusType::tryFrom((string) $this->argument('type'));
            if (!$type) throw new InvalidArgumentException('Tipo de bono no valido; usa el literal en mayusculas.');
            $scopeType = $this->option('scope-type');
            $scopeId = $this->option('scope-id');
            $context = ($scopeType !== null || $scopeId !== null) ? new BonusIssuerContext($scopeType, $scopeId) : null;
            $allowed = $client->check((string) $this->argument('subject'), $type, $context);
            $this->line(json_encode(['status' => 'EVALUATED', 'authorized' => $allowed,
                'subject_id' => strtolower((string) $this->argument('subject')), 'bonus_type' => $type->value,
                'operation' => 'issue', 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'human_consent_proven' => false, 'bonus_issued' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            // Evaluated false is a valid decision, not a broken diagnostic command.
            return self::SUCCESS;
        } catch (IdentityFinancialAuthorizationUnavailable $e) {
            $e->report();
            $this->line(json_encode(['status' => 'UNAVAILABLE', 'dependency_code' => $e->reasonCode,
                'upstream_http_status' => $e->upstreamStatus, 'bonus_issued' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return 2;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Fallo inesperado al consultar la autorizacion. No se emitio ningun bono.');
            return self::FAILURE;
        }
    }
}
