<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarEventoRequest;
use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\RegistroEvento;
use App\Models\User;
use App\Services\InscripcionesEvento;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventoController extends Controller
{
    public function __construct(private InscripcionesEvento $inscripciones) {}

    public function index(Request $request)
    {
        $request->validate(['buscar' => ['nullable', 'string', 'max:120'], 'gestion' => ['nullable', 'boolean'], 'estado' => ['nullable', Rule::in(['borrador', 'publicado', 'cancelado'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $org = $request->attributes->get('organizacion');
        $gestiona = $org && Gate::allows('update', $org);
        $q = Evento::query();
        if ($request->boolean('gestion')) {
            $this->organizacion($request, true);
            $q->where('organizacion_id', (string) $org->id);
            if ($request->filled('estado')) {
                $q->where('estado', $request->estado);
            }
        } else {
            $q->whereIn('organizacion_id', Organizacion::where('estado', 'activa')->pluck('id'))->where('estado', 'publicado')->where('fecha_hora_fin', '>', now());
        }
        if ($request->filled('buscar')) {
            $q->where('titulo', 'like', '%'.$request->buscar.'%');
        }
        $p = $q->orderBy('fecha_hora_inicio')->orderBy('_id')->paginate(12);
        $regs = RegistroEvento::whereIn('evento_id', $p->getCollection()->pluck('id'))->get()->groupBy('evento_id');
        $orgs = Organizacion::whereIn('_id', $p->getCollection()->pluck('organizacion_id'))->get()->keyBy('id');
        $eventos = $p->getCollection()->map(function ($e) use ($regs, $request, $orgs) {
            $todos = $regs->get((string) $e->id, collect());
            $mio = $todos->firstWhere('usuario_id', (string) $request->user()->id);

            return [...$e->toArray(), 'organizacion_nombre' => $orgs->get($e->organizacion_id)?->nombre, 'mi_registro' => $mio ? $this->resumen($mio) : null, 'stats' => ['confirmados' => $todos->where('estado', 'confirmada')->count(), 'espera' => $todos->where('estado', 'espera')->count(), 'asistieron' => $todos->where('estado_asistencia', 'asistio')->count()], 'registro_abierto' => $e->estado === 'publicado' && $e->fecha_inicio_registro && $e->fecha_fin_registro && now()->gte($e->fecha_inicio_registro) && now()->lt($e->fecha_fin_registro)];
        });

        return response()->json(['eventos' => $eventos, 'puede_gestionar' => (bool) $gestiona, 'zona_horaria' => config('comunidad.eventos_timezone'), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function store(GuardarEventoRequest $request)
    {
        $org = $this->organizacion($request, true);
        $e = Evento::create([...$request->datosEvento(), 'organizacion_id' => (string) $org->id, 'slug' => Str::slug($request->titulo).'-'.Str::random(10), 'estado' => 'borrador', 'creado_por' => (string) $request->user()->id]);
        $this->inscripciones->auditar($e, $request->user(), 'evento_creado', $e->id, null, $e->toArray());

        return response()->json(['message' => 'Borrador guardado.', 'evento' => $e], 201);
    }

    public function update(GuardarEventoRequest $request, string $id)
    {
        $data = $request->datosEvento();

        return $this->conEvento($request, $id, true, function ($e) use ($request, $data) {
            $this->editable($e);
            $q = RegistroEvento::where('evento_id', (string) $e->id);
            if ($data['capacidad'] < (clone $q)->where('estado', 'confirmada')->count()) {
                throw ValidationException::withMessages(['capacidad' => 'El cupo no puede ser menor que las reservas confirmadas.']);
            }
            if ((clone $q)->whereIn('estado', ['confirmada', 'espera'])->exists()) {
                foreach (['costo', 'ubicacion', 'fecha_hora_inicio', 'fecha_hora_fin'] as $c) {
                    $igual = str_starts_with($c, 'fecha') ? $data[$c]->equalTo($e->$c) : $data[$c] == $e->$c;
                    if (! $igual) {
                        throw ValidationException::withMessages([$c => 'Con reservas activas no se pueden cambiar el costo, la ubicación ni las fechas del evento.']);
                    }
                }
            }
            $antes = $e->toArray();
            $e->update($data);
            $this->inscripciones->promover($e, $request->user());
            $this->inscripciones->auditar($e, $request->user(), 'evento_editado', $e->id, $antes, $e->toArray());

            return response()->json(['message' => 'Evento actualizado.']);
        });
    }

    public function publicar(Request $request, string $id)
    {
        return $this->conEvento($request, $id, true, function ($e) use ($request) {
            $this->editable($e);
            if (! $e->fecha_fin_registro || now()->gte($e->fecha_fin_registro)) {
                throw ValidationException::withMessages(['evento' => 'Corrige el cierre de inscripción antes de publicar.']);
            }
            if ($e->estado !== 'publicado') {
                $antes = $e->toArray();
                $e->update(['estado' => 'publicado']);
                $this->inscripciones->auditar($e, $request->user(), 'evento_publicado', $e->id, $antes, $e->toArray());
            }

            return response()->json(['message' => 'Evento publicado en el catálogo.']);
        });
    }

    public function cancelarEvento(Request $request, string $id)
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->conEvento($request, $id, true, function ($e) use ($request, $data) {
            if ($e->estado !== 'cancelado') {
                $this->editable($e);
                if (RegistroEvento::where('evento_id', (string) $e->id)->where('estado_asistencia', 'asistio')->exists()) {
                    throw ValidationException::withMessages(['evento' => 'Ya se registró asistencia; no se puede cancelar.']);
                }
                if (RegistroEvento::where('evento_id', (string) $e->id)->where('estado_pago', 'pagado')->where('estado', 'confirmada')->exists()) {
                    throw ValidationException::withMessages(['evento' => 'Hay pagos confirmados; primero coordina su devolución.']);
                }
                $antes = $e->toArray();
                $e->update(['estado' => 'cancelado', 'motivo_cancelacion' => $data['motivo'], 'cancelado_en' => now()]);
                $this->inscripciones->auditar($e, $request->user(), 'evento_cancelado', $e->id, $antes, $e->toArray());
            }
            RegistroEvento::where('evento_id', (string) $e->id)->whereIn('estado', ['confirmada', 'espera'])->update(['estado' => 'cancelada', 'cancelado_en' => now()]);

            return response()->json(['message' => 'Evento cancelado. Sus boletos ya no permiten el acceso.']);
        });
    }

    public function inscribir(Request $request, string $id)
    {
        return $this->conEvento($request, $id, false, function ($e) use ($request) {
            abort_unless($e->estado === 'publicado' && Organizacion::where('_id', $e->organizacion_id)->where('estado', 'activa')->exists(), 404);
            $reg = $this->inscripciones->inscribir($e, $request->user());

            return response()->json(['registro' => $this->resumen($reg), 'message' => $reg->estado === 'espera' ? 'Estás en lista de espera.' : ($reg->estado_pago === 'pendiente' ? 'Lugar reservado. El pago está pendiente de integración.' : 'Inscripción confirmada.')]);
        });
    }

    public function cancelarInscripcion(Request $request, string $id)
    {
        return $this->conEvento($request, $id, false, function ($e) use ($request) {
            $this->inscripciones->cancelar($e, $request->user());

            return response()->json(['message' => 'Inscripción cancelada.']);
        });
    }

    public function inscritos(Request $request, string $id)
    {
        $org = $this->organizacion($request, true);
        Evento::where('organizacion_id', (string) $org->id)->findOrFail($id);
        $request->validate(['estado' => ['nullable', Rule::in(['confirmada', 'espera', 'cancelada'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = RegistroEvento::where('evento_id', $id);
        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }
        $p = $q->orderBy('registrado_en')->orderBy('_id')->paginate(20);
        $users = User::whereIn('_id', $p->getCollection()->pluck('usuario_id'))->get()->keyBy('id');

        return response()->json(['registros' => $p->getCollection()->map(fn ($reg) => [...$this->resumen($reg), 'nombre' => $users->get($reg->usuario_id)?->name ?? 'Usuario no disponible', 'matricula' => $users->get($reg->usuario_id)?->matricula]), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }

    public function boletos(Request $request)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $p = RegistroEvento::where('usuario_id', (string) $request->user()->id)->orderBy('registrado_en', 'desc')->orderBy('_id')->paginate(12);
        $eventos = Evento::whereIn('_id', $p->getCollection()->pluck('evento_id'))->get()->keyBy('id');

        return response()->json(['boletos' => $p->getCollection()->map(fn ($reg) => $this->boleto($reg, $eventos->get($reg->evento_id))), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function miBoleto(Request $request, ?string $id = null)
    {
        $q = RegistroEvento::where('usuario_id', (string) $request->user()->id);
        if ($id) {
            $q->where('evento_id', $id);
        }
        $reg = $q->orderBy('registrado_en', 'desc')->firstOrFail();

        return response()->json($this->boleto($reg, Evento::find($reg->evento_id)))->header('Cache-Control', 'private, no-store');
    }

    public function checkin(Request $request)
    {
        $data = $request->validate(['token_qr' => ['required', 'string', 'max:255'], 'evento_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i']]);

        return $this->conEvento($request, $data['evento_id'], false, function ($e) use ($request, $data) {
            $org = $this->organizacion($request);
            abort_unless((string) $e->organizacion_id === (string) $org->id, 404);
            Gate::authorize('validarAcceso', $e);
            if ($e->estado !== 'publicado' || ! $e->fecha_hora_fin || now()->lt($e->fecha_hora_inicio->copy()->subMinutes(config('comunidad.checkin_minutos_antes'))) || now()->gte($e->fecha_hora_fin)) {
                throw ValidationException::withMessages(['evento' => 'El acceso abre 30 minutos antes y termina al finalizar el evento.']);
            }
            $reg = RegistroEvento::where('evento_id', (string) $e->id)->where('token_qr', trim($data['token_qr']))->where('estado', 'confirmada')->whereIn('estado_pago', ['pagado', 'exento'])->first();
            if (! $reg || $reg->estado_asistencia === 'asistio' || ! User::find($reg->usuario_id)) {
                throw ValidationException::withMessages(['token_qr' => 'Boleto inválido, cancelado, ya utilizado o con pago pendiente.']);
            }
            $antes = $reg->toArray();
            $updated = RegistroEvento::where('_id', (string) $reg->id)->where('estado_asistencia', '!=', 'asistio')->update(['estado_asistencia' => 'asistio', 'asistio_en' => now(), 'asistencia_por' => (string) $request->user()->id]);
            if (! $updated) {
                throw ValidationException::withMessages(['token_qr' => 'El boleto ya fue utilizado.']);
            }
            $this->inscripciones->auditar($e, $request->user(), 'evento_asistencia', $reg->id, $antes, $reg->fresh()->toArray());

            return response()->json(['message' => 'Acceso autorizado. Asistencia registrada.', 'asistente' => User::find($reg->usuario_id)?->only(['name', 'matricula']), 'asistio_en' => $reg->fresh()->asistio_en])->header('Cache-Control', 'private, no-store');
        });
    }

    private function resumen(RegistroEvento $reg): array
    {
        return $reg->only(['id', 'evento_id', 'usuario_id', 'estado', 'estado_pago', 'monto_centavos', 'moneda', 'estado_asistencia', 'registrado_en', 'asistio_en']);
    }

    private function boleto(RegistroEvento $reg, ?Evento $e): array
    {
        $estado = $e?->estado === 'cancelado' ? 'cancelada' : $reg->estado;
        $organizacionActiva = $e && Organizacion::where('_id', $e->organizacion_id)->where('estado', 'activa')->exists();
        $vigente = $organizacionActiva && $e->estado === 'publicado' && $e->fecha_hora_fin?->gt(now());
        $habilitado = $vigente && $estado === 'confirmada' && in_array($reg->estado_pago, ['pagado', 'exento'], true) && $reg->estado_asistencia !== 'asistio';
        $posicion = null;
        if ($estado === 'espera') {
            $idx = RegistroEvento::where('evento_id', $reg->evento_id)->where('estado', 'espera')->orderBy('registrado_en')->orderBy('_id')->pluck('id')->search((string) $reg->id);
            $posicion = $idx === false ? null : $idx + 1;
        }

        return [...$this->resumen($reg), 'estado' => $estado, 'titulo' => $e?->titulo ?? 'Evento no disponible', 'ubicacion' => $e?->ubicacion, 'fecha_hora_inicio' => $e?->fecha_hora_inicio, 'fecha_hora_fin' => $e?->fecha_hora_fin, 'motivo_cancelacion' => $e?->motivo_cancelacion, 'evento_estado' => $e?->estado, 'posicion_espera' => $posicion, 'organizacion_activa' => (bool) $organizacionActiva, 'qr_habilitado' => $habilitado, 'token_qr' => $habilitado ? $reg->token_qr : null, 'puede_cancelar' => $e && now()->lt($e->fecha_hora_inicio) && in_array($estado, ['confirmada', 'espera'], true) && $reg->estado_asistencia !== 'asistio' && $reg->estado_pago !== 'pagado', 'zona_horaria' => config('comunidad.eventos_timezone')];
    }

    private function editable(Evento $e): void
    {
        if ($e->estado === 'cancelado' || now()->gte($e->fecha_hora_inicio)) {
            throw ValidationException::withMessages(['evento' => 'El evento fue cancelado o ya inició; no admite cambios.']);
        }
    }

    private function conEvento(Request $request, string $id, bool $gestionar, callable $accion)
    {
        try {
            return Cache::store(config('comunidad.eventos_lock_store'))->lock('evento:'.$id, 30)->block(5, function () use ($request, $id, $gestionar, $accion) {
                $e = Evento::findOrFail($id);
                if ($gestionar) {
                    $org = $this->organizacion($request, true);
                    abort_unless((string) $e->organizacion_id === (string) $org->id, 404);
                }

                return $accion($e);
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'El evento está procesando otra solicitud. Intenta nuevamente.'], 409);
        }
    }
}
