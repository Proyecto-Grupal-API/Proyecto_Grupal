<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\IdentityProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Exceptions\TransferRecipientUnavailableException;
use App\Domains\Financial\Support\FinancialCorrelation;
use App\Domains\Financial\Contracts\TransferAccountProvider;
use App\Exceptions\DirectoryFailure;
use App\Services\Directory\DirectoryInput;
use App\Services\Directory\IdentityDirectoryService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class Module1TransferRecipientAdapter implements TransferRecipientProvider
{
    public function __construct(
        private readonly IdentityProvider $identity,
        private readonly IdentityDirectoryService $directory,
        private readonly TransferAccountProvider $accounts,
    ) {}

    public function resolve(string $method, string $value, string $actorId, ?string $ip): array
    {
        try {
            return $this->resolveAvailable($method, $value, $actorId, $ip);
        } catch (DirectoryFailure $e) {
            if ($e->status === 409) {
                throw new InvalidArgumentException('No se pudo identificar un destinatario único.');
            }
            if ($e->status === 422) {
                throw new InvalidArgumentException('Matrícula no válida.');
            }
            // FIN-07: distinct cause for the operator, technical code and correlation for support; never the queried value.
            $failure = TransferRecipientUnavailableException::fromDirectory($e, app(FinancialCorrelation::class)->id());
            Log::warning('financial.transfer_recipient.directory_failure', ['code' => $failure->reasonCode, 'status' => $e->status,
                'method' => $method, 'correlation_id' => $failure->correlationId]);

            throw $failure;
        } catch (InvalidArgumentException|FinancialDependencyUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FinancialDependencyUnavailableException('La consulta del destinatario no está disponible.', 0, $e);
        }
    }

    private function resolveAvailable(string $method, string $value, string $actorId, ?string $ip): array
    {
        $value = trim($value);
        $id = null;
        $projection = null;
        $fields = ['projection' => ['identity'], 'include_enrollment' => true];
        if ($method === 'USER_ID') {
            if (! preg_match('/^[a-f0-9]{24}$/i', $value)) {
                throw new InvalidArgumentException('Identificador de usuario no válido.');
            }
            $id = strtolower($value);
        } elseif ($method === 'ENROLLMENT') {
            $enrollment = DirectoryInput::enrollment($value);
            if ($enrollment === null) {
                throw new InvalidArgumentException('Matrícula no válida.');
            }
            $result = $this->directory->resolve($fields + ['enrollment_number' => $enrollment]);
            if (in_array($result['result'] ?? null, ['not_found', 'not_usable'], true)) {
                // Same answer for both: the directory never reveals why an account cannot receive.
                throw new InvalidArgumentException('No hay un destinatario disponible con esa matrícula.');
            }
            $projection = $result['identity'] ?? null;
            $id = $projection['user_id'] ?? null;
        } elseif ($method === 'QR') {
            if ($value === '' || strlen($value) > 500) {
                throw new InvalidArgumentException('Código QR no válido.');
            }
            try {
                $result = $this->identity->validateQr($value, $actorId, 'FINANCIAL_TRANSFER_RECIPIENT', $ip);
            } catch (Throwable $e) {
                report($e);
                throw new FinancialDependencyUnavailableException('La validación QR de identidad no está disponible.');
            }
            if (($result['ok'] ?? false) !== true) {
                throw new InvalidArgumentException('El QR no es válido o ya no está vigente.');
            }
            $identity = $result['identity'] ?? null;
            $id = is_array($identity) ? ($identity['user_id'] ?? $identity['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[a-f0-9]{24}$/i', $id)) {
                throw new FinancialDependencyUnavailableException('El servicio de identidad no resolvió un identificador de usuario compatible.');
            }
        } else {
            throw new InvalidArgumentException('Método de identificación no válido.');
        }
        if (! preg_match('/^[a-f0-9]{24}$/i', (string) $id)) {
            throw new InvalidArgumentException('No se pudo identificar el destinatario.');
        }
        $id = strtolower($id);
        $this->accounts->assertActive($id);
        if ($projection === null) {
            $item = $this->directory->batch($fields + ['user_ids' => [$id]])['items'][0];
            $projection = $item['identity'] ?? null;
        }
        if ($projection === null || $projection['user_id'] !== $id) {
            throw new FinancialDependencyUnavailableException('Identidad no devolvió un destinatario inequívoco.');
        }

        return ['user_id' => $id, 'name' => $projection['display_name'],
            'enrollment_number' => $projection['enrollment_number']];
    }
}
