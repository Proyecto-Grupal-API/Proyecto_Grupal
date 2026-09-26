<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarConvocatoriaRequest;
use App\Models\AsignacionBeneficio;
use App\Models\AuditoriaComunidad;
use App\Models\ConvocatoriaBeca;
use App\Models\Organizacion;
use App\Models\SolicitudBeca;
use App\Models\TipoBeneficio;
use App\Models\User;
use App\Services\BeneficiosBeca;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BecaController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['gestion' => ['nullable', 'boolean'], 'buscar' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        $org = $r->attributes->get('organizacion');
        $q = ConvocatoriaBeca::query();
        if ($r->boolean('gestion')) {
            $q->where('organizacion_id', (string) $this->organizacion($r, true)->id);
        } else {
            $q->whereIn('organizacion_id', Organizacion::where('estado', 'activa')->pluck('id'))->whereIn('estado', ['publicada', 'en_revision'])->where('fecha_fin', '>', now());
        }
        if ($r->filled('buscar')) {
            $q->where('titulo', 'like', '%'.$r->buscar.'%');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(12);
        $orgs = Organizacion::whereIn('_id', $p->getCollection()->pluck('organizacion_id'))->get()->keyBy('id');
        $mias = SolicitudBeca::whereIn('convocatoria_id', $p->getCollection()->pluck('id'))->where('usuario_id', (string) $r->user()->id)->get()->keyBy('convocatoria_id');

        return response()->json(['convocatorias' => $p->getCollection()->map(fn ($c) => [...$this->convocatoria($c), 'organizacion_nombre' => $orgs->get($c->organizacion_id)?->nombre, 'mi_solicitud' => $mias->has($c->id) ? $this->resumen($mias->get($c->id)) : null]), 'tipos' => TipoBeneficio::whereIn('equipo', [2, 5])->get(), 'puede_gestionar' => $org && Gate::allows('update', $org), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function store(GuardarConvocatoriaRequest $r)
    {
        $org = $this->organizacion($r, true);
        $c = ConvocatoriaBeca::create([...$r->datos(), 'organizacion_id' => (string) $org->id, 'creado_por' => (string) $r->user()->id, 'estado' => 'borrador']);
        $this->auditar($r, $c, 'beca_creada', $c->id, null, $c->toArray());

        return response()->json(['message' => 'Borrador guardado.', 'convocatoria' => $this->convocatoria($c)], 201);
    }

    public function update(GuardarConvocatoriaRequest $r, string $id)
    {
        return $this->conConvocatoria($r, $id, true, function ($c) use ($r) {
            $this->exigir($c->estado === 'borrador', 'Solo puedes editar convocatorias en borrador.');
            $antes = $c->toArray();
            $c->update($r->datos());
            $this->auditar($r, $c, 'beca_editada', $c->id, $antes, $c->toArray());

            return response()->json(['message' => 'Convocatoria actualizada.']);
        });
    }

    public function cambiarEstado(Request $r, string $id)
    {
        $d = $r->validate(['estado' => ['required', Rule::in(['publicada', 'en_revision', 'finalizada', 'cancelada'])], 'motivo' => ['required_if:estado,cancelada', 'nullable', 'string', 'min:10', 'max:2000']]);

        return $this->conConvocatoria($r, $id, true, function ($c) use ($r, $d) {
            $antes = $c->toArray();
            $destino = $d['estado'];
            if ($c->estado !== $destino) {
                if ($destino === 'publicada') {
                    $this->exigir($c->estado === 'borrador' && $c->fecha_fin?->gt(now()), 'Solo puedes publicar un borrador con recepción vigente.');
                } elseif ($destino === 'en_revision') {
                    $this->exigir($c->estado === 'publicada', 'Solo puedes cerrar la recepción de una convocatoria publicada.');
                } elseif ($destino === 'finalizada') {
                    $this->exigir($this->revisionAbierta($c), 'Primero cierra la recepción.');
                    $this->exigir(! SolicitudBeca::where('convocatoria_id', (string) $c->id)->whereIn('estado', ['pendiente', 'en_revision'])->exists(), 'Quedan solicitudes por dictaminar.');
                } else {
                    $this->exigir(in_array($c->estado, ['borrador', 'publicada', 'en_revision']), 'La convocatoria ya está finalizada.');
                    $this->exigir(! SolicitudBeca::where('convocatoria_id', (string) $c->id)->where('estado', 'aprobada')->exists(), 'Hay beneficios aprobados; su revocación requiere coordinar la entrega con el equipo responsable.');
                }
                $c->update(['estado' => $destino, 'motivo_cancelacion' => $destino === 'cancelada' ? $d['motivo'] : null]);
                $this->auditar($r, $c, 'beca_estado', $c->id, $antes, $c->toArray());
            }
            if ($destino === 'cancelada') {
                SolicitudBeca::where('convocatoria_id', (string) $c->id)->whereIn('estado', ['borrador', 'pendiente', 'en_revision'])->update(['estado' => 'cancelada', 'motivo_cancelacion' => $d['motivo']]);
            }

            return response()->json(['message' => 'Estado de la convocatoria actualizado.']);
        });
    }

    public function crearSolicitud(Request $r, string $id)
    {
        return $this->conConvocatoria($r, $id, false, function ($c) use ($r) {
            $this->exigir($this->recepcionAbierta($c), 'La recepción de solicitudes no está abierta.');
            $s = SolicitudBeca::firstOrCreate(['convocatoria_id' => (string) $c->id, 'usuario_id' => (string) $r->user()->id], ['organizacion_id' => (string) $c->organizacion_id, 'folio' => 'BECA-'.Str::upper(Str::random(12)), 'estado' => 'borrador', 'motivacion' => '', 'documentos' => []]);
            if ($s->wasRecentlyCreated) {
                $this->auditar($r, $c, 'beca_solicitud_creada', $s->id, null, ['estado' => 'borrador', 'folio' => $s->folio]);
            }

            return response()->json(['message' => 'Solicitud disponible.', 'solicitud' => $this->resumen($s)]);
        });
    }

    public function misSolicitudes(Request $r)
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $p = SolicitudBeca::where('usuario_id', (string) $r->user()->id)->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(12);
        $cs = ConvocatoriaBeca::whereIn('_id', $p->getCollection()->pluck('convocatoria_id'))->get()->keyBy('id');

        return response()->json(['solicitudes' => $p->getCollection()->map(fn ($s) => [...$this->resumen($s), 'titulo' => $cs->get($s->convocatoria_id)?->titulo]), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function solicitudes(Request $r, string $id)
    {
        $c = ConvocatoriaBeca::where('organizacion_id', (string) $this->organizacion($r, true)->id)->findOrFail($id);
        $r->validate(['estado' => ['nullable', Rule::in(['pendiente', 'en_revision', 'aprobada', 'rechazada', 'retirada', 'cancelada'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = SolicitudBeca::where('convocatoria_id', $id)->whereNotNull('enviado_en');
        if ($r->filled('estado')) {
            $q->where('estado', $r->estado);
        }
        $p = $q->orderBy('enviado_en')->orderBy('_id')->paginate(20);
        $users = User::whereIn('_id', $p->getCollection()->pluck('usuario_id'))->get()->keyBy('id');

        return response()->json(['convocatoria' => $this->convocatoria($c), 'solicitudes' => $p->getCollection()->map(fn ($s) => [...$this->resumen($s), 'nombre' => $users->get($s->usuario_id)?->name ?? 'Usuario no disponible', 'matricula' => $users->get($s->usuario_id)?->matricula]), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function showSolicitud(Request $r, string $id)
    {
        $s = SolicitudBeca::findOrFail($id);
        $c = ConvocatoriaBeca::findOrFail($s->convocatoria_id);
        $propia = $this->autorizarSolicitud($r, $s, $c);

        return response()->json(['solicitud' => [...$this->resumen($s), 'motivacion' => $s->motivacion, 'documentos' => collect($s->documentos ?? [])->map(fn ($d) => collect($d)->except('ruta')->all())],
            'convocatoria' => $this->convocatoria($c), 'propia' => $propia, 'puede_editar' => $propia && $s->estado === 'borrador' && $this->recepcionAbierta($c),
            'puede_retirar' => $propia && in_array($s->estado, ['borrador', 'pendiente']) && $this->recepcionAbierta($c),
            'puede_dictaminar' => ! $propia && $this->revisionAbierta($c) && in_array($s->estado, ['pendiente', 'en_revision']),
            'beneficio' => AsignacionBeneficio::where('solicitud_id', $id)->first()?->only(['id', 'estado'])])->header('Cache-Control', 'private, no-store');
    }

    public function guardarSolicitud(Request $r, string $id)
    {
        $d = $r->validate(['motivacion' => ['required', 'string', 'min:20', 'max:3000'], 'enviar' => ['required', 'boolean']]);

        return $this->conSolicitud($r, $id, false, function ($s, $c) use ($r, $d) {
            if ($d['enviar'] && in_array($s->estado, ['pendiente', 'en_revision', 'aprobada', 'rechazada'])) {
                return response()->json(['message' => 'La solicitud ya fue enviada.']);
            }
            $this->editable($s, $c);
            if ($d['enviar']) {
                foreach ($c->requisitos_documentos ?? [] as $i => $nombre) {
                    $this->exigir(collect($s->documentos ?? [])->contains(fn ($doc) => ($doc['requisito'] ?? null) === $i), 'Falta el documento: '.$nombre);
                }
            }
            $antes = $this->resumen($s);
            $s->update(['motivacion' => $d['motivacion'], 'estado' => $d['enviar'] ? 'pendiente' : 'borrador', 'enviado_en' => $d['enviar'] ? now() : null]);
            $this->auditar($r, $c, $d['enviar'] ? 'beca_solicitud_enviada' : 'beca_borrador_guardado', $s->id, $antes, $this->resumen($s));

            return response()->json(['message' => $d['enviar'] ? 'Solicitud enviada para revisión.' : 'Borrador guardado.']);
        });
    }

    public function retirar(Request $r, string $id)
    {
        return $this->conSolicitud($r, $id, false, function ($s, $c) use ($r) {
            if ($s->estado === 'retirada') {
                return response()->json(['message' => 'Solicitud retirada.']);
            }
            $this->exigir($this->recepcionAbierta($c) && in_array($s->estado, ['borrador', 'pendiente']), 'Solo puedes retirar antes del cierre y de la revisión.');
            $antes = $this->resumen($s);
            $s->update(['estado' => 'retirada']);
            $this->auditar($r, $c, 'beca_retirada', $s->id, $antes, $this->resumen($s));

            return response()->json(['message' => 'Solicitud retirada. Se conserva su historial.']);
        });
    }

    public function subirDocumento(Request $r, string $id)
    {
        $r->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'], 'requisito' => ['nullable', 'integer', 'min:0', 'max:4']]);

        return $this->conSolicitud($r, $id, false, function ($s, $c) use ($r) {
            $this->editable($s, $c);
            $docs = $s->documentos ?? [];
            $req = $r->filled('requisito') ? (int) $r->requisito : null;
            $this->exigir(count($docs) < 5, 'Puedes adjuntar hasta cinco documentos.');
            if ($req !== null) {
                $this->exigir(array_key_exists($req, $c->requisitos_documentos ?? []), 'El requisito no existe.');
                $this->exigir(! collect($docs)->contains(fn ($doc) => ($doc['requisito'] ?? null) === $req), 'Elimina el documento anterior para sustituirlo.');
            }
            $file = $r->file('archivo');
            $uuid = (string) Str::uuid();
            $ruta = $file->storeAs((string) $s->id, $uuid.'.'.$file->extension(), 'becas');
            $nombre = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 180);
            $doc = ['id' => $uuid, 'nombre' => $nombre, 'tipo' => $file->getMimeType(), 'tamano' => $file->getSize(), 'requisito' => $req, 'ruta' => $ruta];
            try {
                $s->update(['documentos' => [...$docs, $doc]]);
            } catch (\Throwable $e) {
                Storage::disk('becas')->delete($ruta);
                throw $e;
            }
            $this->auditar($r, $c, 'beca_documento_subido', $s->id, null, ['documento_id' => $uuid, 'requisito' => $req]);

            return response()->json(['message' => 'Documento guardado de forma privada.'], 201);
        });
    }

    public function eliminarDocumento(Request $r, string $id, string $documento)
    {
        return $this->conSolicitud($r, $id, false, function ($s, $c) use ($r, $documento) {
            $this->editable($s, $c);
            $doc = collect($s->documentos ?? [])->firstWhere('id', $documento);
            abort_unless($doc, 404);
            $s->update(['documentos' => collect($s->documentos)->reject(fn ($d) => $d['id'] === $documento)->values()->all()]);
            Storage::disk('becas')->delete($doc['ruta']);
            $this->auditar($r, $c, 'beca_documento_eliminado', $s->id, ['documento_id' => $documento], []);

            return response()->json(['message' => 'Documento eliminado del borrador.']);
        });
    }

    public function descargarDocumento(Request $r, string $id, string $documento)
    {
        $s = SolicitudBeca::findOrFail($id);
        $c = ConvocatoriaBeca::findOrFail($s->convocatoria_id);
        $this->autorizarSolicitud($r, $s, $c);
        $doc = collect($s->documentos ?? [])->firstWhere('id', $documento);
        abort_unless($doc && Storage::disk('becas')->exists($doc['ruta']), 404);
        $this->auditar($r, $c, 'beca_documento_descargado', $s->id, null, ['documento_id' => $documento]);

        return Storage::disk('becas')->download($doc['ruta'], 'documento-'.$doc['id'].'.'.pathinfo($doc['ruta'], PATHINFO_EXTENSION), ['Content-Type' => $doc['tipo'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function dictaminar(Request $r, string $id)
    {
        $d = $r->validate(['estado' => ['required', Rule::in(['en_revision', 'aprobada', 'rechazada'])], 'motivo' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->conSolicitud($r, $id, true, function ($s, $c) use ($r, $d) {
            $this->exigir((string) $s->usuario_id !== (string) $r->user()->id, 'No puedes dictaminar tu propia solicitud.');
            if ($s->estado === $d['estado'] && in_array($s->estado, ['aprobada', 'rechazada'])) {
                if ($s->estado === 'aprobada') {
                    app(BeneficiosBeca::class)->preparar($s, $c);
                }

                return response()->json(['message' => 'El dictamen ya estaba registrado.']);
            }
            $this->exigir($this->revisionAbierta($c), 'El dictamen se realiza después del cierre de recepción.');
            $this->exigir(in_array($s->estado, ['pendiente', 'en_revision']), 'Esta solicitud no admite otro dictamen.');
            $this->exigir(User::where('_id', $s->usuario_id)->exists(), 'La cuenta del solicitante ya no está disponible.');
            if ($d['estado'] === 'aprobada') {
                $this->exigir($c->vigencia_fin->gt(now()), 'La vigencia del beneficio ya terminó.');
                $this->exigir(SolicitudBeca::where('convocatoria_id', (string) $c->id)->where('estado', 'aprobada')->count() < $c->total_espacios, 'Se agotaron los espacios disponibles.');
            }
            $antes = $this->resumen($s);
            $s->update(['estado' => $d['estado'], 'dictamen' => ['motivo' => $d['motivo']], 'dictaminado_en' => now(), 'dictaminado_por' => (string) $r->user()->id]);
            $this->auditar($r, $c, 'beca_dictamen', $s->id, $antes, $this->resumen($s));
            if ($s->estado === 'aprobada') {
                app(BeneficiosBeca::class)->preparar($s, $c);
            }

            return response()->json(['message' => $s->estado === 'aprobada' ? 'Solicitud aprobada. La entrega del beneficio está pendiente.' : 'Dictamen guardado.']);
        });
    }

    public function contrato(Request $r, string $id)
    {
        $s = SolicitudBeca::findOrFail($id);
        $c = ConvocatoriaBeca::findOrFail($s->convocatoria_id);
        $this->autorizarGestion($r, $c);
        abort_unless($s->estado === 'aprobada', 404);
        $a = AsignacionBeneficio::where('solicitud_id', $id)->firstOrFail();

        return response()->json($a->only(['id', 'estado', 'contrato']))->header('Cache-Control', 'private, no-store');
    }

    private function convocatoria(ConvocatoriaBeca $c): array
    {
        $ocupados = SolicitudBeca::where('convocatoria_id', (string) $c->id)->where('estado', 'aprobada')->count();

        return [...$c->toArray(), 'recepcion_abierta' => $this->recepcionAbierta($c), 'revision_abierta' => $this->revisionAbierta($c), 'espacios_ocupados' => $ocupados, 'porcentaje' => $c->total_espacios ? round($ocupados / $c->total_espacios * 100) : 0];
    }

    private function resumen(SolicitudBeca $s): array
    {
        return $s->only(['id', 'convocatoria_id', 'folio', 'estado', 'enviado_en', 'dictamen', 'dictaminado_en', 'motivo_cancelacion']);
    }

    private function recepcionAbierta(ConvocatoriaBeca $c): bool
    {
        return $c->estado === 'publicada' && $c->fecha_inicio && $c->fecha_fin && now()->gte($c->fecha_inicio) && now()->lt($c->fecha_fin) && Organizacion::where('_id', $c->organizacion_id)->where('estado', 'activa')->exists();
    }

    private function revisionAbierta(ConvocatoriaBeca $c): bool
    {
        return $c->estado === 'en_revision' || ($c->estado === 'publicada' && $c->fecha_fin && now()->gte($c->fecha_fin));
    }

    private function editable(SolicitudBeca $s, ConvocatoriaBeca $c): void
    {
        $this->exigir($s->estado === 'borrador' && $this->recepcionAbierta($c), 'Solo puedes editar un borrador durante la recepción.');
    }

    private function exigir(bool $ok, string $mensaje): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['beca' => $mensaje]);
        }
    }

    private function autorizarGestion(Request $r, ConvocatoriaBeca $c): void
    {
        abort_unless((string) $this->organizacion($r, true)->id === (string) $c->organizacion_id, 404);
    }

    private function autorizarSolicitud(Request $r, SolicitudBeca $s, ConvocatoriaBeca $c): bool
    {
        if ((string) $s->usuario_id === (string) $r->user()->id) {
            return true;
        }
        $this->autorizarGestion($r, $c);
        abort_unless($s->enviado_en, 404);

        return false;
    }

    private function conSolicitud(Request $r, string $id, bool $gestion, callable $fn)
    {
        $original = SolicitudBeca::findOrFail($id);

        return $this->conConvocatoria($r, $original->convocatoria_id, $gestion, function ($c) use ($r, $id, $gestion, $fn) {
            $s = SolicitudBeca::findOrFail($id);
            if (! $gestion) {
                abort_unless((string) $s->usuario_id === (string) $r->user()->id, 404);
            }

            return $fn($s, $c);
        });
    }

    private function conConvocatoria(Request $r, string $id, bool $gestion, callable $fn)
    {
        try {
            return Cache::store('file')->lock('beca:'.$id, 60)->block(5, function () use ($r, $id, $gestion, $fn) {
                $c = ConvocatoriaBeca::findOrFail($id);
                if ($gestion) {
                    $this->autorizarGestion($r, $c);
                }

                return $fn($c);
            });
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'La convocatoria está procesando otra solicitud. Intenta nuevamente.'], 409);
        }
    }

    private function auditar(Request $r, ConvocatoriaBeca $c, string $accion, string $id, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => (string) $c->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'accion' => $accion, 'entidad_id' => $id, 'antes' => $antes, 'despues' => $despues]);
    }
}
