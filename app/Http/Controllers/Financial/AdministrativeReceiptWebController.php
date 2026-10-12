<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\AdministrativeReceiptQueryService;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Illuminate\Http\Request;
use Inertia\Inertia;
class AdministrativeReceiptWebController extends Controller
{
    public function index() {
        return Inertia::render('Financial/AdministrativeReceipts');
    }
    private function receipt(Request $request, AdministrativeReceiptQueryService $receipts):array {
        abort_unless($request->user()->two_factor_secret && $request->user()->two_factor_confirmed_at,403,'Se requiere segundo factor confirmado.');
        $data=$request->validate(['source'=>'required|in:transaction,purchase','operation_id'=>'required|uuid',
            'wallet_id'=>'required|uuid','scope_type'=>'nullable|in:business,association','scope_id'=>'nullable|string|max:128',
            'actor_id'=>'prohibited','user_id'=>'prohibited','principal'=>'prohibited']);
        $type=$data['scope_type']??null;$id=$data['scope_id']??null;
        if (($type !== null && $type !== '') !== ($id !== null && $id !== '')) abort(422,'El contexto requiere tipo e identificador.');
        $scope=$type ? ['type'=>$type,'id'=>$id] : null;
        try { $receipt=$receipts->show((string)$request->user()->getKey(),$data['source'],$data['operation_id'],$data['wallet_id'],$scope); }
        catch(FinancialDependencyUnavailableException $e){report($e);abort(503,'La autorización administrativa no está disponible.');}
        return $receipt;
    }
    public function show(Request $request,AdministrativeReceiptQueryService $receipts){
        return response()->json(['data'=>$this->receipt($request,$receipts)]);
    }
    public function downloadPdf(Request $request,AdministrativeReceiptQueryService $receipts,\App\Domains\Financial\Services\ReceiptPdfService $pdf){
        return $pdf->download($this->receipt($request,$receipts));
    }
}
