<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Todas las escrituras se invocan bajo el bloqueo por evento del controlador. */
class InscripcionesEvento
{
    public function inscribir(Evento $evento, User $user): RegistroEvento
    {
        if ($evento->estado !== 'publicado' || ! $evento->fecha_inicio_registro || ! $evento->fecha_fin_registro || now()->lt($evento->fecha_inicio_registro) || now()->gte($evento->fecha_fin_registro)) {
            throw ValidationException::withMessages(['evento' => 'La inscripción no está abierta.']);
        }
        $reg = RegistroEvento::firstOrNew(['evento_id' => (string) $evento->id, 'usuario_id' => (string) $user->id]);
        if ($reg->exists && in_array($reg->estado, ['confirmada', 'espera'], true)) {
            return $reg;
        }
        $this->promover($evento, $user);
        $estado = RegistroEvento::where('evento_id', (string) $evento->id)->where('estado', 'confirmada')->count() < $evento->capacidad ? 'confirmada' : 'espera';
        if ($estado === 'espera' && ! $evento->lista_espera) {
            throw ValidationException::withMessages(['evento' => 'Se agotaron los lugares y no hay lista de espera.']);
        }
        $antes = $reg->exists ? $reg->toArray() : null;
        $reg->fill(['estado' => $estado, 'estado_pago' => $evento->costo > 0 ? 'pendiente' : 'exento', 'monto_centavos' => (int) round($evento->costo * 100), 'moneda' => 'MXN', 'token_qr' => Str::random(64), 'estado_asistencia' => 'pendiente', 'registrado_en' => now(), 'cancelado_en' => null, 'confirmado_en' => $estado === 'confirmada' ? now() : null, 'asistio_en' => null, 'asistencia_por' => null])->save();
        $this->auditar($evento, $user, 'evento_inscripcion', $reg->id, $antes, $reg->toArray());

        return $reg;
    }

    public function cancelar(Evento $evento, User $user): void
    {
        $reg = RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', (string) $user->id)->firstOrFail();
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

    public function promover(Evento $evento, User $actor): void
    {
        if ($evento->estado !== 'publicado' || now()->gte($evento->fecha_hora_inicio) || ! Organizacion::where('_id', $evento->organizacion_id)->where('estado', 'activa')->exists()) {
            return;
        }
        $libres = $evento->capacidad - RegistroEvento::where('evento_id', (string) $evento->id)->where('estado', 'confirmada')->count();
        if ($libres <= 0) {
            return;
        }
        $espera = RegistroEvento::where('evento_id', (string) $evento->id)->where('estado', 'espera')->orderBy('registrado_en')->orderBy('_id')->get();
        $users = User::whereIn('_id', $espera->pluck('usuario_id'))->pluck('id');
        $ids = $espera->whereIn('usuario_id', $users)->take($libres)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        RegistroEvento::whereIn('_id', $ids)->where('estado', 'espera')->update(['estado' => 'confirmada', 'confirmado_en' => now()]);
        $this->auditar($evento, $actor, 'evento_lista_promovida', $evento->id, null, ['registros' => $ids->all()]);
    }

    public function auditar(Evento $e, User $u, string $accion, string $id, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => (string) $e->organizacion_id, 'usuario_id' => (string) $u->id, 'accion' => $accion, 'entidad_id' => $id, 'antes' => $antes, 'despues' => $despues]);
    }
}
