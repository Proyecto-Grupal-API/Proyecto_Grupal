<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\PermisoGestionOrganizaciones;
use App\Models\RolOrganizacion;
use App\Models\StaffEvento;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ResponsabilidadesComunidad
{
    // Mismo bloqueo en bajas, asignaciones y recuperación: evita asignar a una cuenta mientras se elimina.
    public function ejecutar(callable $accion): mixed
    {
        try {
            return Cache::lock('comunidad:responsabilidades', 120)->block(5, $accion);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['password' => 'Otra operación de responsables está en curso. Intenta nuevamente.']);
        }
    }

    public function eliminarCuenta(User $user, callable $antesDeEliminar): void
    {
        $this->ejecutar(function () use ($user, $antesDeEliminar) {
            if (RolOrganizacion::where('usuario_id', (string) $user->id)->where('slug_rol', 'presidencia')->whereNull('eliminado_en')->exists()) {
                throw ValidationException::withMessages(['password' => 'Transfiere o regulariza la presidencia de tus organizaciones antes de eliminar tu cuenta.']);
            }
            foreach ([MiembroOrganizacion::class, RolOrganizacion::class, StaffEvento::class] as $modelo) {
                foreach ($modelo::where('usuario_id', (string) $user->id)->whereNull('eliminado_en')->get() as $doc) {
                    $antes = $doc->toArray();
                    $doc->fill(['eliminado_en' => now()]);
                    if ($doc instanceof MiembroOrganizacion) {
                        $doc->estado = 'inactivo';
                    }
                    $doc->save();
                    AuditoriaComunidad::create(['organizacion_id' => $doc->organizacion_id, 'usuario_id' => (string) $user->id,
                        'accion' => 'cuenta_baja_responsabilidad', 'entidad_id' => (string) $doc->id,
                        'antes' => $antes, 'despues' => ['tipo' => $doc->getTable(), 'eliminado_en' => now()]]);
                }
            }
            foreach (PermisoGestionOrganizaciones::where('usuario_id', (string) $user->id)->where('activo', true)->get() as $permiso) {
                $antes = $permiso->toArray();
                $permiso->update(['activo' => false]);
                AuditoriaComunidad::create(['organizacion_id' => null, 'usuario_id' => (string) $user->id, 'accion' => 'gestor_revocado_por_baja', 'entidad_id' => (string) $permiso->id, 'antes' => $antes, 'despues' => ['activo' => false]]);
            }
            $antesDeEliminar(); // Logout renueva remember_token mientras el usuario todavía existe.
            $user->delete();
        });
    }
}
