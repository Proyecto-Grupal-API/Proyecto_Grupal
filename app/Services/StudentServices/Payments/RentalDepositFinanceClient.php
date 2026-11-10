<?php

namespace App\Services\StudentServices\Payments;

use App\Services\ClienteModuloHttp;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Str;
use RuntimeException;

final class RentalDepositFinanceClient
{
    public function __construct(private readonly ClienteModuloHttp $http) {}

    public function hold(string $rentalId, string $studentId, int $amountCents, CarbonInterface $expiresAt): array
    {
        $receipt = $this->request($rentalId, 'hold', '', [
            'student_id' => $studentId,
            'amount_cents' => $amountCents,
            'expires_at' => $expiresAt->avoidMutation()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        return $this->validateHold($receipt, $studentId, $amountCents, $expiresAt);
    }

    public function lookup(string $rentalId, string $studentId, int $amountCents, CarbonInterface $expiresAt): array
    {
        return $this->validateHold($this->request($rentalId, 'hold', '', null), $studentId, $amountCents, $expiresAt);
    }

    private function validateHold(array $receipt, string $studentId, int $amountCents, CarbonInterface $expiresAt): array
    {
        if (($receipt['student_id'] ?? null) !== $studentId || ($receipt['amount_cents'] ?? null) !== $amountCents
            || ($receipt['currency'] ?? null) !== 'MXN'
            || ($receipt['expires_at'] ?? null) !== $expiresAt->avoidMutation()->utc()->format('Y-m-d\TH:i:s\Z')) {
            throw new PaymentException('La retención confirmada no coincide con los datos de la renta.');
        }

        return $receipt;
    }

    public function settle(string $rentalId, string $holdId, string $condition, int $chargedCents, int $depositCents): array
    {
        if (! Str::isUuid($holdId) || $chargedCents < 0 || $chargedCents > $depositCents) {
            throw new PaymentException('Los datos del depósito son inválidos.');
        }
        $receipt = $this->request($rentalId, 'settle', '/'.$holdId.'/settle', [
            'condition' => $condition,
            'charged_cents' => $chargedCents,
        ]);
        $statuses = $chargedCents > 0 ? ['CAPTURADA'] : ['LIBERADA', 'EXPIRADA'];
        if ($receipt['hold_id'] !== strtolower($holdId)
            || ! in_array($receipt['status'] ?? null, $statuses, true)
            || ! Str::isUuid($receipt['transaction_id'] ?? null)
            || ($receipt['charged_cents'] ?? null) !== $chargedCents
            || ($receipt['released_cents'] ?? null) !== $depositCents - $chargedCents) {
            throw new PaymentException('La liquidación confirmada no coincide con la garantía.');
        }

        return $receipt;
    }

    private function request(string $rentalId, string $operation, string $suffix, ?array $body): array
    {
        if (! preg_match('/^[a-f0-9]{24}$/D', $rentalId)) {
            throw new PaymentException('La referencia de la renta es inválida.');
        }
        try {
            $client = $this->http->para('finanzas_garantias', 'financial:rental-deposit:'.$operation);
            $path = '/api/v1/financial/student-services/rentals/'.$rentalId.'/deposit'.$suffix;
            $response = $body === null
                ? $client->get($path)
                : $client->withHeader('Idempotency-Key', 'rental:'.$rentalId.':deposit-'.$operation)->post($path, $body);
        } catch (HttpClientException|RuntimeException) {
            throw new PaymentException('No se pudo comprobar la garantía. Reintenta con la misma referencia.');
        }

        if ($response->conflict()) {
            throw new PaymentException('La garantía ya está cerrada o no coincide con la solicitud original.');
        }
        $receipt = $response->json('data');
        if (! $response->successful() || ! is_array($receipt) || ($receipt['rental_id'] ?? null) !== $rentalId
            || ! Str::isUuid($receipt['hold_id'] ?? null)) {
            throw new PaymentException('Finanzas no confirmó la operación de garantía.');
        }

        return $receipt;
    }
}
