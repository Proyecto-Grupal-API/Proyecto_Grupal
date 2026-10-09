<?php

namespace App\Services\StudentServices\Benefits;

use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use App\Services\StudentServices\Services\ServiceOrderService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Throwable;

/**
 * Beneficios de servicio becados (REQ-M6-E5-001).
 *
 * Convierte una beca de servicio aprobada por Comunidad (Equipo 6) en un
 * recurso real del Equipo 5:
 *  - locker: asignación de locker tipo "scholarship" en el periodo activo
 *    (reutiliza LockerAssignmentService, cuyo apartado del locker es
 *    atómico: dos beneficiarios nunca obtienen el mismo locker).
 *  - impresiones: saldo de páginas que se consume al pagar órdenes de
 *    impresión del módulo 5.8.
 *
 * Idempotencia: la clave es única por cliente (índice único). Repetir la
 * clave con el mismo contenido devuelve la misma asignación; con
 * contenido distinto, conflicto. Un rechazo (sin disponibilidad, etc.)
 * no deja registro, así que reintentar más tarde con la misma clave
 * vuelve a evaluar la solicitud.
 */
class ServiceBenefitService
{
    private const LOCKER_CLAIM_ATTEMPTS = 5;

    private const LOCKER_TAKEN_MESSAGE = 'El locker ya no está disponible.';

    private bool $indexesReady = false;

