<?php
namespace App\Domains\Financial\Integrations\Team4;
final class WebhookSigner {
    public function headers(string $body,string $path,int $timestamp,string $secret): array {
        $payload=$timestamp.'|POST|'.$path.'|'.hash('sha256',$body);
        return ['X-Team-Timestamp'=>(string)$timestamp,'X-Team-Signature'=>hash_hmac('sha256',$payload,$secret),
            'Accept'=>'application/json','Content-Type'=>'application/json'];
    }
}
