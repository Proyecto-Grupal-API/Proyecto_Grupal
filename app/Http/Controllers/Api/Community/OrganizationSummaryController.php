<?php

namespace App\Http\Controllers\Api\Community;

use App\Http\Controllers\Controller;
use App\Models\Organizacion;
use Illuminate\Http\JsonResponse;
use UnexpectedValueException;

class OrganizationSummaryController extends Controller
{
    public function __invoke(string $organizationId): JsonResponse
    {
        $organization = Organizacion::findOrFail($organizationId, ['nombre', 'tipo', 'estado']);

        return response()->json([
            'id' => (string) $organization->id,
            'type' => match ($organization->tipo) {
                'asociacion' => 'association',
                'consejo' => 'council',
                default => throw new UnexpectedValueException('Unsupported organization type.'),
            },
            'name' => $organization->nombre,
            'status' => match ($organization->estado) {
                'activa' => 'active',
                'suspendida' => 'suspended',
                'configurando' => 'configuring',
                default => throw new UnexpectedValueException('Unsupported organization status.'),
            },
        ])->header('Cache-Control', 'private, no-store');
    }
}
