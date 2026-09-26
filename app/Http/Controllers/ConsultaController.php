<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarConsultaRequest;
use App\Models\AuditoriaComunidad;
use App\Models\ConsultaComunidad;
use App\Services\ConsultasComunidad;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConsultaController extends Controller
{
    public function __construct(private ConsultasComunidad $consultas) {}

    public function index(Request $r, string $tipo)
    {
        $org = $this->organizacion($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'buscar' => ['nullable', 'string', 'max:120']]);
        $gestion = Gate::allows('update', $org);
        $q = $this->consultas->consulta($tipo)->where('organizacion_id', (string) $org->id)->whereNotNull('revision');
        if (! $gestion) {
            $q->whereIn('estado', ['publicada', 'cerrada', 'cancelada'])->where('padron', (string) $r->user()->id);
        }
        if ($r->filled('buscar')) {
            $q->where('titulo', 'like', '%'.$r->buscar.'%');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(12);

        return response()->json(['consultas' => $p->getCollection()->map(fn ($c) => $this->resumen($r, $tipo, $c)), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'puede_gestionar' => $gestion])->header('Cache-Control', 'private, no-store');
    }

    public function store(GuardarConsultaRequest $r, string $tipo)
    {
        $org = $this->organizacion($r, true);
        $c = $this->consultas->consulta($tipo)->create([...$r->datos(), 'organizacion_id' => (string) $org->id, 'estado' => 'borrador', 'revision' => 1]);
        $this->auditar($r, $c, 'consulta_creada');

        return response()->json(['message' => 'Borrador guardado.', 'consulta' => $this->resumen($r, $tipo, $c)], 201);
    }

    public function show(Request $r, string $tipo, string $id)
    {
        $c = $this->buscar($r, $tipo, $id);

        return response()->json(['consulta' => $this->resumen($r, $tipo, $c), 'resultados' => $c->fase() === 'cerrada' ? $this->consultas->resultados($tipo, $c) : null])->header('Cache-Control', 'private, no-store');
    }

    public function update(GuardarConsultaRequest $r, string $tipo, string $id)
    {
        return $this->bloquear($r, $tipo, $id, true, function ($c) use ($r) {
            $this->exigir($c->estado === 'borrador', 'Solo puedes editar borradores.');
            abort_unless((int) $r->input('revision') === $c->revision, 409, 'El borrador cambió. Abre de nuevo el formulario.');
            $c->update([...$r->datos(), 'revision' => $c->revision + 1]);
            $this->auditar($r, $c, 'consulta_editada');

            return response()->json(['message' => 'Borrador actualizado.']);
        });
    }

    public function preview(Request $r, string $tipo, string $id)
    {
        return $this->bloquear($r, $tipo, $id, true, function ($c) use ($r, $tipo) {
            $this->exigir($c->estado === 'borrador', 'Esta consulta ya fue publicada o cancelada.');
            $ids = $this->consultas->padron($c);

            return response()->json(['consulta' => $this->resumen($r, $tipo, $c), 'total_padron' => count($ids), 'firma' => $this->consultas->firma($c, $ids)]);
        });
    }

    public function publicar(Request $r, string $tipo, string $id)
    {
        $d = $r->validate(['firma' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return $this->bloquear($r, $tipo, $id, true, function ($c) use ($r, $d) {
            $this->exigir($c->estado === 'borrador', 'Solo puedes publicar borradores.');
            $this->exigir($c->fecha_fin->isFuture(), 'La fecha de cierre ya pasó. Edita el periodo.');
            $ids = $this->consultas->padron($c);
            abort_unless(hash_equals($this->consultas->firma($c, $ids), $d['firma']), 409, 'Cambió el contenido o el padrón. Revisa otra vez antes de publicar.');
            $this->exigir(count($ids) > 0, 'El padrón no puede estar vacío.');
            $c->update(['estado' => 'publicada', 'padron' => $ids, 'total_padron' => count($ids), 'publicado_en' => now()]);
            $this->auditar($r, $c, 'consulta_publicada');

            return response()->json(['message' => 'Consulta publicada con padrón fijo.']);
        });
    }

    public function responder(Request $r, string $tipo, string $id)
    {
        $d = $r->validate(['revision' => ['required', 'integer', 'min:1'], 'respuestas' => ['required', 'array', 'list', 'min:1', 'max:10'], 'respuestas.*' => ['required', 'array:pregunta_id,opcion_id'], 'respuestas.*.pregunta_id' => ['required', 'uuid', 'distinct'], 'respuestas.*.opcion_id' => ['required', 'uuid']]);

        return $this->bloquear($r, $tipo, $id, false, function ($c) use ($r, $tipo, $d) {
            abort_unless(in_array((string) $r->user()->id, $c->padron ?? [], true), 403, 'No formas parte del padrón publicado.');
            $participaciones = $this->consultas->participacion($tipo);
            // Comprobar antes del periodo hace seguro reintentar una petición cuyo resultado se perdió.
            $previa = $participaciones->where('consulta_id', (string) $c->id)->where('usuario_id', (string) $r->user()->id)->first();
            if ($previa) {
                return response()->json(['message' => 'Tu participación ya estaba registrada. No se modificó.', 'registrada' => true]);
            }
            $this->exigir($c->fase() === 'abierta', 'La consulta no está abierta para participar.');
            abort_unless((int) $d['revision'] === $c->revision, 409, 'La consulta cambió. Recarga antes de participar.');
            $this->exigir(count($d['respuestas']) === count($c->preguntas), 'Responde todas las preguntas una sola vez.');
            $recibidas = collect($d['respuestas'])->keyBy('pregunta_id');
            $respuestas = [];
            foreach ($c->preguntas as $p) {
                $opcion = $recibidas->get($p['id'])['opcion_id'] ?? null;
                $this->exigir(in_array($opcion, array_column($p['opciones'], 'id'), true), 'Una opción no pertenece a su pregunta. Recarga y revisa las respuestas.');
                $respuestas[] = ['pregunta_id' => $p['id'], 'opcion_id' => $opcion];
            }
            // Una sola escritura guarda la participación y todas sus respuestas; no hay contador separado que pueda desincronizarse.
            $participaciones->create(['consulta_id' => (string) $c->id, 'organizacion_id' => $c->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'respuestas' => $respuestas]);

            return response()->json(['message' => 'Participación registrada. Gracias por participar.', 'registrada' => true], 201);
        });
    }

    public function cerrar(Request $r, string $tipo, string $id)
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->bloquear($r, $tipo, $id, true, function ($c) use ($r, $d) {
            $this->exigir($c->estado === 'publicada' && now()->gte($c->fecha_inicio), 'Solo puedes cerrar una consulta que ya inició.');
            $c->update(['estado' => 'cerrada', 'cerrado_en' => now(), 'motivo_cierre' => $d['motivo']]);
            $this->auditar($r, $c, 'consulta_cerrada');

            return response()->json(['message' => 'Consulta cerrada. Los resultados agregados ya están disponibles.']);
        });
    }

    public function cancelar(Request $r, string $tipo, string $id)
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->bloquear($r, $tipo, $id, true, function ($c) use ($r, $d) {
            $this->exigir(in_array($c->fase(), ['borrador', 'programada', 'abierta']), 'No puedes cancelar una consulta cerrada o cancelada.');
            $c->update(['estado' => 'cancelada', 'motivo_cancelacion' => $d['motivo']]);
            $this->auditar($r, $c, 'consulta_cancelada');

            return response()->json(['message' => 'Consulta cancelada. Se conservan las participaciones sin publicar resultados.']);
        });
    }

    private function buscar(Request $r, string $tipo, string $id, bool $gestionar = false): ConsultaComunidad
    {
        $org = $this->organizacion($r, $gestionar);
        $c = $this->consultas->consulta($tipo)->where('organizacion_id', (string) $org->id)->whereNotNull('revision')->findOrFail($id);
        if (! Gate::allows('update', $org)) {
            abort_unless($c->estado !== 'borrador' && in_array((string) $r->user()->id, $c->padron ?? [], true), 404);
        }

        return $c;
    }

    private function bloquear(Request $r, string $tipo, string $id, bool $gestionar, callable $fn)
    {
        try {
            return Cache::store('file')->lock('consulta:'.$tipo.':'.$id, 30)->block(5, fn () => $fn($this->buscar($r, $tipo, $id, $gestionar)));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Hay otra operación en curso. Intenta de nuevo.'], 409);
        }
    }

    private function resumen(Request $r, string $tipo, ConsultaComunidad $c): array
    {
        $registrada = $this->consultas->participacion($tipo)->where('consulta_id', (string) $c->id)->where('usuario_id', (string) $r->user()->id)->exists();

        return [...$c->only(['id', 'titulo', 'descripcion', 'fecha_inicio', 'fecha_fin', 'preguntas', 'estado', 'revision', 'total_padron', 'motivo_cierre', 'motivo_cancelacion']), 'fase' => $c->fase(), 'participacion_registrada' => $registrada, 'puede_participar' => ! $registrada && $c->fase() === 'abierta' && in_array((string) $r->user()->id, $c->padron ?? [], true)];
    }

    private function exigir(bool $ok, string $mensaje): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['consulta' => $mensaje]);
        }
    }

    private function auditar(Request $r, ConsultaComunidad $c, string $accion): void
    {
        // La bitácora administrativa nunca incluye elecciones personales ni el padrón nominal.
        AuditoriaComunidad::create(['organizacion_id' => $c->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'entidad_id' => (string) $c->id, 'accion' => $accion, 'antes' => null, 'despues' => ['tipo' => $c->getTable(), 'estado' => $c->estado, 'revision' => $c->revision, 'total_padron' => $c->total_padron, 'motivo' => $c->motivo_cierre ?? $c->motivo_cancelacion]]);
    }
}
