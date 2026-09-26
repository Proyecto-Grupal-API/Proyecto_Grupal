<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarCampanaRequest;
use App\Jobs\EnviarCampana;
use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Services\AudienciaCampana;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComunicacionController extends Controller
{
    public function index(Request $r)
    {
        $org = $this->organizacion($r, true);
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'buscar' => ['nullable', 'string', 'max:120']]);
        $q = Campana::where('organizacion_id', (string) $org->id);
        if ($r->filled('buscar')) {
            $q->where('nombre', 'like', '%'.$r->buscar.'%');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(12);

        return response()->json(['campanas' => $p->getCollection()->map(fn ($c) => $this->resumen($c)), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(),
            'eventos' => Evento::where('organizacion_id', (string) $org->id)->orderBy('creado_en', 'desc')->get(['id', 'titulo']),
            'becas' => ConvocatoriaBeca::where('organizacion_id', (string) $org->id)->orderBy('creado_en', 'desc')->get(['id', 'titulo'])]);
    }

    public function store(GuardarCampanaRequest $r)
    {
        $org = $this->organizacion($r, true);
        $c = new Campana([...$r->datos(), 'organizacion_id' => (string) $org->id, 'estado' => 'borrador', 'revision' => 1]);
        app(AudienciaCampana::class)->ids($c);
        $c->save();
        $this->auditar($r, $c, 'campana_creada');

        return response()->json(['message' => 'Borrador guardado.', 'campana' => $this->resumen($c)], 201);
    }

    public function update(GuardarCampanaRequest $r, string $id)
    {
        return $this->conCampana($r, $id, function ($c) use ($r) {
            $this->exigir($c->estado === 'borrador', 'Solo puedes editar borradores.');
            $c->fill([...$r->datos(), 'revision' => $c->revision + 1]);
            app(AudienciaCampana::class)->ids($c);
            $c->save();
            $this->auditar($r, $c, 'campana_editada');

            return response()->json(['message' => 'Borrador actualizado.', 'campana' => $this->resumen($c)]);
        });
    }

    public function preview(Request $r, string $id)
    {
        return $this->conCampana($r, $id, function ($c) {
            $this->exigir($c->estado === 'borrador', 'Esta campaña ya no es un borrador.');
            $a = app(AudienciaCampana::class);
            $ids = $a->ids($c);

            return response()->json(['campana' => $this->resumen($c), 'destinatarios' => count($ids), 'firma' => $a->firma($c, $ids)]);
        });
    }

    public function enviar(Request $r, string $id)
    {
        $d = $r->validate(['firma' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return $this->conCampana($r, $id, function ($c) use ($r, $d) {
            // Repetir una confirmación nunca crea una campaña nueva ni cambia su audiencia.
            if (in_array($c->estado, ['en_cola', 'enviando', 'enviada'])) {
                return response()->json(['message' => 'La campaña ya fue confirmada.']);
            }
            $this->exigir($c->estado === 'borrador', 'Esta campaña no admite otro envío.');
            $a = app(AudienciaCampana::class);
            $ids = $a->ids($c);
            abort_unless(hash_equals($a->firma($c, $ids), $d['firma']), 409, 'Cambió el contenido o la audiencia. Revisa una nueva vista previa antes de confirmar.');
            $this->exigir(count($ids) > 0, 'No hay destinatarios disponibles para esta audiencia.');
            $c->update(['estado' => 'en_cola', 'destinatarios' => $ids, 'total_destinatarios' => count($ids), 'procesados' => 0, 'omitidos' => 0, 'encolado_en' => now(), 'enviado_por' => (string) $r->user()->id, 'emisor_nombre' => $r->attributes->get('organizacion')->nombre, 'ejecucion' => (string) Str::uuid(), 'error' => null]);
            $this->auditar($r, $c, 'campana_confirmada');
            $this->encolar($c);

            return response()->json(['message' => 'Campaña confirmada. Los mensajes se entregarán en la bandeja interna.'], 202);
        });
    }

    public function reintentar(Request $r, string $id)
    {
        return $this->conCampana($r, $id, function ($c) use ($r) {
            $this->exigir(in_array($c->estado, ['en_cola', 'enviando', 'error', 'pausada']), 'No quedan entregas reintentables.');
            $c->update(['estado' => 'en_cola', 'error' => null, 'enviado_por' => (string) $r->user()->id, 'ejecucion' => (string) Str::uuid()]);
            $this->auditar($r, $c, 'campana_reintentada');
            $this->encolar($c);

            return response()->json(['message' => 'Se reintentará únicamente la audiencia pendiente.'], 202);
        });
    }

    public function cancelar(Request $r, string $id)
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->conCampana($r, $id, function ($c) use ($r, $d) {
            if ($c->estado === 'cancelada') {
                return response()->json(['message' => 'Campaña cancelada.']);
            }
            $this->exigir($c->estado !== 'enviada', 'La entrega ya terminó.');
            $c->update(['estado' => 'cancelada', 'motivo_cancelacion' => $d['motivo']]);
            $this->auditar($r, $c, 'campana_cancelada');

            return response()->json(['message' => 'Envío cancelado. Los mensajes ya entregados se conservan.']);
        });
    }

    private function encolar(Campana $c): void
    {
        try {
            EnviarCampana::dispatch((string) $c->id, $c->ejecucion);
        } catch (\Throwable $e) {
            $c->update(['estado' => 'error', 'error' => 'No se pudo iniciar la entrega. Reintenta los pendientes.']);
            report($e);
            abort(503, 'No se pudo iniciar la entrega. Puedes reintentar.');
        }
    }

    private function resumen(Campana $c): array
    {
        $q = Mensaje::where('campaña_id', (string) $c->id);
        $entregados = (clone $q)->count();
        $leidos = (clone $q)->whereNotNull('primera_lectura_en')->count();

        return [...$c->toArray(), 'total_enviados' => $entregados, 'total_leidos' => $leidos, 'tasa_lectura' => $entregados ? round($leidos / $entregados * 100) : 0];
    }

    private function conCampana(Request $r, string $id, callable $fn)
    {
        $org = $this->organizacion($r, true);
        try {
            return Cache::store('file')->lock('campana:'.$id, 60)->block(5, function () use ($org, $id, $fn) {
                return $fn(Campana::where('organizacion_id', (string) $org->id)->findOrFail($id));
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'La campaña está procesando otra operación. Intenta nuevamente.'], 409);
        }
    }

    private function exigir(bool $ok, string $mensaje): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['campana' => $mensaje]);
        }
    }

    private function auditar(Request $r, Campana $c, string $accion): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $c->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'accion' => $accion, 'entidad_id' => (string) $c->id, 'antes' => null, 'despues' => ['estado' => $c->estado, 'revision' => $c->revision, 'audiencia' => $c->audiencia, 'referencia_id' => $c->referencia_id, 'total_destinatarios' => $c->total_destinatarios]]);
    }
}
