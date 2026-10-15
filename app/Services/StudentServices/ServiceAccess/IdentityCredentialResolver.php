<?php
namespace App\Services\StudentServices\ServiceAccess;

use App\Services\ClienteModuloHttp;
use App\Services\StudentServices\Benefits\StudentDirectory;

class IdentityCredentialResolver implements StudentCredentialResolver
{
    public function resolve(string $credential, string $method): ?array
    {
        $method = strtolower($method);
        if (! in_array($method, ['qr', 'nfc', 'manual'], true)) { return null; }
        if ($method === 'manual') {
            if (! preg_match('/^[a-f0-9]{24}$/i', $credential)) { return null; }
            $r = app(ClienteModuloHttp::class)->para('identidad', 'students:read')
                ->get('/api/v1/students/'.rawurlencode($credential).'/status');
            if ($r->status() === 404) { return null; }
            $r->throw();
            $id = $r->json('data.user_id');
            if (! is_string($id)) { return null; }
            return ['student_id' => $id, 'name' => (string) $r->json('data.name'), 'status' => $r->json('data.status') === 'active' ? 'active' : 'inactive'];
        }
        $r = app(ClienteModuloHttp::class)->para('identidad', 'identity:'.$method.':validate')
            ->post('/api/v1/identity/'.$method.'-validate', $method === 'qr'
                ? ['code' => $credential, 'context' => 'servicios-estudiante'] : ['credential_uid' => $credential]);
        if ($r->status() === 422) { return null; }
        $r->throw();
        $id = $r->json('identity.user_id');
        if ($r->json('ok') !== true || ! is_string($id)) { return null; }
        return ['student_id' => $id, 'name' => (string) $r->json('identity.name'),
            'status' => app(StudentDirectory::class)->status($id)];
    }
}
