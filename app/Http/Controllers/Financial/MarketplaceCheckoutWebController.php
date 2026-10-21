<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\MarketplaceCheckoutService;
use App\Domains\Financial\Models\MarketplacePaymentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class MarketplaceCheckoutWebController extends Controller {
    public function index(Request $r,MarketplaceCheckoutService $s){
        if(!$s->enabled())return Inertia::render('Financial/MarketplacePurchases',['enabled'=>false,'requests'=>['data'=>[],'links'=>[]]]);
        $s->session($r);$r->validate(['page'=>'sometimes|integer|min:1|max:100000']);
        $rows=MarketplacePaymentRequest::where('buyer_id',(string)$r->user()->getKey())->orderByDesc('id')->paginate(20);
        $rows->through(fn($row)=>$s->partnerResult($row)+['description'=>$row->payload['description']??'']);
        return Inertia::render('Financial/MarketplacePurchases',['enabled'=>true,'requests'=>$rows]);
    }
    public function show(Request $r,string $paymentRequest,MarketplaceCheckoutService $s){
        $s->session($r);$row=$s->owned($r,$paymentRequest);$merchant=$s->merchant($row->client_id,$row->business_id);
        $recent=$s->passwordRecent($r);
        if(!$recent)$r->session()->put('url.intended',route('financial.marketplace.show',['paymentRequest'=>$row->public_id]));
        return Inertia::render('Financial/MarketplaceCheckout',['paymentRequest'=>$s->partnerResult($row)+$merchant+
            ['description'=>$row->payload['description']??'','items'=>$row->payload['items']??[]],
            'reviewToken'=>$s->reviewToken($r,$row),'passwordRecent'=>$recent]);
    }
    public function confirm(Request $r,string $paymentRequest,MarketplaceCheckoutService $s){
        abort_if(array_diff(array_keys($r->all()),['consent','review_token'])!==[],422,'Campos no admitidos.');
        $d=$r->validate(['consent'=>'required|accepted','review_token'=>'required|string|size:64']);
        try {$row=$s->confirm($r,$paymentRequest,$d['review_token']);}
        catch(InvalidArgumentException $e){return back()->withErrors(['payment'=>$e->getMessage()]);}
        catch(HttpExceptionInterface $e){if($e->getStatusCode()!==409)throw $e;return back()->withErrors(['payment'=>$e->getMessage()]);}
        return redirect()->route('financial.receipts.show',['source'=>'purchase','operationId'=>strtolower($row->purchase_payment_id)]);
    }
    public function cancel(Request $r,string $paymentRequest,MarketplaceCheckoutService $s){
        abort_unless($r->all()===[],422,'Campos no admitidos.');
        try {$s->cancel($r,$paymentRequest);}catch(HttpExceptionInterface $e){if($e->getStatusCode()!==409)throw $e;return back()->withErrors(['payment'=>$e->getMessage()]);}
        return redirect()->route('financial.marketplace.index');
    }
}
