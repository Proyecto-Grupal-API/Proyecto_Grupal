<?php
namespace App\Domains\CampusAuthorization;
use CampusDigital\Authorization\Directory;
use App\Models\User;
final class Module1Directory implements Directory {
    public function account(string $identity): ?array {
        if (!preg_match('/^[a-fA-F0-9]{24}$/',$identity)) return null;
        $u=User::find(strtolower($identity));
        if (!$u) return null;
        return ['identity'=>strtolower((string)$u->getKey()),'active'=>!$u->deleted_at && !$u->account_activation_pending && !$u->must_change_password,'verified'=>$u->hasVerifiedEmail()];
    }
    public function scopeExists(string $type,string $id): bool {
        // Registry is populated only by an authoritative integration, never by request payloads.
        $row=\Illuminate\Support\Facades\DB::connection('mongodb')->table('campus_authorization_contexts')->where('type',$type)->where('reference',$id)->where('active',true)->first();
        if(!$row)return false;
        $data=(array)$row;
        if(($data['source']??null)==='development_fixture'&&!app()->environment('local','testing'))return false;
        return true;
    }
}
