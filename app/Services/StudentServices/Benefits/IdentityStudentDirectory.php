<?php
namespace App\Services\StudentServices\Benefits;

use App\Services\ClienteModuloHttp;
use RuntimeException;

class IdentityStudentDirectory implements StudentDirectory
{
    public function status(string $studentId): string
    {
        try {
        $r = app(ClienteModuloHttp::class)->para('identidad', 'students:read')
            ->get('/api/v1/students/'.rawurlencode($studentId).'/status');
        if ($r->status() === 404) { return 'not_found'; }
        $r->throw();
        $status = $r->json('data.status');
        if (! is_string($status)) { throw new RuntimeException('Respuesta académica inválida de Identidad.'); }
        return $status === 'active' ? 'active' : 'inactive';
        } catch (\Throwable $e) {
            throw new BenefitException('identidad_no_disponible', 'No se pudo consultar el estado académico con Identidad.', 503);
        }
    }
}
