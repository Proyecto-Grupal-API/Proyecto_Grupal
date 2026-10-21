<?php
namespace App\Domains\Financial\Integrations\Team4;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
final class PaymentEventOutbox {
    /** Trusted domain hook only. Call inside the SAME sqlsrv transaction as the financial change. */
    public function record(string $sourceReference,array $input): string {
        if(DB::connection('sqlsrv')->transactionLevel()<1)throw new LogicException('El evento requiere la transacción financiera sqlsrv.');
        if($sourceReference===''||strlen($sourceReference)>255)throw new LogicException('Referencia de origen obligatoria.');
        $data=PaymentEventPayload::canonical($input);$hash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));$key=hash('sha256',$sourceReference);
        $db=DB::connection('sqlsrv');
        // SQL uniqueness plus transaction: first creation races fail safely and the caller retries its transaction.
        if(!$db->table('financial_team4_streams')->where('payment_intent_id',$data['payment_intent_id'])->exists()){
            $db->table('financial_team4_streams')->insert(['payment_intent_id'=>$data['payment_intent_id'],'order_id'=>$data['order_id'],
                'business_id'=>$data['business_id'],'last_version'=>0,'created_at'=>now(),'updated_at'=>now()]);
        }
        $stream=$db->table('financial_team4_streams')->where('payment_intent_id',$data['payment_intent_id'])->lockForUpdate()->first();
        if($stream->order_id!==$data['order_id']||$stream->business_id!==$data['business_id'])throw new ConflictHttpException('La intención ya pertenece a otro pedido o negocio.');
        $existing=$db->table('financial_team4_outbox')->where('source_key',$key)->first();
        if($existing){if(!hash_equals($existing->request_hash,$hash))throw new ConflictHttpException('La referencia ya se utilizó con otro evento.');return strtolower((string) $existing->event_id);}
        $id=(string)Str::uuid();$version=(int)$stream->last_version+1;$body=PaymentEventPayload::body($data,$id,$version);
        $db->table('financial_team4_outbox')->insert(['event_id'=>$id,'stream_id'=>$stream->id,'event_version'=>$version,'source_key'=>$key,
            'request_hash'=>$hash,'body'=>$body,'body_hash'=>hash('sha256',$body),'state'=>'PENDING','attempts'=>0,'available_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $db->table('financial_team4_streams')->where('id',$stream->id)->update(['last_version'=>$version,'updated_at'=>now()]);
        return $id;
    }
}
