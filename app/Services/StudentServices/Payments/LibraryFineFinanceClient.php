<?php

namespace App\Services\StudentServices\Payments;

use App\Models\StudentServices\Library\LibraryFine;
use App\Services\ClienteModuloHttp;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Str;
use RuntimeException;

class LibraryFineFinanceClient
{
    public function __construct(private readonly ClienteModuloHttp $http) {}

    public function enabled(): bool
    {
        return config('integracion_servicios.finanzas_multas.enabled') === true;
    }

    public function prepare(LibraryFine $fine): array
    {
        return $this->request($fine, 'prepare', [
            'student_id' => $fine->student_id, 'amount_cents' => $fine->amount_cents, 'currency' => 'MXN',
        ]);
    }

    public function lookup(LibraryFine $fine): ?array
    {
        return $this->request($fine, 'read');
    }

    public function cancel(LibraryFine $fine): array
    {
        return $this->request($fine, 'cancel');
    }

    private function request(LibraryFine $fine, string $action, array $body = []): ?array
    {
        if (! $this->enabled()) {
            throw new PaymentException('La conexión de multas con Finanzas no está habilitada.');
        }

        $path = '/api/v1/financial/student-services/library-fines/'.$fine->id;
        try {
            $client = $this->http->para('finanzas_multas', 'financial:library-fine:'.$action);
            $response = match ($action) {
                'prepare' => $client->post($path, $body),
                'cancel' => $client->post($path.'/cancel', []),
                'read' => $client->get($path),
            };
        } catch (HttpClientException|RuntimeException) {
            throw new PaymentException('Finanzas no está disponible. Intenta consultar el pago nuevamente.');
        }

        if ($action === 'read' && $response->notFound()) {
            return null;
        }
        if ($response->conflict()) {
            throw new PaymentException('Finanzas ya tiene una solicitud incompatible o cerrada para esta multa.');
        }
        if (! $response->successful()) {
            throw new PaymentException('Finanzas no pudo resolver la solicitud. Intenta nuevamente.');
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new PaymentException('Finanzas no devolvió un resultado de pago válido.');
        }

        $status = $data['status'] ?? null;
        $paymentId = $data['payment_id'] ?? null;
        $matchesFine = ($data['student_id'] ?? null) === $fine->student_id && ($data['amount_cents'] ?? null) === $fine->amount_cents;
        $cancelledBeforePrepare = $status === 'CANCELLED' && ($data['student_id'] ?? null) === null && ($data['amount_cents'] ?? null) === null;
        if (($data['source_reference'] ?? null) !== (string) $fine->id
            || ($data['currency'] ?? null) !== 'MXN' || ($data['concept'] ?? null) !== 'library_fine'
            || (! $matchesFine && ! $cancelledBeforePrepare)
            || ! in_array($status, ['PENDING', 'CONFIRMED', 'CANCELLED'], true)
            || ($status === 'CONFIRMED' ? (! is_string($paymentId) || ! Str::isUuid($paymentId)) : $paymentId !== null)) {
            throw new PaymentException('La respuesta de Finanzas no coincide con esta multa.');
        }

        return $data;
    }
}
