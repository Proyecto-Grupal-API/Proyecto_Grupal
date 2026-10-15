<?php

namespace App\Services;

use App\Models\DelegacionOrganizacion;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;

class PermisosComunidad
{
    public const CAPACIDADES = [
        'comunicacion.gestionar' => 'Gestionar campañas de comunicación',
        'becas.gestionar' => 'Crear y administrar convocatorias de becas',
        'becas.revisar' => 'Revisar solicitudes y dictaminar becas',
    ];

    public function capacidades(?User $usuario, ?Organizacion $org): array
    {
        if (! $usuario || $usuario->trashed() || ! $org
            || ! Organizacion::where('_id', (string) $org->id)->where('estado', 'activa')->exists()
            || ! MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $usuario->id)->exists()) {
            return [];
        }
        $cargos = RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)
            ->where('usuario_id', (string) $usuario->id)->pluck('slug_rol')->all();
        if (in_array('presidencia', $cargos, true)) {
            return array_keys(self::CAPACIDADES);
        }

        $capacidades = [
            ...(in_array('comunicacion', $cargos, true) ? ['comunicacion.gestionar'] : []),
            ...(in_array('responsable_becas', $cargos, true) ? ['becas.revisar'] : []),
        ];
        $delegaciones = app(DelegacionesComunidad::class);
        foreach (DelegacionOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('destinatario_id', (string) $usuario->id)->get() as $d) {
            if ($delegaciones->vigente($d, $org->fresh())) {
                $capacidades = [...$capacidades, ...array_intersect($d->capacidades, array_keys(self::CAPACIDADES))];
            }
        }

        return array_values(array_unique($capacidades));
    }

    public function permite(User $usuario, Organizacion $org, string $capacidad): bool
    {
        return in_array($capacidad, $this->capacidades($usuario, $org), true);
    }
}
