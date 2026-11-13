<?php

namespace App\Services;

use App\Support\StudentIdentityInput;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IdentidadComunidadApi
{
    /** In-process audience contract; user is from the authenticated backend request, not OAuth. */
    public function seleccionarAudienciaAcademica(\Illuminate\Http\Request $request,array $filters): array
    {
        return app(\App\Services\Directory\AcademicAudienceService::class)->select($request,
            (string)config('identity_directory.community_academic_client',''),$filters);
    }

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

    public function resolverMatricula(string $matricula, string $organizacionId): string
    {
        $numero = StudentIdentityInput::normalize(['enrollment_number' => $matricula])['enrollment_number'];
        $data = $this->directorio('resolve-enrollment', 'identity:directory:resolve', $organizacionId, [
            'enrollment_number' => $numero,
            'purpose' => 'membership_management',
        ])->json('data');

        if (in_array($data['result'] ?? null, ['not_found', 'not_usable'], true)) {
            throw ValidationException::withMessages(['matricula' => 'No se encontró una cuenta utilizable con esa matrícula.']);
        }

        $id = $data['identity']['user_id'] ?? null;
        abort_unless(($data['result'] ?? null) === 'resolved' && is_string($id)
            && preg_match('/^[a-f0-9]{24}$/D', $id) === 1 && ($data['identity']['enrollment_number'] ?? null) === $numero,
            503, 'El directorio de Identidad devolvió una referencia inválida.');

        return $id;
    }

    /** @param list<string> $ids */
    public function identidadesOrganizacion(array $ids, string $organizacionId): array
    {
        $identidades = [];
        foreach (array_chunk($ids, 100) as $lote) {
            $items = $this->directorio('batch', 'identity:directory:read identity:directory:enrollment:read', $organizacionId, [
                'user_ids' => $lote,
                'purpose' => 'historical_reference',
                'projection' => ['identity'],
                'include_enrollment' => true,
            ])->json('data.items');

            abort_unless(is_array($items) && array_is_list($items) && count($items) === count($lote),
                503, 'El directorio de Identidad devolvió una lista inválida.');
            foreach ($items as $index => $item) {
                if (in_array($item['result'] ?? null, ['not_found', 'unavailable', 'invalid_id'], true)) {
                    continue;
                }
                $id = $lote[$index];
                $nombre = $item['identity']['display_name'] ?? null;
                $matricula = $item['identity']['enrollment_number'] ?? null;
                abort_unless(($item['result'] ?? null) === 'found' && ($item['requested_user_id'] ?? null) === $id
                    && ($item['identity']['user_id'] ?? null) === $id && is_string($nombre) && trim($nombre) !== ''
                    && ($matricula === null || is_string($matricula)),
                    503, 'El directorio de Identidad devolvió una identidad inválida.');
                $identidades[$id] = ['nombre' => $nombre, 'matricula' => $matricula];
            }
        }

        return $identidades;
    }

    private function directorio(string $ruta, string $scope, string $organizacionId, array $datos): Response
    {
        try {
            $response = app(ClienteModuloHttp::class)->para('identidad_comunidad', $scope)
                ->post('/api/v1/identity/directory/'.$ruta, [...$datos, 'context' => ['type' => 'organization', 'id' => $organizacionId]]);
        } catch (HttpClientException|RuntimeException) {
            abort(503, 'El directorio de Identidad no está disponible para esta operación.');
        }

        if ($ruta === 'resolve-enrollment' && $response->status() === 422) {
            throw ValidationException::withMessages(['matricula' => 'La matrícula no tiene un formato válido.']);
        }
        abort_unless($response->successful(), 503, 'El directorio de Identidad no está disponible para esta operación.');

        return $response;
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
