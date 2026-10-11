<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\{WalletService, PersonalReceiptQueryService};
use App\Domains\Financial\Enums\WalletType;
use Illuminate\Http\Request;
class PersonalReceiptApiController extends Controller
{
    public function __construct(private WalletService $wallets, private PersonalReceiptQueryService $receipts) {}
    private function wallet(Request $request) {
        $user = $request->attributes->get('receipt_delegated_user_id');
        abort_unless(is_string($user) && preg_match('/^[a-f0-9]{24}$/', $user), 401);
        return $this->wallets->findByOwner('USER', $user, WalletType::USUARIO);
    }
    private function meta(Request $request): array {
        return ['api_version' => 'v1', 'correlation_id' => app(\App\Domains\Financial\Support\FinancialCorrelation::class)->id()];
    }
    public function index(Request $request) {
        $wallet = $this->wallet($request);
        if (!$wallet) return response()->json(['data'=>[], 'meta'=>array_merge($this->meta($request),
            ['total'=>0,'current_page'=>(int)$request->query('page',1),'per_page'=>(int)$request->query('per_page',20),'last_page'=>1])]);
        $page = $this->receipts->listing($wallet, (int)$request->query('per_page',20), (int)$request->query('page',1));
        // Do not serialize SQL internals or allow any other user's balances.
        $rows = collect($page->items())->map(fn ($row) => ['id'=>strtolower($row->operation_id),
            'source'=>$row->source,'created_at'=>$row->created_at])->all();
        return response()->json(['data'=>$rows,'meta'=>array_merge($this->meta($request),
            ['total'=>$page->total(),'current_page'=>$page->currentPage(),'per_page'=>$page->perPage(),'last_page'=>$page->lastPage()])]);
    }
    public function show(Request $request, string $source, string $operationId) {
        $wallet = $this->wallet($request); abort_unless($wallet,404);
        $receipt = match($source) {
            'transaction'=>$this->receipts->transaction($wallet,$operationId),
            'purchase'=>$this->receipts->purchase($wallet,$operationId), default=>abort(404),
        };
        return response()->json(['data'=>$receipt,'meta'=>$this->meta($request)]);
    }
}
