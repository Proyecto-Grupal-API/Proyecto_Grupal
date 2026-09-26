<?php

namespace App\Console\Commands;

use App\Models\AuditoriaComunidad;
use App\Models\PermisoGestionOrganizaciones;
use App\Models\User;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GestorOrganizaciones extends Command
{
    protected $signature = 'comunidad:gestor-organizaciones {matricula} {--revocar} {--operador=} {--motivo=}';

    protected $description = 'Otorga o revoca únicamente gestión del registro de organizaciones; no da presidencia ni acceso a expedientes.';

    public function handle(ResponsabilidadesComunidad $responsabilidades): int
    {
        try {
            $d = Validator::make(['operador' => $this->option('operador'), 'motivo' => $this->option('motivo')], ['operador' => ['required', 'string', 'min:3', 'max:150'], 'motivo' => ['required', 'string', 'min:10', 'max:500']])->validate();
            $responsabilidades->ejecutar(function () use ($d) {
                $u = User::where('matricula', $this->argument('matricula'))->first();
                if (! $u) {
                    throw ValidationException::withMessages(['matricula' => 'La matrícula no corresponde a un usuario registrado.']);
                }
                $p = PermisoGestionOrganizaciones::firstOrNew(['usuario_id' => (string) $u->id]);
                $activo = ! $this->option('revocar');
                if ($p->exists && $p->activo === $activo) {
                    return;
                }
                $antes = $p->exists ? $p->toArray() : null;
                $p->fill([...$d, 'activo' => $activo])->save();
                AuditoriaComunidad::create(['organizacion_id' => null, 'usuario_id' => null, 'accion' => $activo ? 'gestor_habilitado' : 'gestor_revocado', 'entidad_id' => (string) $p->id, 'antes' => $antes, 'despues' => [...$p->toArray(), 'canal' => 'consola']]);
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $mensajes) {
                foreach ($mensajes as $m) {
                    $this->error($m);
                }
            }

            return self::FAILURE;
        }
        $this->info('Permiso de gestión actualizado.');

        return self::SUCCESS;
    }
}
