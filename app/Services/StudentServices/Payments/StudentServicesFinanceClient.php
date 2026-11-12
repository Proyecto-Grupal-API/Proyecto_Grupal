<?php

namespace App\Services\StudentServices\Payments;

use App\Services\ClienteModuloHttp;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente de la API de Finanzas para los cobros de Servicios al Estudiante:
 * pago de locker (locker_rental) y de impresiones (print_order). El alumno
 * autoriza cada uno en Finanzas; aquí solo se prepara, consulta y cancela.
 *
 * Toda respuesta se valida contra el registro local (concepto, origen,
 * alumno e importe) antes de usarla.
 */
class StudentServicesFinanceClient
{
    public const CONCEPTS = ['locker_rental', 'print_order'];

    private const CHECKOUT_PATH = '~\A/finanzas/servicios-estudiante/solicitudes/[0-9a-f-]{36}\z~';

    public function __construct(private readonly ClienteModuloHttp $http) {}

    public function enabled(): bool
    {
        return config('integracion_servicios.finanzas_servicios.enabled') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(string $concept, string $sourceId, string $studentId, int $amountCents): array
    {
        $body = ['student_id' => $studentId, 'amount_cents' => $amountCents, 'currency' => 'MXN'];

        return $this->expect($concept, $sourceId, $studentId, $amountCents,
            $this->send('prepare', 'post', $this->paymentPath($concept, $sourceId), $body));
    }

    /**
     * @return array<string, mixed>|null null si Finanzas no conoce la solicitud
     */
    public function lookup(string $concept, string $sourceId, string $studentId, int $amountCents): ?array
    {
        $data = $this->send('read', 'get', $this->paymentPath($concept, $sourceId));

        return $data === null ? null : $this->expect($concept, $sourceId, $studentId, $amountCents, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $concept, string $sourceId, string $studentId, int $amountCents): array
    {
        return $this->expect($concept, $sourceId, $studentId, $amountCents,
            $this->send('cancel', 'post', $this->paymentPath($concept, $sourceId).'/cancel', []), allowEmptyCancel: true);
    }

    /**
     * Ruta de la pantalla de Finanzas donde el alumno autoriza, ya validada.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkoutPath(array $data): string
    {
        $path = $data['confirmation_path'] ?? null;

        if (($data['status'] ?? null) !== 'PENDING' || ! is_string($path) || preg_match(self::CHECKOUT_PATH, $path) !== 1
            || ! Str::isUuid(basename($path))) {
            throw new PaymentException('Finanzas no devolvió una solicitud de pago válida.');
        }

        return $path;
    }

    private function paymentPath(string $concept, string $sourceId): string
    {
        if (! in_array($concept, self::CONCEPTS, true) || preg_match('/\A[a-f0-9]{24}\z/', $sourceId) !== 1) {
            throw new PaymentException('Cobro de Servicios no válido.');
        }

        return '/api/v1/financial/student-services/payments/'.$concept.'/'.$sourceId;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function send(string $scope, string $method, string $path, array $body = []): ?array
    {
        if (! $this->enabled()) {
            throw new PaymentException('Los cobros de Servicios con Finanzas no están habilitados.');
        }

        try {
            $client = $this->http->para('finanzas_servicios', 'financial:student-service:'.$scope);
            $response = $method === 'get' ? $client->get($path) : $client->post($path, $body);
        } catch (HttpClientException|RuntimeException) {
            throw new PaymentException('Finanzas no está disponible. Intenta consultar el pago nuevamente.');
        }

        if ($method === 'get' && $response->notFound()) {
            return null;
        }
        if ($response->conflict()) {
            $message = $response->json('message');

            throw new PaymentException(is_string($message) && $message !== '' ? 'Finanzas: '.$message : 'Finanzas tiene una solicitud incompatible o cerrada para este registro.');
        }
        if (! $response->successful()) {
            throw new PaymentException('Finanzas no pudo resolver la solicitud. Intenta nuevamente.');
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new PaymentException('Finanzas no devolvió un resultado válido.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function expect(string $concept, string $sourceId, string $studentId, int $amountCents, array $data, bool $allowEmptyCancel = false): array
    {
        $status = $data['status'] ?? null;
        $matches = ($data['student_id'] ?? null) === $studentId && ($data['amount_cents'] ?? null) === $amountCents;
        // Una cancelación que llegó antes de preparar no trae alumno ni importe.
        $cancelledEmpty = $allowEmptyCancel && $status === 'CANCELLED'
            && ($data['student_id'] ?? null) === null && ($data['amount_cents'] ?? null) === null;
        $proofOk = $status === 'CONFIRMED'
            ? Str::isUuid((string) ($data['payment_id'] ?? ''))
            : ($data['payment_id'] ?? null) === null;

        if (($data['concept'] ?? null) !== $concept || ($data['source_reference'] ?? null) !== $sourceId
            || ($data['currency'] ?? null) !== 'MXN' || (! $matches && ! $cancelledEmpty)
            || ! in_array($status, ['PENDING', 'CONFIRMED', 'CANCELLED'], true) || ! $proofOk) {
            throw new PaymentException('La respuesta de Finanzas no coincide con este registro.');
        }

        return $data;
    }
}
