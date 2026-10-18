<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IdentidadComunidadApi
{
    public function estado(string $usuarioId): array
    {
        $data = app(ClienteModuloHttp::class)->para('identidad_comunidad', 'students:read')
            ->get('/api/v1/students/'.rawurlencode($usuarioId).'/status')->throw()->json('data');
        if (! is_array($data) || ($data['student_id'] ?? null) !== $usuarioId
            || ($data['user_id'] ?? null) !== $usuarioId
            || ! in_array($data['status'] ?? null, ['active', 'inactive', 'suspended', 'restricted', 'leave', 'graduated'], true)
            || ! array_key_exists('restrictions', $data) || $data['restrictions'] !== null
            || ! array_key_exists('benefits_eligible', $data) || $data['benefits_eligible'] !== null) {
            throw new RuntimeException('Respuesta de Identidad inválida.');
        }
        $textos = ['name', 'enrollment', 'program', 'campus', 'status_label', 'effective_from', 'status_reason'];
        foreach ($textos as $campo) {
            if (isset($data[$campo]) && ! is_string($data[$campo])) {
                throw new RuntimeException('Respuesta de Identidad inválida.');
            }
        }
        if (isset($data['semester']) && ! is_int($data['semester'])) {
            throw new RuntimeException('Respuesta de Identidad inválida.');
        }

        // E1 publishes academic labels, not institutional scopes or benefit decisions.
        return Arr::only($data, ['student_id', 'user_id', ...$textos, 'semester', 'status', 'restrictions', 'benefits_eligible']);
    }

    public function credencialNfc(string $uid): array
    {
        $response = app(ClienteModuloHttp::class)->para('identidad_comunidad', 'identity:nfc:validate')
            ->post('/api/v1/identity/nfc-validate', ['credential_uid' => trim($uid)]);
        if ($response->status() === 422) {
            throw ValidationException::withMessages(['credential_uid' => 'La credencial no está vigente o no pudo validarse.']);
        }
        $response->throw();
        $id = $response->json('identity.user_id');
        $name = $response->json('identity.name');
        $enrollment = $response->json('identity.student.enrollment_number');
        if ($response->json('ok') !== true || $response->json('result') !== 'valid'
            || $response->json('credential.status') !== 'active'
            || ! is_string($id) || ! preg_match('/^[a-f0-9]{24}$/', $id)
            || $response->json('student_id') !== $id || ! is_string($name) || trim($name) === '' || strlen($name) > 1000
            || ($enrollment !== null && ! is_string($enrollment))) {
            throw new RuntimeException('Respuesta de credencial de Identidad inválida.');
        }

        return ['user_id' => $id, 'name' => $name, 'matricula' => $enrollment];
    }
}
