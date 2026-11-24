<?php

namespace App\Http\Controllers\PlatformModule;

use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Services\PlatformModule\PlatformActor as User;
use App\Services\PlatformModule\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PlatformModule\PointsLedger\DTO\RedeemPointsRequest;
use App\Services\PlatformModule\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PlatformModule\PointsLedger\Exception\DailyCapExceededException;
use App\Services\PlatformModule\PointsLedger\Exception\InsufficientPointsException;
use App\Services\PlatformModule\PointsLedger\Exception\LedgerEntryNotFoundException;
use App\Services\PlatformModule\PointsLedger\Exception\NoActiveEarningRuleException;
use App\Services\PlatformModule\PointsLedgerService;
use App\Services\StudentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PointsController
{
    public function __construct(private readonly PointsLedgerService $ledger) {}

    /**
     * Consulta de saldo del usuario autenticado (para alumnos).
     */
    public function myBalance(Request $request): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');
        $studentId = (string) $user->getKey();
        $balance = $this->ledger->getAvailableBalance($studentId);
        $account = PointsAccount::where('student_id', $studentId)->first();

        return response()->json([
            'student_id' => $studentId,
            'balance' => $balance,
            'pending_balance' => $account?->pending_balance ?? 0,
            'lifetime_earned' => $account?->lifetime_earned ?? 0,
            'lifetime_redeemed' => $account?->lifetime_redeemed ?? 0,
        ]);
    }

    /**
     * Historial de movimientos del usuario autenticado (para alumnos).
     */
    public function myHistory(Request $request): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');
        $studentId = (string) $user->getKey();

        return $this->queryHistory($request, $studentId);
    }

    /**
     * Consulta de saldo por student_id (con control de acceso).
     * Los estudiantes solo pueden ver su propio saldo.
     */
    public function balance(Request $request, string $studentId): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');

        if ($user && $user->role === User::ROLE_STUDENT && (string) $user->getKey() !== $studentId) {
            return response()->json([
                'success' => false,
                'error' => 'Acceso denegado: no puedes consultar el saldo de otro estudiante.',
            ], 403);
        }

        $balance = $this->ledger->getAvailableBalance($studentId);
        $account = PointsAccount::where('student_id', $studentId)->first();

        return response()->json([
            'student_id' => $studentId,
            'balance' => $balance,
            'pending_balance' => $account?->pending_balance ?? 0,
            'lifetime_earned' => $account?->lifetime_earned ?? 0,
            'lifetime_redeemed' => $account?->lifetime_redeemed ?? 0,
        ]);
    }

    /**
     * Historial de movimientos por student_id (con control de acceso).
     */
    public function history(Request $request, string $studentId): JsonResponse
    {
        $user = $request->attributes->get('platform_actor');

        if ($user && $user->role === User::ROLE_STUDENT && (string) $user->getKey() !== $studentId) {
            return response()->json([
                'success' => false,
                'error' => 'Acceso denegado: no puedes consultar el historial de otro estudiante.',
            ], 403);
        }

        return $this->queryHistory($request, $studentId);
    }

    /**
     * Ledger global de puntos (solo administración y auditoría transversal).
     * Permite filtros por student_id, type, business_id y rango de fechas.
     */
    private function ledgerQuery(Request $request)
    {
        $query = PointsLedger::orderBy('created_at', 'desc');

        if ($studentId = $request->query('student_id')) {
            $query->where('student_id', $studentId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($businessId = $request->query('business_id')) {
            $query->where('business_id', $businessId);
        }
        if ($sourceDomain = $request->query('source_domain')) {
            $query->where('source_domain', $sourceDomain);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('source_reference', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%");
            });
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return $query;
    }

    public function ledger(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        return response()->json($this->ledgerQuery($request)->paginate($perPage));
    }

    /** Exportación CSV del ledger con los mismos filtros del listado (rúbrica 10.3 y 10.4). */
    public function exportLedger(Request $request): StreamedResponse
    {
        $rows = $this->ledgerQuery($request)->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['fecha', 'alumno', 'tipo', 'puntos', 'negocio', 'dominio', 'referencia', 'descripcion', 'movimiento_relacionado']);
            foreach ($rows as $entry) {
                fputcsv($out, [optional($entry->created_at)->toIso8601String(), $entry->student_id, $entry->type, $entry->amount,
                    $entry->business_id, $entry->source_domain, $entry->source_reference, $entry->description, $entry->related_ledger_id]);
            }
            fclose($out);
        }, 'ledger-recompensas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Acumulación de puntos autorizada.
     */
    public function earn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|string',
            'business_id' => 'required|string',
            'sale_amount' => 'required|numeric|min:0',
            'source_domain' => 'required|string',
            'source_reference' => 'required|string',
            'campaign_id' => 'nullable|string',
            'idempotency_key' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Los puntos pertenecen a un alumno: Identidad (1.8) confirma que la cuenta existe y tiene perfil de estudiante.
        try {
            app(StudentStatusService::class)->forUserId($validated['student_id']);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['student_id' => 'El identificador no corresponde a un alumno registrado.']);
        }

        $user = $request->attributes->get('platform_actor');

        try {
            $dto = new EarnPointsRequest(
                studentId: $validated['student_id'],
                businessId: $validated['business_id'],
                sourceDomain: $validated['source_domain'],
                sourceReference: $validated['source_reference'],
                saleAmount: (float) $validated['sale_amount'],
                idempotencyKey: $validated['idempotency_key'] ?? (string) Str::uuid(),
                campaignId: $validated['campaign_id'] ?? null,
                metadata: $validated['metadata'] ?? [],
                actorId: $user ? (string) $user->getKey() : null,
                actorRole: $user?->role ?? 'system',
            );

            $result = $this->ledger->earn($dto);

            return response()->json([
                'success' => true,
                'replayed' => $result->wasIdempotentReplay,
                'ledger' => $result->ledgerEntry,
                'account' => $result->account,
            ], $result->wasIdempotentReplay ? 200 : 201);

        } catch (NoActiveEarningRuleException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (DailyCapExceededException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Canje de puntos (operación de bajo nivel de ledger).
     */
    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|string',
            'redemption_id' => 'required|string',
            'points_required' => 'required|integer|min:1',
            'idempotency_key' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $user = $request->attributes->get('platform_actor');

        // Si quien llama es estudiante, solo puede canjear sus propios puntos
        if ($user && $user->role === User::ROLE_STUDENT && (string) $user->getKey() !== $validated['student_id']) {
            return response()->json([
                'success' => false,
                'error' => 'Acceso denegado: no puedes canjear puntos de otra cuenta.',
            ], 403);
        }

        try {
            $dto = new RedeemPointsRequest(
                studentId: $validated['student_id'],
                redemptionId: $validated['redemption_id'],
                pointsRequired: (int) $validated['points_required'],
                idempotencyKey: $validated['idempotency_key'] ?? (string) Str::uuid(),
                metadata: $validated['metadata'] ?? [],
                actorId: $user ? (string) $user->getKey() : null,
                actorRole: $user?->role ?? 'student',
            );

            $result = $this->ledger->redeem($dto);

            return response()->json([
                'success' => true,
                'replayed' => $result->wasIdempotentReplay,
                'ledger' => $result->ledgerEntry,
                'account' => $result->account,
            ], $result->wasIdempotentReplay ? 200 : 201);

        } catch (InsufficientPointsException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Reverso de movimiento de puntos.
     */
    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'related_ledger_id' => 'required|string',
            'reason' => 'required|string|max:500',
            'idempotency_key' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $user = $request->attributes->get('platform_actor');

        try {
            $dto = new ReversePointsRequest(
                relatedLedgerId: $validated['related_ledger_id'],
                reason: $validated['reason'],
                idempotencyKey: $validated['idempotency_key'] ?? (string) Str::uuid(),
                metadata: $validated['metadata'] ?? [],
                actorId: $user ? (string) $user->getKey() : null,
                actorRole: $user?->role ?? 'admin',
            );

            $result = $this->ledger->reverse($dto);

            return response()->json([
                'success' => true,
                'replayed' => $result->wasIdempotentReplay,
                'shortfall' => $result->shortfallAmount,
                'ledger' => $result->ledgerEntry,
                'account' => $result->account,
            ]);

        } catch (LedgerEntryNotFoundException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * Reconciliación forzada de saldo desde el ledger.
     */
    public function reconcile(string $studentId): JsonResponse
    {
        $account = $this->ledger->reconcileAccount($studentId);

        return response()->json(['success' => true, 'account' => $account]);
    }

    private function queryHistory(Request $request, string $studentId): JsonResponse
    {
        $validated = $request->validate([
            'movement_id' => ['sometimes', 'required', 'string', 'regex:/\A[0-9a-fA-F]{24}\z/'],
        ]);
        $perPage = min((int) $request->query('per_page', 20), 100);
        $type = $request->query('type');

        $query = PointsLedger::where('student_id', $studentId)
            ->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        if (isset($validated['movement_id'])) {
            $query->where('_id', strtolower($validated['movement_id']));
        }

        $entries = $query->paginate($perPage);

        return response()->json($entries);
    }
}
