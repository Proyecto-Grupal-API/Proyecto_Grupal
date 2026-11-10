<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Models\MarketplacePaymentRequest;
use Illuminate\Http\Request;
use LogicException;

/** Request-scoped capability: valid only during this native confirmation; always cleared. */
final class InstitutionalCheckoutConsent
{
    private ?array $active=null;
    public function within(Request $request,MarketplacePaymentRequest $row,array $expected,callable $work):mixed
    {
        if($this->active!==null)throw new LogicException('Ya existe una confirmacion institucional en curso.');
        $this->active=['request'=>$request,'row'=>$row,'expected'=>$expected];
        try{return $work();}finally{$this->active=null;}
    }
    public function allows(string $actor,array $context):bool
    {
        if($this->active===null)return false;
        $s=app(MarketplaceCheckoutService::class);if(!$s->becaEnabled())return false;
        $r=$this->active['request'];$row=$this->active['row'];$expected=$this->active['expected'];
        $keys=['wallet_id','buyer_id','bonus_id','amount_cents','currency','category_id','authorization_reference','marketplace','budget_id'];
        if(count($expected)!==count($keys)||array_diff($keys,array_keys($expected))||count($context)!==count($expected))return false;
        foreach($expected as$key=>$value)if(!array_key_exists($key,$context)||$context[$key]!==$value)return false;
        if($r->input('payment_method')!=='BECA' || strtolower((string)$r->input('bonus_id'))!==$expected['bonus_id'])return false;
        if($actor!==$expected['buyer_id'] || !validator(['consent'=>$r->input('consent')],['consent'=>['required','accepted']])->passes())return false;
        if($expected['buyer_id']!==$row->buyer_id || $expected['amount_cents']!==$row->amount_cents || $expected['currency']!==$row->currency
            || $expected['category_id']!==null || $expected['marketplace']!==['order_id'=>$row->order_id,'business_id'=>$row->business_id,'items'=>$row->payload['items']??[]])return false;
        if(app(InstitutionalBudgetReadAccess::class)->actor($r)!==$actor || !$s->passwordRecent($r))return false;
        $s->merchant($row->client_id,$row->business_id);
        $current=MarketplacePaymentRequest::where('public_id',$row->public_id)->first();
        if(!$current || $s->status($current)!=='PENDING' || $current->buyer_id!==$actor
            || $current->client_id!==$row->client_id || $current->business_id!==$row->business_id || $current->order_id!==$row->order_id
            || $current->amount_cents!==$row->amount_cents || $current->currency!==$row->currency
            || !hash_equals($current->request_hash,$row->request_hash) || $current->payload!==$row->payload)return false;
        if(!hash_equals('checkout-beca:'.(string)$r->input('funding_review_token'),$expected['authorization_reference']))return false;
        return hash_equals($s->reviewToken($r,$row),(string)$r->input('review_token'))
            && hash_equals($s->becaReviewToken($r,$row,$expected['bonus_id'],$expected['budget_id']),(string)$r->input('funding_review_token'));
    }
}
