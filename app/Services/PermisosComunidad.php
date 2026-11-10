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

    private const CAPACIDADES_CAJA = [
        'caja.consultar' => 'Consultar caja y comprobantes de la organización',
        'caja.operar' => 'Abrir y operar turnos, recargar, retirar y cerrar caja',
        'caja.administrar' => 'Administrar cajas y políticas de aprobación',
        'caja.revisar' => 'Revisar solicitudes de aprobación de caja',
        'caja.ajustar' => 'Solicitar y ejecutar ajustes de caja',
        'caja.recuperar' => 'Solicitar y ejecutar recuperaciones de retiros',
    ];

    private const CASH_ACTIONS = [
        'read' => ['caja.consultar', 'caja.operar', 'caja.administrar', 'caja.revisar', 'caja.ajustar', 'caja.recuperar'],
        'operate' => ['caja.operar'],
        'close' => ['caja.operar'],
        'manage' => ['caja.administrar'],
        'approval_manage' => ['caja.administrar'],
        'approval_read' => ['caja.administrar', 'caja.revisar'],
        'approval_review' => ['caja.revisar'],
        'adjust' => ['caja.ajustar'],
        'recover' => ['caja.recuperar'],
    ];

    public function delegables(Organizacion $org): array
    {
        return $this->cajaHabilitada($org) ? [...self::CAPACIDADES, ...self::CAPACIDADES_CAJA] : self::CAPACIDADES;
    }

    private function cajaHabilitada(Organizacion $org): bool
    {
        return in_array($org->tipo, ['asociacion', 'consejo'], true)
            && in_array((string) $org->id, config('financial.cash.community_associations', []), true);
    }

    public function permiteAccionCaja(string $usuarioId, string $organizacionId, string $accion): bool
    {
        $capabilities = self::CASH_ACTIONS[$accion] ?? [];
        $org = $capabilities !== [] ? Organizacion::find($organizacionId) : null;

        return $org && $this->cajaHabilitada($org)
            && array_intersect($capabilities, $this->capacidadesDe($usuarioId, $org)) !== [];
    }

    public function capacidades(?User $usuario, ?Organizacion $org): array
    {
        return $usuario && ! $usuario->trashed() && $org
            ? $this->capacidadesDe((string) $usuario->id, $org)
            : [];
    }

    private function capacidadesDe(string $usuarioId, Organizacion $org): array
    {
        if (! Organizacion::where('_id', (string) $org->id)->where('estado', 'activa')->exists()
            || ! MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', $usuarioId)->exists()) {
            return [];
        }
        $cargos = RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)
            ->where('usuario_id', $usuarioId)->pluck('slug_rol')->all();
        $capacidades = in_array('presidencia', $cargos, true) ? array_keys(self::CAPACIDADES) : [
            ...(in_array('comunicacion', $cargos, true) ? ['comunicacion.gestionar'] : []),
            ...(in_array('responsable_becas', $cargos, true) ? ['becas.revisar'] : []),
        ];
        if ($this->cajaHabilitada($org) && in_array('tesoreria', $cargos, true)) {
            $capacidades[] = 'caja.consultar';
        }
        $delegables = array_keys($this->delegables($org));
        $delegaciones = app(DelegacionesComunidad::class);
        foreach (DelegacionOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('destinatario_id', $usuarioId)->get() as $d) {
            if ($delegaciones->vigente($d, $org->fresh())) {
                $capacidades = [...$capacidades, ...array_intersect($d->capacidades, $delegables)];
            }
        }

        return array_values(array_unique($capacidades));
    }

    public function permite(User $usuario, Organizacion $org, string $capacidad): bool
    {
        return in_array($capacidad, $this->capacidades($usuario, $org), true);
    }
}
