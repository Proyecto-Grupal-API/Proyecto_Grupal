<?php
namespace App\Domains\CampusAuthorization;
use CampusDigital\Authorization\Store;
use Illuminate\Support\Facades\DB;
final class MongoStore implements Store {
    private int $depth=0;
    private array $original=[];
    public function transaction(callable $work): mixed {
        if ($this->depth>0) return $work();
        $this->depth++; $this->original=[];
        try { return DB::connection('mongodb')->transaction($work); }
        finally { $this->depth--; $this->original=[]; }
    }
    public function get(string $key): ?array {
        $row=DB::connection('mongodb')->table('campus_authorization_state')->where('key',$key)->first();
        $payload=$row ? (is_array($row)?$row['payload']:$row->payload) : null;
        if ($this->depth>0 && !array_key_exists($key,$this->original)) $this->original[$key]=$payload;
        return $payload===null ? null : json_decode($payload,true,512,JSON_THROW_ON_ERROR);
    }
    public function put(string $key,array $value): void {
        if ($this->depth===0) throw new \LogicException('Authorization writes require a transaction.');
        if (!array_key_exists($key,$this->original)) $this->get($key);
        $payload=json_encode($value,JSON_THROW_ON_ERROR);
        $q=DB::connection('mongodb')->table('campus_authorization_state');
        if ($this->original[$key]===null) $q->insert(['key'=>$key,'payload'=>$payload]);
        else {
            $changed=$q->where('key',$key)->where('payload',$this->original[$key])->update(['payload'=>$payload]);
            if ($changed!==1) throw new AuthorityConflict('AUTHORITY_VERSION_CONFLICT');
        }
        $this->original[$key]=$payload;
    }
}
