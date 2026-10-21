<?php
namespace App\Domains\Financial\Integrations\Team4;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
final class Team4WebhookDelivery {
    public function __construct(private WebhookSigner $signer){}
    private function destination(): array {
        $url=config('financial_team4.url');$secret=config('financial_team4.secret');$p=is_string($url)?parse_url($url):false;
        if(!$p||!isset($p['host'],$p['scheme'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])
            ||($p['path']??'')!=='/api/equipo4/webhooks/payment-status'
            ||!in_array($p['scheme'],['http','https'],true)||($p['scheme']==='http'&&!app()->environment(['local','testing']))
            ||!is_string($secret)||trim($secret)==='')throw new RuntimeException('Configura URL y secreto de Team4; HTTP solo en local/testing.');
        return [$url,$p['path'],$secret];
    }
    public function run(int $limit=50): int {
        if(!config('financial_team4.enabled'))return 0;
        [$url,$path,$secret]=$this->destination();$limit=max(1,min(100,$limit));$sent=0;
        $streamIds=DB::connection('sqlsrv')->table('financial_team4_outbox as candidate')
            ->where(function($q){$q->where(function($x){$x->whereIn('candidate.state',['PENDING','RETRY'])->where('candidate.available_at','<=',now());})
                ->orWhere(function($x){$x->where('candidate.state','SENDING')->where('candidate.lease_until','<=',now());});})
            ->whereNotExists(function($q){$q->selectRaw('1')->from('financial_team4_outbox as earlier')
                ->whereColumn('earlier.stream_id','candidate.stream_id')->whereColumn('earlier.event_version','<','candidate.event_version')->where('earlier.state','!=','DELIVERED');})
            ->orderBy('candidate.id')->limit($limit)->pluck('candidate.stream_id');
        foreach($streamIds as $streamId){
            $row=$this->claim((int)$streamId);if(!$row)continue;
            $status=null;$code='INTERNAL_ERROR';$retry=false;$wait=null;
            try {
                if(!hash_equals($row->body_hash,hash('sha256',$row->body)))throw new RuntimeException('Body integrity mismatch.');
                $response=Http::withHeaders($this->signer->headers($row->body,$path,time(),$secret))
                    ->withOptions(['allow_redirects'=>false])->connectTimeout(3)->timeout(10)->withBody($row->body,'application/json')->post($url);
                $status=$response->status();
                if($status===202){
                    $ack=$response->json();$ok=is_array($ack)&&($ack['received']??null)===true&&is_string($ack['payment_event_id']??null)&&trim($ack['payment_event_id'])!==''&&strlen($ack['payment_event_id'])<=100;
                    $code=$ok?'ACKNOWLEDGED':'INVALID_ACK';
                }elseif($status>=500&&$status<=599){$retry=true;$code='REMOTE_5XX';}
                elseif($status===429){$retry=true;$code='RATE_LIMITED';$h=$response->header('Retry-After');if(is_string($h)&&ctype_digit($h))$wait=min(7200,(int)$h);}
                else{$code='HTTP_'.$status;}
            }catch(ConnectionException $e){$retry=true;$code='NETWORK_FAILURE';}
            catch(\Throwable $e){$code='LOCAL_FAILURE';logger()->error('Team4 webhook local failure.',['event_id'=>$row->event_id,'exception_type'=>$e::class]);}
            $this->finish($row,$status,$code,$retry,$wait);$sent++;
        }
        return $sent;
    }
    private function claim(int $streamId): ?object {
        return DB::connection('sqlsrv')->transaction(function()use($streamId){
            $db=DB::connection('sqlsrv');$db->table('financial_team4_streams')->where('id',$streamId)->lockForUpdate()->first();
            // Never overtake an unacknowledged event; REVIEW/EXHAUSTED block this stream for operator investigation.
            $row=$db->table('financial_team4_outbox')->where('stream_id',$streamId)->where('state','!=','DELIVERED')->orderBy('event_version')->lockForUpdate()->first();
            if(!$row||in_array($row->state,['REVIEW','EXHAUSTED'],true)||($row->state==='SENDING'&&now()->lt($row->lease_until))||now()->lt($row->available_at))return null;
            if((int)$row->attempts>=6){$db->table('financial_team4_outbox')->where('id',$row->id)->update(['state'=>'EXHAUSTED','last_code'=>'ATTEMPTS_EXHAUSTED','updated_at'=>now()]);return null;}
            $token=(string)Str::uuid();$attempt=(int)$row->attempts+1;
            $db->table('financial_team4_outbox')->where('id',$row->id)->update(['state'=>'SENDING','attempts'=>$attempt,'lease_token'=>$token,'lease_until'=>now()->addSeconds(120),'updated_at'=>now()]);
            $db->table('financial_team4_attempts')->insert(['event_id'=>$row->event_id,'attempt_no'=>$attempt,'lease_token'=>$token,'started_at'=>now()]);
            $row->lease_token=$token;$row->attempts=$attempt;return $row;
        });
    }
    private function finish(object $row,?int $status,string $code,bool $retry,?int $wait): void {
        DB::connection('sqlsrv')->transaction(function()use($row,$status,$code,$retry,$wait){
            $db=DB::connection('sqlsrv');$delivered=$code==='ACKNOWLEDGED';$state=$delivered?'DELIVERED':($retry?($row->attempts>=6?'EXHAUSTED':'RETRY'):'REVIEW');
            $backoff=[5,30,300,1800,7200];$seconds=max($backoff[min(4,$row->attempts-1)],$wait??0);
            $count=$db->table('financial_team4_outbox')->where('id',$row->id)->where('state','SENDING')->where('lease_token',$row->lease_token)
                ->update(['state'=>$state,'last_code'=>$code,'lease_until'=>null,'lease_token'=>null,'available_at'=>now()->addSeconds($seconds),'delivered_at'=>$delivered?now():null,'updated_at'=>now()]);
            $db->table('financial_team4_attempts')->where('event_id',$row->event_id)->where('attempt_no',$row->attempts)
                ->update(['finished_at'=>now(),'http_status'=>$status,'result_code'=>$count?$code:'LEASE_LOST']);
        });
    }
}
