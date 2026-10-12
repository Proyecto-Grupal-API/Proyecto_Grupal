<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\LegacyCashReceiptReviewService;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use Illuminate\Http\Request;
class LegacyCashReceiptReviewController extends Controller {
    public function store(Request $request,LegacyCashReceiptReviewService $reviews){
        abort_unless($request->user()->two_factor_secret&&$request->user()->two_factor_confirmed_at,403,'Se requiere segundo factor confirmado.');
        $d=$request->validate(['transaction_id'=>'required|uuid','wallet_id'=>'required|uuid','reason'=>'required|string|max:1000',
            'actor_id'=>'prohibited','reviewed_by'=>'prohibited','classification'=>'prohibited']);
        try{$review=$reviews->classify((string)$request->user()->getKey(),$d['transaction_id'],$d['wallet_id'],$d['reason']);}
        catch(FinancialDependencyUnavailableException $e){report($e);return response()->json(['message'=>'La autorización histórica no está disponible.'],503);}
        return response()->json(['data'=>['id'=>strtolower($review->public_id),'classification'=>$review->classification,'reviewed_at'=>$review->reviewed_at->toISOString()]]);
    }
}
