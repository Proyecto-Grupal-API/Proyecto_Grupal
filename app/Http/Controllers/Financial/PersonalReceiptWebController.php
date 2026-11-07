<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\PersonalReceiptQueryService;
use App\Domains\Financial\Services\ReceiptPdfService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class PersonalReceiptWebController extends Controller
{
    public function __construct(private WalletService $wallets, private PersonalReceiptQueryService $receipts) {}

    private function wallet(Request $request)
    {
        return $this->wallets->findByOwner('USER', (string) $request->user()->getKey(), WalletType::USUARIO);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $wallet = $this->wallet($request);

        return Inertia::render('Financial/PersonalReceipts', [
            'receipts' => $wallet ? $this->receipts->listing($wallet, $data['per_page'] ?? 20, $data['page'] ?? 1)->withQueryString()
                : ['data' => [], 'total' => 0, 'links' => []],
            'hasWallet' => (bool) $wallet,
        ]);
    }

    private function receipt(Request $request, string $source, string $operationId): array
    {
        $wallet = $this->wallet($request);
        abort_unless($wallet, 404);
        $receipt = match ($source) {
            'transaction' => $this->receipts->transaction($wallet, $operationId),
            'purchase' => $this->receipts->purchase($wallet, $operationId),
            default => abort(404),
        };

        // Verify only identifiers already exposed by the owner's canonical receipt.
        $verification = Validator::make($request->query(), [
            'folio' => 'sometimes|required|string|max:50',
            'reference' => 'sometimes|required|uuid',
        ]);
        abort_if($verification->fails(), 404);

        $identifiers = $verification->validated();
        if (isset($identifiers['folio'])) {
            abort_unless(in_array($identifiers['folio'], array_column($receipt['cash_receipts'], 'folio'), true), 404);
        }
        if (isset($identifiers['reference'])) {
            abort_unless(strtolower($identifiers['reference']) === $receipt['id'], 404);
        }

        return $receipt;
    }

    public function show(Request $request, string $source, string $operationId)
    {
        return Inertia::render('Financial/PersonalReceipt', ['receipt' => $this->receipt($request, $source, $operationId)])
            ->toResponse($request)
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function downloadPdf(Request $request, string $source, string $operationId, ReceiptPdfService $pdf)
    {
        return $pdf->download($this->receipt($request, $source, $operationId));
    }
}