    public function __construct(
        private BenefitCatalog $catalog,
        private StudentDirectory $students,
        private LockerAssignmentService $lockers,
        private ServiceOrderService $serviceOrders,
        private ServiceAuditor $auditor
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{assignment: BenefitAssignment, created: bool}
     *
     * @throws BenefitException
     */
    public function assign(array $input, string $idempotencyKey, string $clientId): array
    {
        $this->ensureIndexes();

        $request = $this->normalize($input);
        $hash = hash('sha256', json_encode($request['hash_source'], JSON_THROW_ON_ERROR));

        $existing = $this->findByKey($clientId, $idempotencyKey);

        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        $this->assertNotAlreadyAssigned($clientId, $request, $idempotencyKey);
        $this->assertEligible($request);

        try {
            $assignment = BenefitAssignment::create([
                'folio' => $this->newFolio('BEN'),
                'client_id' => $clientId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'benefit' => $request['benefit'],
                'student_id' => $request['student_id'],
                'organization_id' => $request['organization_id'],
                'call_id' => $request['call_id'],
                'application_id' => $request['application_id'],
                'origin_folio' => $request['origin_folio'],
                'quantity' => $request['quantity'],
                'consumed' => 0,
                'remaining' => $request['quantity'],
                'valid_from' => $request['valid_from'],
                'valid_until' => $request['valid_until'],
                'status' => 'processing',
                'consumptions' => [],
                'cancellation' => null,
            ]);
        } catch (Throwable $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $winner = $this->findByKey($clientId, $idempotencyKey);

            if ($winner === null) {
                throw $exception;
            }

            return $this->replay($winner, $hash);
        }

        try {
            if ($request['benefit'] === BenefitAssignment::BENEFIT_LOCKER) {
                $this->fulfillLocker($assignment, $request, $clientId);
            } else {
                $assignment->update(['status' => 'active']);
            }
        } catch (Throwable $exception) {
            $assignment->delete();

            throw $exception;
        }

        $assignment = $assignment->fresh();

        $this->auditor->record(
            'benefit.assignment.created',
            'benefit_assignment',
            (string) $assignment->id,
            null,
            ['benefit' => $assignment->benefit, 'student_id' => $assignment->student_id, 'quantity' => $assignment->quantity, 'status' => $assignment->status],
            'Beca '.($assignment->folio ?? $assignment->application_id ?? ''),
            'api:'.$clientId
        );

        return ['assignment' => $assignment, 'created' => true];
    }

    /**
     * @return array{assignment: BenefitAssignment, replayed: bool}
     *
     * @throws BenefitException
     */
    public function cancel(
        string $assignmentId,
        string $idempotencyKey,
        string $reason,
        ?string $originReference,
        string $clientId
    ): array {
        $assignment = $this->find($assignmentId, $clientId)
            ?? throw new BenefitException('no_encontrada', 'La asignación no existe para este cliente.', 404);

        $cancellation = $assignment->cancellation;

        if (is_array($cancellation)) {
            if (($cancellation['idempotency_key'] ?? null) === $idempotencyKey) {
                return ['assignment' => $assignment, 'replayed' => true];
            }

            throw new BenefitException('ya_cancelada', 'La asignación ya fue cancelada con otra solicitud.', 409, [
                'cancelacion_id' => $cancellation['cancellation_id'] ?? null,
            ]);
        }

        $record = [
            'cancellation_id' => $this->newFolio('CAN'),
            'idempotency_key' => $idempotencyKey,
            'reason' => $reason,
            'origin_reference' => $originReference,
            'cancelled_at' => now()->toIso8601String(),
            'released' => false,
            'released_quantity' => 0,
        ];

        if ($assignment->benefit === BenefitAssignment::BENEFIT_LOCKER) {
            $lockerAssignment = $this->lockerAssignmentOf($assignment);

            if ($lockerAssignment === null || $lockerAssignment->status !== 'active') {
                throw new BenefitException(
                    'no_cancelable',
                    'La asignación de locker ya terminó; no hay recurso que liberar.',
                    409,
                    ['estado_locker' => $lockerAssignment?->status]
                );
            }

            try {
                $this->lockers->release($lockerAssignment, 'Cancelación de beca: '.$reason, 'api:'.$clientId);
            } catch (RuntimeException $exception) {
                throw new BenefitException('no_cancelable', $exception->getMessage(), 409);
            }

            $record['released'] = true;
            $record['released_quantity'] = 1;

            $assignment->update(['status' => 'cancelled', 'cancellation' => $record]);

            $this->auditCancellation($assignment, $reason, $clientId);

            return ['assignment' => $assignment->fresh(), 'replayed' => false];
        }

        $remaining = (int) $assignment->remaining;

        if ($remaining <= 0) {
            throw new BenefitException('no_cancelable', 'El saldo de impresiones ya se consumió por completo.', 409);
        }

        if ($assignment->valid_until->lessThanOrEqualTo(now())) {
            throw new BenefitException('no_cancelable', 'La vigencia del saldo de impresiones ya terminó.', 409);
        }

        $record['released'] = true;
        $record['released_quantity'] = $remaining;

        $updated = BenefitAssignment::query()
            ->where('_id', new ObjectId((string) $assignment->id))
            ->where('status', 'active')
            ->where('remaining', $remaining)
            ->update(['status' => 'cancelled', 'remaining' => 0, 'cancellation' => $record]);

        if ($updated === 0) {
            throw new BenefitException(
                'conflicto_concurrente',
                'El saldo cambió mientras se cancelaba (se registró un consumo). Consulta la asignación y vuelve a intentar.',
                409
            );
        }

        $this->auditCancellation($assignment, $reason, $clientId);

        return ['assignment' => $assignment->fresh(), 'replayed' => false];
    }

    private function auditCancellation(BenefitAssignment $assignment, string $reason, string $clientId): void
    {
        $this->auditor->record(
            'benefit.assignment.cancelled',
            'benefit_assignment',
            (string) $assignment->id,
            ['status' => 'active', 'benefit' => $assignment->benefit, 'student_id' => $assignment->student_id],
            ['status' => 'cancelled'],
            $reason,
            'api:'.$clientId
        );
    }

    public function find(string $assignmentId, string $clientId): ?BenefitAssignment
    {
        if (preg_match('/^[a-f0-9]{24}$/i', $assignmentId) !== 1) {
            return null;
        }

        return BenefitAssignment::query()
            ->where('_id', new ObjectId(strtolower($assignmentId)))
            ->where('client_id', $clientId)
            ->where('status', '!=', 'processing')
            ->first();
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return array{items: list<BenefitAssignment>, next_cursor: string|null}
     */
    public function search(array $filters, string $clientId, ?string $cursor, int $limit): array
    {
        $query = BenefitAssignment::query()
            ->where('client_id', $clientId)
            ->where('status', '!=', 'processing');

        $map = [
            'clave_idempotencia' => 'idempotency_key',
            'solicitud_id' => 'application_id',
            'convocatoria_id' => 'call_id',
            'beneficiario_id' => 'student_id',
            'organizacion_id' => 'organization_id',
            'beneficio_id' => 'benefit',
        ];

        foreach ($map as $filter => $field) {
            if (($filters[$filter] ?? null) !== null && $filters[$filter] !== '') {
                $query->where($field, $filters[$filter]);
            }
        }

        $cursorId = $cursor !== null ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;

        if (is_string($cursorId) && preg_match('/^[a-f0-9]{24}$/', $cursorId) === 1) {
            $query->where('_id', '<', new ObjectId($cursorId));
        }

        $items = $query->orderBy('_id', 'desc')->limit($limit + 1)->get();

        $next = null;

        if ($items->count() > $limit) {
            $items = $items->take($limit);
            $next = rtrim(strtr(base64_encode((string) $items->last()->id), '+/', '-_'), '=');
        }

        return ['items' => array_values($items->all()), 'next_cursor' => $next];
    }

    /**
     * Representación pública (contrato v1 acordado con el Equipo 6).
     *
     * @return array<string, mixed>
     */
    public function present(BenefitAssignment $assignment): array
    {
        $cancellation = is_array($assignment->cancellation) ? $assignment->cancellation : null;
        $isLocker = $assignment->benefit === BenefitAssignment::BENEFIT_LOCKER;

        $evidence = [
            'asignado_en' => $assignment->created_at?->toIso8601String(),
        ];

        if ($isLocker) {
            $lockerAssignment = $this->lockerAssignmentOf($assignment);
            $firstAccess = $this->firstLockerAccess($assignment);

            $state = match (true) {
                $cancellation !== null => 'cancelado',
                $lockerAssignment === null || $lockerAssignment->status !== 'active' => 'finalizado',
                $firstAccess !== null => 'entregado',
                default => 'asignado',
            };

            $evidence['folio_asignacion_locker'] = $assignment->locker_assignment_folio;
            $evidence['primer_acceso_en'] = $firstAccess?->scanned_at?->toIso8601String();
            $evidence['liberado_en'] = $lockerAssignment?->released_at?->toIso8601String();

            $consumed = $firstAccess !== null ? 1 : 0;
            $resource = [
                'tipo' => 'locker',
                'codigo' => $assignment->locker_code,
                'edificio' => $assignment->locker_building,
                'zona' => $assignment->locker_zone,
            ];
        } else {
            $remaining = (int) $assignment->remaining;

            $state = match (true) {
                $cancellation !== null => 'cancelado',
                $remaining <= 0 => 'consumido',
                $assignment->valid_until->lessThanOrEqualTo(now()) => 'vencido',
                (int) $assignment->consumed > 0 => 'en_uso',
                default => 'asignado',
            };

            $evidence['consumos'] = array_map(fn (array $consumption): array => [
                'folio_orden' => $consumption['order_folio'] ?? null,
                'paginas' => (int) ($consumption['pages'] ?? 0),
                'consumido_en' => $consumption['consumed_at'] ?? null,
            ], (array) ($assignment->consumptions ?? []));

            $consumed = (int) $assignment->consumed;
            $resource = null;
        }

        return [
            'asignacion_id' => (string) $assignment->id,
            'folio' => $assignment->folio,
            'beneficio_id' => $assignment->benefit,
            'estado' => $state,
            'clave_idempotencia' => $assignment->idempotency_key,
            'beneficiario_id' => $assignment->student_id,
            'organizacion_id' => $assignment->organization_id,
            'convocatoria_id' => $assignment->call_id,
            'solicitud_id' => $assignment->application_id,
            'folio_origen' => $assignment->origin_folio,
            'cantidad' => [
                'unidad' => $isLocker ? 'periodo' : 'paginas',
                'asignada' => (int) $assignment->quantity,
                'consumida' => $consumed,
                'disponible' => $isLocker ? ($state === 'asignado' || $state === 'entregado' ? 1 : 0) : (int) $assignment->remaining,
            ],
            'vigencia' => [
                'inicio' => $assignment->valid_from?->toIso8601String(),
                'fin_exclusiva' => $assignment->valid_until?->toIso8601String(),
            ],
            'recurso' => $resource,
            'evidencia' => $evidence,
            'cancelacion' => $cancellation === null ? null : [
                'cancelacion_id' => $cancellation['cancellation_id'] ?? null,
                'motivo' => $cancellation['reason'] ?? null,
                'referencia_origen' => $cancellation['origin_reference'] ?? null,
                'cancelada_en' => $cancellation['cancelled_at'] ?? null,
                'liberado' => (bool) ($cancellation['released'] ?? false),
                'cantidad_liberada' => (int) ($cancellation['released_quantity'] ?? 0),
            ],
            'creado_en' => $assignment->created_at?->toIso8601String(),
            'actualizado_en' => $assignment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Saldo de impresiones becado vigente de un estudiante (para 5.8).
     *
     * @return array{disponible: int, asignado: int, vence_en: string|null, maximo_por_orden: int, folios: list<string>}|null
     */
    public function printBalanceFor(string $studentId): ?array
    {
        $now = now();

        $allowances = BenefitAssignment::query()
            ->where('student_id', $studentId)
            ->where('benefit', BenefitAssignment::BENEFIT_PRINTS)
            ->where('status', 'active')
            ->where('remaining', '>', 0)
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->orderBy('valid_until')
            ->get();

        if ($allowances->isEmpty()) {
            return null;
        }

        return [
            'disponible' => (int) $allowances->sum('remaining'),
            'asignado' => (int) $allowances->sum('quantity'),
            'vence_en' => $allowances->first()->valid_until?->toIso8601String(),
            'maximo_por_orden' => (int) $allowances->max('remaining'),
            'folios' => array_values($allowances->map(fn (BenefitAssignment $allowance): string => $allowance->folio)->all()),
        ];
    }

    /**
     * Paga una orden de impresión de 5.8 con el saldo becado: descuenta
     * las páginas de forma atómica y registra el pago con la referencia
     * de la beca. Si el pago falla, devuelve las páginas.
     *
     * @throws RuntimeException
     */
    public function payPrintOrder(ServiceOrder $order, string $studentId): ServiceOrder
    {
        if ($order->service_type !== 'printing') {
            throw new RuntimeException('Solo las órdenes de impresión se pueden pagar con la beca de impresiones.');
        }

        if ($order->status !== 'awaiting_payment' || $order->payment_status !== 'pending') {
            throw new RuntimeException('Esta solicitud no está pendiente de pago.');
        }

        $pages = (int) $order->quantity;
        $now = now();

        $candidates = BenefitAssignment::query()
            ->where('student_id', $studentId)
            ->where('benefit', BenefitAssignment::BENEFIT_PRINTS)
            ->where('status', 'active')
            ->where('remaining', '>=', $pages)
            ->where('valid_from', '<=', $now)
            ->where('valid_until', '>', $now)
            ->orderBy('valid_until')
            ->get();

        $consumption = [
            'order_id' => (string) $order->id,
            'order_folio' => $order->folio,
            'pages' => $pages,
            'consumed_at' => $now->toIso8601String(),
        ];

        $used = null;

        foreach ($candidates as $candidate) {
            $updated = BenefitAssignment::query()
                ->where('_id', new ObjectId((string) $candidate->id))
                ->where('status', 'active')
                ->where('remaining', '>=', $pages)
                ->update([
                    '$inc' => ['consumed' => $pages, 'remaining' => -$pages],
                    '$push' => ['consumptions' => $consumption],
                ]);

            if ($updated > 0) {
                $used = $candidate;

                break;
            }
        }

        if ($used === null) {
            throw new RuntimeException("No tienes saldo de impresiones becado suficiente para esta orden ({$pages} páginas).");
        }

        try {
            return $this->serviceOrders->markAsPaid($order, 'BECA-'.$used->folio);
        } catch (Throwable $exception) {
            BenefitAssignment::query()
                ->where('_id', new ObjectId((string) $used->id))
                ->update([
                    '$inc' => ['consumed' => -$pages, 'remaining' => $pages],
                    '$pull' => ['consumptions' => ['order_id' => (string) $order->id]],
                ]);

            throw $exception;
        }
    }

    /**
     * Índices que garantizan la idempotencia aun con peticiones
     * simultáneas. La migración también los crea; aquí se asegura su
     * existencia (createIndex es idempotente).
     */
    public function ensureIndexes(): void
    {
        if ($this->indexesReady) {
            return;
        }

        $connection = DB::connection('mongodb');

        if (! $connection instanceof Connection) {
            throw new RuntimeException('La conexión [mongodb] no usa el driver de MongoDB.');
        }

        $collection = $connection->getCollection('benefit_assignments');
        $collection->createIndex(['client_id' => 1, 'idempotency_key' => 1], ['unique' => true, 'name' => 'client_idempotency_unique']);
        $collection->createIndex(['client_id' => 1, 'application_id' => 1], ['name' => 'client_application']);
        $collection->createIndex(['student_id' => 1, 'benefit' => 1, 'status' => 1], ['name' => 'student_benefit_status']);

        $this->indexesReady = true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     benefit: string,
     *     student_id: string,
     *     organization_id: string,
     *     call_id: string,
     *     application_id: string,
     *     origin_folio: string|null,
     *     quantity: int,
     *     valid_from: CarbonInterface,
     *     valid_until: CarbonInterface,
     *     building: string|null,
     *     hash_source: array<string, mixed>
     * }
     *
     * @throws BenefitException
     */
    private function normalize(array $input): array
    {
        $benefit = Str::lower(trim((string) ($input['beneficio_id'] ?? $input['tipo'] ?? '')));

        if (! in_array($benefit, $this->catalog->supportedBenefits(), true)) {
            throw $this->catalog->unsupported($benefit);
        }

        $from = Carbon::parse((string) ($input['periodo']['inicio'] ?? $input['vigencia_inicio']));
        $until = Carbon::parse((string) ($input['periodo']['fin'] ?? $input['vigencia_fin_exclusiva']));

        if ($until->lessThanOrEqualTo($from)) {
            throw new BenefitException('vigencia_invalida', 'El fin de la vigencia debe ser posterior al inicio.', 422);
        }

        if ($until->lessThanOrEqualTo(now())) {
            throw new BenefitException('vigencia_vencida', 'La vigencia del beneficio ya terminó.', 422);
        }

        $quantity = (int) $input['cantidad'];

        if ($benefit === BenefitAssignment::BENEFIT_LOCKER && $quantity !== 1) {
            throw new BenefitException('cantidad_invalida', 'La beca de locker se otorga por unidad: cantidad debe ser 1.', 422);
        }

        if ($benefit === BenefitAssignment::BENEFIT_PRINTS && ($quantity < 1 || $quantity > BenefitCatalog::MAX_PRINT_PAGES)) {
            throw new BenefitException(
                'cantidad_invalida',
                'La cantidad de impresiones debe estar entre 1 y '.BenefitCatalog::MAX_PRINT_PAGES.' páginas.',
                422
            );
        }

        $normalized = [
            'benefit' => $benefit,
            'student_id' => trim((string) $input['beneficiario_id']),
            'organization_id' => trim((string) $input['organizacion_id']),
            'call_id' => trim((string) $input['convocatoria_id']),
            'application_id' => trim((string) $input['solicitud_id']),
            'origin_folio' => isset($input['folio']) ? trim((string) $input['folio']) : null,
            'quantity' => $quantity,
            'valid_from' => $from,
            'valid_until' => $until,
            'building' => isset($input['edificio']) && trim((string) $input['edificio']) !== '' ? trim((string) $input['edificio']) : null,
        ];

        $normalized['hash_source'] = [
            'benefit' => $normalized['benefit'],
            'student_id' => $normalized['student_id'],
            'organization_id' => $normalized['organization_id'],
            'call_id' => $normalized['call_id'],
            'application_id' => $normalized['application_id'],
            'quantity' => $quantity,
            'valid_from' => $from->copy()->utc()->toIso8601String(),
            'valid_until' => $until->copy()->utc()->toIso8601String(),
            'building' => $normalized['building'],
        ];

        return $normalized;
    }

    /**
     * @return array{assignment: BenefitAssignment, created: bool}
     *
     * @throws BenefitException
     */
    private function replay(BenefitAssignment $existing, string $hash): array
    {
        if ($existing->request_hash !== $hash) {
            throw new BenefitException(
                'conflicto_idempotencia',
                'La clave de idempotencia ya se usó con un contenido diferente.',
                409,
                ['asignacion_id' => (string) $existing->id]
            );
        }

        if ($existing->status === 'processing') {
            throw new BenefitException(
                'en_proceso',
                'La solicitud con esta clave todavía se está procesando; consulta su resultado en unos segundos.',
                409
            );
        }

        return ['assignment' => $existing, 'created' => false];
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws BenefitException
     */
    private function assertNotAlreadyAssigned(string $clientId, array $request, string $idempotencyKey): void
    {
        $duplicate = BenefitAssignment::query()
            ->where('client_id', $clientId)
            ->where('application_id', $request['application_id'])
            ->where('benefit', $request['benefit'])
            ->whereIn('status', ['processing', 'active'])
            ->where('idempotency_key', '!=', $idempotencyKey)
            ->first();

        if ($duplicate !== null) {
            throw new BenefitException(
                'solicitud_ya_asignada',
                'Esta solicitud ya tiene una asignación vigente con otra clave de idempotencia.',
                409,
                ['asignacion_id' => (string) $duplicate->id]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws BenefitException
     */
    private function assertEligible(array $request): void
    {
        $status = $this->students->status($request['student_id']);

        if ($status === 'not_found') {
            throw new BenefitException('beneficiario_no_encontrado', 'El beneficiario no existe.', 422);
        }

        if ($status !== 'active') {
            throw new BenefitException('beneficiario_no_elegible', 'El beneficiario no tiene un estatus activo.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws BenefitException
     */
    private function fulfillLocker(BenefitAssignment $assignment, array $request, string $clientId): void
    {
        $period = $this->catalog->lockerPeriodFor($request['valid_from'], $request['valid_until'])
            ?? throw new BenefitException(
                'sin_periodo',
                'No hay un periodo de lockers activo que se traslape con la vigencia solicitada.',
                409
            );

        if ($this->lockers->studentHasOpenService($request['student_id'], (string) $period->id)) {
            throw new BenefitException(
                'beneficiario_con_locker',
                'El beneficiario ya tiene un locker o una solicitud de locker en este periodo.',
                409
            );
        }

        $reference = "Beca {$request['origin_folio']} · convocatoria {$request['call_id']} · solicitud {$request['application_id']}";

        for ($attempt = 1; $attempt <= self::LOCKER_CLAIM_ATTEMPTS; $attempt++) {
            $locker = Locker::query()
                ->where('status', 'available')
                ->when($request['building'] !== null, fn ($query) => $query->where('building', $request['building']))
                ->orderBy('building')
                ->orderBy('zone')
                ->orderBy('code')
                ->first();

            if ($locker === null) {
                break;
            }

            try {
                $lockerAssignment = $this->lockers->createSponsored(
                    $request['student_id'],
                    $period,
                    'scholarship',
                    $locker,
                    null,
                    $reference,
                    'api:'.$clientId
                );
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === self::LOCKER_TAKEN_MESSAGE) {
                    continue;
                }

                throw new BenefitException('rechazado', $exception->getMessage(), 409);
            }

            $assignment->update([
                'status' => 'active',
                'locker_assignment_id' => (string) $lockerAssignment->id,
                'locker_assignment_folio' => $lockerAssignment->folio,
                'locker_code' => $locker->code,
                'locker_building' => $locker->building,
                'locker_zone' => $locker->zone,
                'valid_from' => $lockerAssignment->starts_at,
                'valid_until' => $this->exclusiveEnd($lockerAssignment->ends_at),
                'remaining' => 0,
            ]);

            return;
        }

        $availability = $this->catalog->availability(
            BenefitAssignment::BENEFIT_LOCKER,
            $request['valid_from'],
            $request['valid_until'],
            1,
            $request['building']
        );

        throw new BenefitException(
            'sin_disponibilidad',
            'No hay lockers disponibles'.($request['building'] !== null ? " en {$request['building']}" : '').'.',
            409,
            ['alternativas' => $availability['alternativas']]
        );
    }

    /**
     * Los periodos de lockers guardan su fin como instante inclusivo
     * (23:59:59); el contrato con Comunidad usa fin exclusivo.
     */
    private function exclusiveEnd(CarbonInterface $end): CarbonInterface
    {
        return $end->format('H:i:s') === '23:59:59' ? $end->copy()->addSecond()->startOfSecond() : $end;
    }

    private function lockerAssignmentOf(BenefitAssignment $assignment): ?LockerAssignment
    {
        if ($assignment->locker_assignment_id === null) {
            return null;
        }

        return LockerAssignment::find((string) $assignment->locker_assignment_id);
    }

    /**
     * Primer acceso autorizado al locker registrado por la validación de
     * servicios (5.11): es la evidencia de entrega.
     */
    private function firstLockerAccess(BenefitAssignment $assignment): ?ServiceCheckin
    {
        if ($assignment->locker_code === null) {
            return null;
        }

        return ServiceCheckin::query()
            ->where('service', 'locker')
            ->where('granted', true)
            ->where('student_id', $assignment->student_id)
            ->where('reference', $assignment->locker_code)
            ->where('scanned_at', '>=', $assignment->created_at)
            ->orderBy('scanned_at')
            ->first();
    }

    private function findByKey(string $clientId, string $idempotencyKey): ?BenefitAssignment
    {
        return BenefitAssignment::query()
            ->where('client_id', $clientId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function isDuplicateKey(Throwable $exception): bool
    {
        return $exception->getCode() === 11000
            || str_contains($exception->getMessage(), 'E11000')
            || str_contains(strtolower($exception->getMessage()), 'duplicate key');
    }

    private function newFolio(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
    }
}
