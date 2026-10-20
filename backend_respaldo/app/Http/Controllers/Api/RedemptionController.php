<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Redemption;
use App\Models\RedemptionEvent;
use App\Models\Reward;
use App\Models\User;
use App\Services\PointsLedger\DTO\RedeemPointsRequest;
use App\Services\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PointsLedger\Exception\InsufficientPointsException;
use App\Services\PointsLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RedemptionController
{
    public function __construct(private readonly PointsLedgerService $ledger) {}

    /**
     * Listado de canjes:
     * - Estudiantes: solo sus propios canjes.
     * - Profesores y administradores: todos, con filtros por status, código o estudiante.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $user = $request->user();

        $query = Redemption::with(['reward'])->orderBy('created_at', 'desc');

        if ($user && $user->role === User::ROLE_STUDENT) {
            $query->where('student_id', (string) $user->getKey());
        } elseif ($studentId = $request->query('student_id')) {
            $query->where('student_id', $studentId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($code = $request->query('code')) {
            $query->where('redemption_code', strtoupper(trim($code)));
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Detalle de un canje con su línea de tiempo de eventos y datos de recompensa.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $redemption = Redemption::with(['reward', 'events'])->findOrFail($id);
        $user = $request->user();

        if ($user && $user->role === User::ROLE_STUDENT && $redemption->student_id !== (string) $user->getKey()) {
            return response()->json([
                'success' => false,
                'error'   => 'Acceso denegado: no puedes ver los canjes de otro estudiante.',
            ], 403);
        }

        return response()->json($redemption);
    }

    /**
     * Solicitud de canje (iniciada por el estudiante o en su nombre):
     * 1. Verifica vigencia y disponibilidad de la recompensa.
     * 2. Comprueba stock disponible.
     * 3. Comprueba saldo suficiente en el ledger.
     * 4. En transacción: descuenta puntos (ledger append-only), reserva stock y emite código único.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'reward_id'       => 'required|string',
            'reward_item_id'  => 'nullable|string',
            'idempotency_key' => 'nullable|string',
            'student_id'      => 'nullable|string', // solo si un admin canjea en nombre de un alumno
        ]);

        // Determina el estudiante: el autenticado (si es alumno) o el indicado por admin
        $studentId = ($user && $user->role === User::ROLE_STUDENT)
            ? (string) $user->getKey()
            : ($validated['student_id'] ?? (string) $user->getKey());

        $reward = Reward::findOrFail($validated['reward_id']);

        if ($reward->status !== 'active') {
            return response()->json([
                'success' => false,
                'error'   => 'La recompensa no está activa para canje.',
            ], 422);
        }

        if ($reward->stock !== null && $reward->availableStock() <= 0) {
            return response()->json([
                'success' => false,
                'error'   => 'No hay stock disponible para esta recompensa.',
            ], 422);
        }

        $availableBalance = $this->ledger->getAvailableBalance($studentId);
        if ($availableBalance < $reward->points_required) {
            return response()->json([
                'success'           => false,
                'error'             => "Saldo insuficiente: tienes {$availableBalance} puntos y se requieren {$reward->points_required}.",
                'available_balance' => $availableBalance,
                'points_required'   => $reward->points_required,
            ], 422);
        }

        $idempotencyKey = $validated['idempotency_key'] ?? (string) Str::uuid();
        $redemptionCode = 'CJ-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));

        try {
            // Descuento atómico en PointsLedger (maneja su propia transacción)
            $ledgerResult = $this->ledger->redeem(new RedeemPointsRequest(
                studentId:      $studentId,
                redemptionId:   $idempotencyKey,
                pointsRequired: $reward->points_required,
                idempotencyKey: $idempotencyKey,
                metadata: [
                    'reward_name'     => $reward->name,
                    'redemption_code' => $redemptionCode,
                ],
                actorId:   $user ? (string) $user->getKey() : $studentId,
                actorRole: $user?->role ?? 'student',
            ));

            // Reserva stock provisional
            $reward->increment('reserved_stock', 1);

            $redemption = Redemption::create([
                'student_id'                  => $studentId,
                'reward_id'                   => (string) $reward->_id,
                'reward_item_id'              => $validated['reward_item_id'] ?? null,
                'points_spent'                => $reward->points_required,
                'status'                      => 'confirmed', // listo para validación/entrega
                'redemption_code'             => $redemptionCode,
                'stock_reservation_reference' => (string) Str::uuid(),
                'requested_at'                => now(),
                'ledger_entry_id'             => (string) $ledgerResult->ledgerEntry->_id,
            ]);

            // Evento de ciclo de vida
            RedemptionEvent::create([
                'redemption_id' => (string) $redemption->_id,
                'from_status'   => 'requested',
                'to_status'     => 'confirmed',
                'actor_id'      => $user ? (string) $user->getKey() : $studentId,
                'actor_role'    => $user?->role ?? 'student',
                'note'          => 'Solicitud de canje creada exitosamente.',
            ]);

            AuditLog::create([
                'actor_id'      => $user ? (string) $user->getKey() : $studentId,
                'actor_role'    => $user?->role ?? 'student',
                'action'        => 'redemption.created',
                'entity_type'   => 'Redemption',
                'entity_id'     => (string) $redemption->_id,
                'after'         => [
                    'code'            => $redemptionCode,
                    'points_spent'    => $reward->points_required,
                    'reward_id'       => (string) $reward->_id,
                    'ledger_entry_id' => (string) $ledgerResult->ledgerEntry->_id,
                ],
                'ip_address'    => $request->ip(),
                'device'        => $request->userAgent(),
                'source_domain' => 'rewards',
            ]);

            return response()->json([
                'success'    => true,
                'redemption' => $redemption->fresh(['reward']),
                'account'    => $ledgerResult->account,
            ], 201);

        } catch (InsufficientPointsException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Búsqueda y validación de código de canje (para profesores y administradores).
     */
    public function validateCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $code = strtoupper(trim($validated['code']));
        $redemption = Redemption::with(['reward', 'events'])->where('redemption_code', $code)->first();

        if (! $redemption) {
            return response()->json([
                'success' => false,
                'error'   => "No se encontró ningún canje con el código [{$code}].",
            ], 404);
        }

        $student = User::find($redemption->student_id);

        return response()->json([
            'success'    => true,
            'redemption' => $redemption,
            'student'    => $student ? [
                'id'           => (string) $student->getKey(),
                'name'         => $student->name,
                'email'        => $student->email,
                'student_code' => $student->student_code,
            ] : null,
            'can_deliver' => in_array($redemption->status, ['confirmed', 'reserved', 'stock_pending'], true),
        ]);
    }

    /**
     * Entrega de la recompensa física o confirmación final de servicio:
     * Transición a 'delivered', descuenta stock final, libera reserved_stock.
     */
    public function deliver(Request $request, string $id): JsonResponse
    {
        $redemption = Redemption::with(['reward'])->findOrFail($id);
        $user = $request->user();

        if (! in_array($redemption->status, ['confirmed', 'reserved', 'stock_pending'], true)) {
            return response()->json([
                'success' => false,
                'error'   => "El canje está en estado [{$redemption->status}] y no puede ser marcado como entregado.",
            ], 422);
        }

        DB::transaction(function () use ($redemption, $user, $request) {
            $fromStatus = $redemption->status;
            $redemption->update([
                'status'       => 'delivered',
                'delivered_at' => now(),
            ]);

            // Actualiza stock en la recompensa
            $reward = $redemption->reward;
            if ($reward) {
                if ($reward->reserved_stock > 0) {
                    $reward->decrement('reserved_stock', 1);
                }
                if ($reward->stock !== null && $reward->stock > 0) {
                    $reward->decrement('stock', 1);
                }
            }

            RedemptionEvent::create([
                'redemption_id' => (string) $redemption->_id,
                'from_status'   => $fromStatus,
                'to_status'     => 'delivered',
                'actor_id'      => $user ? (string) $user->getKey() : 'system',
                'actor_role'    => $user?->role ?? 'admin',
                'note'          => $request->input('note', 'Recompensa entregada al estudiante.'),
            ]);

            AuditLog::create([
                'actor_id'      => $user ? (string) $user->getKey() : 'system',
                'actor_role'    => $user?->role ?? 'admin',
                'action'        => 'redemption.delivered',
                'entity_type'   => 'Redemption',
                'entity_id'     => (string) $redemption->_id,
                'before'        => ['status' => $fromStatus],
                'after'         => ['status' => 'delivered', 'delivered_at' => now()->toIso8601String()],
                'ip_address'    => $request->ip(),
                'device'        => $request->userAgent(),
                'source_domain' => 'rewards',
            ]);
        });

        return response()->json([
            'success'    => true,
            'message'    => 'Canje marcado como entregado correctamente.',
            'redemption' => $redemption->fresh(['reward', 'events']),
        ]);
    }

    /**
     * Cancelación de canje:
     * - Estudiante (solo si aún no ha sido entregado) o Administrador.
     * - Revierte los puntos automáticamente al estudiante vía PointsLedgerService.
     * - Restaura stock reservado.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $redemption = Redemption::with(['reward'])->findOrFail($id);
        $user = $request->user();

        // Control de permisos
        if ($user && $user->role === User::ROLE_STUDENT && $redemption->student_id !== (string) $user->getKey()) {
            return response()->json(['success' => false, 'error' => 'Acceso denegado.'], 403);
        }

        if (in_array($redemption->status, ['cancelled', 'reversed', 'delivered'], true)) {
            return response()->json([
                'success' => false,
                'error'   => "No se puede cancelar un canje en estado [{$redemption->status}].",
            ], 422);
        }

        $reason = $request->input('reason', 'Cancelación solicitada por el usuario.');

        $fromStatus = $redemption->status;

        // Si hay un ledger_entry_id asociado, revertir el débito (el ledger maneja su propia transacción)
        if ($redemption->ledger_entry_id) {
            $this->ledger->reverse(new ReversePointsRequest(
                relatedLedgerId: $redemption->ledger_entry_id,
                reason:          "Cancelación del canje {$redemption->redemption_code}: {$reason}",
                idempotencyKey:  (string) Str::uuid(),
                metadata:        ['redemption_id' => (string) $redemption->_id],
                actorId:         $user ? (string) $user->getKey() : null,
                actorRole:       $user?->role ?? 'admin',
            ));
        }

        // Liberar stock reservado
        $reward = $redemption->reward;
        if ($reward && $reward->reserved_stock > 0) {
            $reward->decrement('reserved_stock', 1);
        }

        $redemption->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        RedemptionEvent::create([
            'redemption_id' => (string) $redemption->_id,
            'from_status'   => $fromStatus,
            'to_status'     => 'cancelled',
            'actor_id'      => $user ? (string) $user->getKey() : 'system',
            'actor_role'    => $user?->role ?? 'admin',
            'note'          => $reason,
        ]);

        AuditLog::create([
            'actor_id'      => $user ? (string) $user->getKey() : 'system',
            'actor_role'    => $user?->role ?? 'admin',
            'action'        => 'redemption.cancelled',
            'entity_type'   => 'Redemption',
            'entity_id'     => (string) $redemption->_id,
            'before'        => ['status' => $fromStatus],
            'after'         => ['status' => 'cancelled', 'reason' => $reason],
            'reason'        => $reason,
            'ip_address'    => $request->ip(),
            'device'        => $request->userAgent(),
            'source_domain' => 'rewards',
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'Canje cancelado y puntos devueltos al estudiante.',
            'redemption' => $redemption->fresh(['reward', 'events']),
        ]);
    }

    /**
     * Historial de eventos de un canje.
     */
    public function events(string $id): JsonResponse
    {
        $events = RedemptionEvent::where('redemption_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($events);
    }
}
