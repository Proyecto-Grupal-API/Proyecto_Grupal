<?php

namespace App\Http\Controllers\StudentServices\Benefits;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Benefits\BenefitAvailabilityRequest;
use App\Http\Requests\StudentServices\Benefits\CancelBenefitAssignmentRequest;
use App\Http\Requests\StudentServices\Benefits\ListBenefitAssignmentsRequest;
use App\Http\Requests\StudentServices\Benefits\StoreBenefitAssignmentRequest;
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Services\StudentServices\Benefits\BenefitCatalog;
use App\Services\StudentServices\Benefits\BenefitException;
use App\Services\StudentServices\Benefits\ServiceBenefitService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API de beneficios de servicio para Comunidad (REQ-M6-E5-001).
 * Base: /api/v1/servicios. Autenticación: token de servicio
 * (oauth.service) con scopes services:benefits:read / :write.
 */
class BenefitApiController extends Controller
{
    public function __construct(
        private BenefitCatalog $catalog,
        private ServiceBenefitService $benefits
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $type = $request->query('tipo');

        return ApiResponse::data(
            $request,
            $this->catalog->catalog(is_string($type) ? $type : null),
            meta: ['siguiente_cursor' => null]
        );
    }

    public function availability(BenefitAvailabilityRequest $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $data = $request->validated();
            $from = isset($data['inicio']) ? Carbon::parse($data['inicio']) : now();
            $until = isset($data['fin']) ? Carbon::parse($data['fin']) : $from->copy()->addDay();

            return ApiResponse::data($request, $this->catalog->availability(
                strtolower($data['beneficio_id']),
                $from,
                $until,
                (int) ($data['cantidad'] ?? 1),
                $data['edificio'] ?? $data['zona'] ?? null
            ));
        });
    }

    public function store(StoreBenefitAssignmentRequest $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $result = $this->benefits->assign(
                $request->validated(),
                $this->idempotencyKey($request),
                $this->clientId($request)
            );

            $payload = $this->benefits->present($result['assignment']);

            return ApiResponse::data($request, $payload, $result['created'] ? 201 : 200, [
                'repetida' => ! $result['created'],
            ])->header('Location', url('/api/v1/servicios/asignaciones/'.$payload['asignacion_id']));
        });
    }

    public function index(ListBenefitAssignmentsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $result = $this->benefits->search(
            $filters,
            $this->clientId($request),
            $filters['cursor'] ?? null,
            (int) ($filters['limite'] ?? 20)
        );

        return ApiResponse::data(
            $request,
            array_map(fn (BenefitAssignment $assignment): array => $this->benefits->present($assignment), $result['items']),
            meta: ['siguiente_cursor' => $result['next_cursor']]
        );
    }

    public function show(Request $request, string $assignmentId): JsonResponse
    {
        $assignment = $this->benefits->find($assignmentId, $this->clientId($request));

        if ($assignment === null) {
            return ApiResponse::error($request, 'no_encontrada', 'La asignación no existe para este cliente.', 404);
        }

        return ApiResponse::data($request, $this->benefits->present($assignment));
    }

    public function cancel(CancelBenefitAssignmentRequest $request, string $assignmentId): JsonResponse
    {
        return $this->guard($request, function () use ($request, $assignmentId): JsonResponse {
            $data = $request->validated();

            $result = $this->benefits->cancel(
                $assignmentId,
                $this->idempotencyKey($request),
                $data['motivo'],
                $data['referencia_origen'] ?? null,
                $this->clientId($request)
            );

            $assignment = $this->benefits->present($result['assignment']);

            return ApiResponse::data($request, [
                'cancelacion_id' => $assignment['cancelacion']['cancelacion_id'] ?? null,
                'estado' => $assignment['estado'],
                'liberado' => $assignment['cancelacion']['liberado'] ?? false,
                'cantidad_liberada' => $assignment['cancelacion']['cantidad_liberada'] ?? 0,
                'asignacion' => $assignment,
            ], meta: ['repetida' => $result['replayed']]);
        });
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function guard(Request $request, Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (BenefitException $exception) {
            return ApiResponse::error(
                $request,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details
            );
        }
    }

    /**
     * La clave viene en el encabezado Idempotency-Key (preferido) o en
     * el campo clave_idempotencia del contrato de Comunidad.
     *
     * @throws BenefitException
     */
    private function idempotencyKey(Request $request): string
    {
        $header = trim((string) $request->header('Idempotency-Key', ''));
        $body = trim((string) $request->input('clave_idempotencia', ''));

        if ($header !== '' && $body !== '' && $header !== $body) {
            throw new BenefitException(
                'idempotencia_inconsistente',
                'El encabezado Idempotency-Key y clave_idempotencia no coinciden.',
                422
            );
        }

        $key = $header !== '' ? $header : $body;

        if ($key === '' || mb_strlen($key) > 150) {
            throw new BenefitException(
                'falta_idempotencia',
                'Envía el encabezado Idempotency-Key (máximo 150 caracteres).',
                422
            );
        }

        return $key;
    }

    private function clientId(Request $request): string
    {
        return (string) ($request->attributes->get('oauth_client_id') ?? 'desconocido');
    }
}
