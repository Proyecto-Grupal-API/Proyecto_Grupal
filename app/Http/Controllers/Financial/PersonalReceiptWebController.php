<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\{WalletService, PersonalReceiptQueryService};
use App\Domains\Financial\Enums\WalletType;
use Illuminate\Http\Request;
use Inertia\Inertia;
class PersonalReceiptWebController extends Controller
{
    public function __construct(private WalletService $wallets, private PersonalReceiptQueryService $receipts) {}
    private function wallet(Request $request) {
        return $this->wallets->findByOwner('USER', (string) $request->user()->getKey(), WalletType::USUARIO);
    }
    public function index(Request $request) {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $wallet = $this->wallet($request);
        return Inertia::render('Financial/PersonalReceipts', [
            'receipts' => $wallet ? $this->receipts->listing($wallet, $data['per_page'] ?? 20, $data['page'] ?? 1)->withQueryString()
                : ['data' => [], 'total' => 0, 'links' => []],
            'hasWallet' => (bool) $wallet,
        ]);
    }
    public function show(Request $request, string $source, string $operationId) {
        $wallet = $this->wallet($request); abort_unless($wallet, 404);
        $receipt = match ($source) {
            'transaction' => $this->receipts->transaction($wallet, $operationId),
            'purchase' => $this->receipts->purchase($wallet, $operationId),
            default => abort(404),
        };
        return Inertia::render('Financial/PersonalReceipt', ['receipt' => $receipt]);
    }
}
