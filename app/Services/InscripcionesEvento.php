<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Todas las escrituras se invocan bajo el bloqueo por evento del controlador. */
class InscripcionesEvento
{
    public function inscribir(Evento $evento, User $user, bool $nuevoIntento = false): RegistroEvento
    {
        $this->sincronizar($evento, $user);
        if ($evento->estado !== 'publicado' || ! $evento->fecha_inicio_registro || ! $evento->fecha_fin_registro || now()->lt($evento->fecha_inicio_registro) || now()->gte($evento->fecha_fin_registro)) {
            throw ValidationException::withMessages(['evento' => 'La inscripción no está abierta.']);
        }
        $reg = RegistroEvento::firstOrNew(['evento_id' => (string) $evento->id, 'usuario_id' => (string) $user->id]);
        if ($reg->exists && in_array($reg->estado, ['confirmada', 'espera'], true)) {
            return $reg;
        }
        if ($reg->estado === 'vencida' && ! $nuevoIntento) {
            return $reg;
        }
        $estado = RegistroEvento::where('evento_id', (string) $evento->id)->conCupo()->count() < $evento->capacidad ? 'confirmada' : 'espera';
        if ($estado === 'espera' && ! $evento->lista_espera) {
            throw ValidationException::withMessages(['evento' => 'Se agotaron los lugares y no hay lista de espera.']);
        }
        $antes = $reg->exists ? $reg->toArray() : null;
        $reg->fill(['reserva_hasta' => $estado === 'confirmada' && $evento->costo > 0 ? $this->limite($evento) : null, 'vencido_en' => null, 'estado' => $estado, 'estado_pago' => $evento->costo > 0 ? 'pendiente' : 'exento', 'monto_centavos' => (int) round($evento->costo * 100), 'moneda' => 'MXN', 'token_qr' => Str::random(64), 'estado_asistencia' => 'pendiente', 'registrado_en' => now(), 'cancelado_en' => null, 'confirmado_en' => $estado === 'confirmada' ? now() : null, 'asistio_en' => null, 'asistencia_por' => null])->save();
        $this->auditar($evento, $user, 'evento_inscripcion', $reg->id, $antes, $reg->toArray());

        return $reg;
    }

    public function cancelar(Evento $evento, User $user): void
    {
        $this->sincronizar($evento, $user);
        $reg = RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', (string) $user->id)->firstOrFail();
        if ($reg->estado === 'vencida') {
            return;
        }
        if ($reg->estado === 'cancelada') {
            $this->promover($evento, $user);

            return;
        }
        if (now()->gte($evento->fecha_hora_inicio) || $reg->estado_asistencia === 'asistio') {
            throw ValidationException::withMessages(['evento' => 'No puedes cancelar después del inicio o de registrar asistencia.']);
        }
        if ($reg->estado_pago === 'pagado') {
            throw ValidationException::withMessages(['evento' => 'Esta reserva requiere gestionar su devolución con el equipo de pagos.']);
        }
        $antes = $reg->toArray();
        $reg->update(['estado' => 'cancelada', 'cancelado_en' => now(), 'token_qr' => Str::random(64)]);
        $this->auditar($evento, $user, 'evento_inscripcion_cancelada', $reg->id, $antes, $reg->toArray());
        $this->promover($evento, $user);
    }

    public function promover(Evento $evento, ?User $actor): void
    {
        if ($evento->estado !== 'publicado' || now()->gte($evento->fecha_hora_inicio) || ! Organizacion::where('_id', $evento->organizacion_id)->where('estado', 'activa')->exists()) {
            return;
        }
        $libres = $evento->capacidad - RegistroEvento::where('evento_id', (string) $evento->id)->conCupo()->count();
        if ($libres <= 0) {
            return;
        }
        $espera = RegistroEvento::where('evento_id', (string) $evento->id)->where('estado', 'espera')->orderBy('registrado_en')->orderBy('_id')->get();
        $users = User::whereIn('_id', $espera->pluck('usuario_id'))->pluck('id');
        $ids = $espera->whereIn('usuario_id', $users)->take($libres)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        foreach ($espera->whereIn('id', $ids) as $reg) {
            $reg->update(['estado' => 'confirmada', 'confirmado_en' => now(), 'reserva_hasta' => $reg->estado_pago === 'pendiente' ? $this->limite($evento) : null]);
        }
        $this->auditar($evento, $actor, 'evento_lista_promovida', $evento->id, null, ['registros' => $ids->all()]);
    }

    public function ejecutar(string $id, callable $accion): mixed
    {
        $id = strtolower($id);
        return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => Cache::store(config('comunidad.eventos_lock_store'))
            ->lock('evento:'.$id, 30)->block(5, fn () => DB::connection('mongodb')->transaction(fn () => $accion(Evento::findOrFail($id)))));
    }

    public function sincronizar(Evento $evento, ?User $actor = null): void
    {
        foreach (RegistroEvento::where('evento_id', (string) $evento->id)->pendientesDeVencer()->get() as $reg) {
            if (! $reg->reserva_hasta) {
                $antes = $reg->toArray();
                $reg->update(['reserva_hasta' => $this->limite($evento)]);
                $this->auditar($evento, $actor, 'evento_reserva_plazo_asignado', (string) $reg->id, $antes, $reg->toArray());
            }
            if ($reg->estado_efectivo === 'vencida') {
                $antes = $reg->toArray();
                $reg->update(['estado' => 'vencida', 'vencido_en' => now(), 'token_qr' => Str::random(64)]);
                $this->auditar($evento, $actor, 'evento_reserva_vencida', (string) $reg->id, $antes, $reg->toArray());
            }
        }
        $this->promover($evento, $actor);
    }

    private function limite(Evento $evento)
    {
        return now()->addMinutes(15)->min($evento->fecha_hora_inicio);
    }

    public function auditar(Evento $e, ?User $u, string $accion, string $id, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => (string) $e->organizacion_id, 'usuario_id' => $u ? (string) $u->id : null, 'accion' => $accion, 'entidad_id' => $id, 'antes' => $antes, 'despues' => $despues]);
    }
}
