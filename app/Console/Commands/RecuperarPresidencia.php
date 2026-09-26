<?php

namespace App\Console\Commands;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecuperarPresidencia extends Command
{
    protected $signature = 'comunidad:recuperar-presidencia {organizacion : Slug de organización} {matricula : Integrante que asumirá el cargo} {--hasta= : Último día de vigencia YYYY-MM-DD} {--motivo= : Justificación de la recuperación} {--operador= : Responsable de ejecutar el mantenimiento}';

    protected $description = 'Recupera una organización sin presidencia efectiva, con auditoría y acceso exclusivo a consola.';

    public function handle(ResponsabilidadesComunidad $responsabilidades): int
    {
        try {
            $data = Validator::make([
                'hasta' => $this->option('hasta'), 'motivo' => $this->option('motivo'), 'operador' => $this->option('operador'),
            ], ['hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'motivo' => ['required', 'string', 'min:10', 'max:500'], 'operador' => ['required', 'string', 'min:3', 'max:150']])->validate();
            $responsabilidades->ejecutar(function () use ($data) {
                $org = Organizacion::where('slug', $this->argument('organizacion'))->whereIn('estado', ['activa', 'suspendida'])->first();
                $user = User::where('matricula', $this->argument('matricula'))->first();
                if (! $org || ! $user || ! MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $user->id)->exists()) {
                    throw ValidationException::withMessages(['responsable' => 'Se requiere una organización activa o suspendida y un usuario existente que sea integrante activo.']);
                }
                $rol = RolOrganizacion::firstOrNew(['organizacion_id' => (string) $org->id, 'slug_rol' => 'presidencia', 'eliminado_en' => null]);
                $titular = $rol->usuario_id ? User::find($rol->usuario_id) : null;
                if ($titular && $rol->vigente && MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $titular->id)->exists()) {
                    throw ValidationException::withMessages(['presidencia' => 'Existe una presidencia vigente. Usa la transferencia desde Organizaciones.']);
                }
                if ($rol->fecha_inicio?->gt(now()) && $titular) {
                    throw ValidationException::withMessages(['presidencia' => 'Hay una presidencia futura programada. Revisa ese periodo antes de recuperar.']);
                }
                $antes = $rol->exists ? $rol->toArray() : null;
                $rol->fill(['usuario_id' => (string) $user->id, 'fecha_inicio' => today(), 'fecha_fin' => $data['hasta'], 'otorgado_por' => null])->save();
                AuditoriaComunidad::create(['organizacion_id' => (string) $org->id, 'usuario_id' => null, 'accion' => 'presidencia_recuperada', 'entidad_id' => (string) $rol->id,
                    'antes' => $antes, 'despues' => ['cargo' => $rol->toArray(), 'canal' => 'consola', 'operador' => $data['operador'], 'motivo' => $data['motivo']]]);
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $this->error($mensaje);
                }
            }

            return self::FAILURE;
        }
        $this->info('Presidencia recuperada. El cambio quedó registrado en auditoría.');

        return self::SUCCESS;
    }
}
