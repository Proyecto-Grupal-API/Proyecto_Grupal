<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;

class StudentCashConfirmationController extends Controller
{
    private function owned(Request $request)
    {
        $id = (string) $request->user()->getKey();
        return CashOperationConfirmation::where('student_id', $id)->whereHas('wallet', fn ($q) => $q->where('owner_type', 'USER')->where('owner_id', $id));
    }
    private function data($proof): array
    {
        return ['id' => strtolower($proof->public_id), 'status' => in_array($proof->status, ['PENDING', 'CONFIRMED'], true) && ! $proof->expires_at->isFuture() ? 'EXPIRED' : $proof->status,
            'operation' => $proof->operation, 'amount_cents' => $proof->amount_cents, 'currency' => $proof->currency,
            'reason' => $proof->reason, 'register_name' => $proof->shift->cashRegister->name,
            'expires_at' => $proof->expires_at->toISOString(), 'confirmed_at' => $proof->confirmed_at?->toISOString(),
            'consumed_at' => $proof->consumed_at?->toISOString()];
    }
    public function index(Request $request)
    {
        $proofs = $this->owned($request)->with('shift.cashRegister')->orderByDesc('id')->paginate(10);
        return Inertia::render('Financial/CashConfirmations', ['confirmations' => $proofs->through(fn ($proof) => $this->data($proof)), 'selected' => null]);
    }
    public function show(Request $request, string $confirmationId)
    {
        $proof = $this->owned($request)->with('shift.cashRegister')->where('public_id', $confirmationId)->firstOrFail();
        return Inertia::render('Financial/CashConfirmations', ['confirmations' => null, 'selected' => $this->data($proof)]);
    }
    public function decide(Request $request, string $confirmationId, string $action, CashOperationConfirmationService $service)
    {
        $proof = $this->owned($request)->where('public_id', $confirmationId)->firstOrFail();
        try { $service->decide($proof->public_id, $request->user(), $action === 'approve', $request->ip()); }
        catch (InvalidArgumentException $exception) { return back()->withErrors(['confirmation' => $exception->getMessage()]); }
        return back()->with('success', $action === 'approve' ? 'Operación confirmada. El cajero puede finalizarla.' : 'Operación rechazada.');
    }
}
