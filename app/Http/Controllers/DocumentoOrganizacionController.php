<?php

namespace App\Http\Controllers;

use App\Models\AuditoriaComunidad;
use App\Models\DocumentoOrganizacion;
use App\Models\VersionDocumentoOrganizacion;
use App\Services\DocumentosOrganizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentoOrganizacionController extends Controller
{
    public function index(Request $r)
    {
        $org = $this->organizacion($r);
        $admin = Gate::allows('update', $org);
        $r->validate(['estado' => ['nullable', Rule::in(['activo', 'retirado'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = DocumentoOrganizacion::where('organizacion_id', (string) $org->id)->where('estado', $r->input('estado', 'activo'));
        if (! $admin) {
            $q->where('visibilidad', 'integrantes')->where('estado', 'activo');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(10);
        $versiones = VersionDocumentoOrganizacion::whereIn('_id', $p->getCollection()->pluck('version_actual_id'))->get()->keyBy('id');

        return response()->json(['documentos' => $p->getCollection()->map(fn ($d) => $this->resumen($d, $versiones->get($d->version_actual_id))), 'categorias' => DocumentosOrganizacion::CATEGORIAS, 'puede_editar' => $admin, 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, DocumentosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $d = $this->metadatos($r, false);
        $this->validarArchivo($r);
        [$doc, $nuevo] = $s->crear($r->user(), $org, $d, $r->file('archivo'));

        return $this->respuesta($doc, 'Documento guardado de forma privada.', $nuevo ? 201 : 200);
    }

    public function update(Request $r, string $id, DocumentosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $doc = $s->editar($r->user(), $org, $id, $this->metadatos($r, true));

        return $this->respuesta($doc, 'Datos y visibilidad actualizados.');
    }

    public function nuevaVersion(Request $r, string $id, DocumentosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:10', 'max:1000'], 'clave_solicitud' => ['required', 'uuid']]);
        $this->validarArchivo($r);
        [$doc, $nuevo] = $s->nuevaVersion($r->user(), $org, $id, $d, $r->file('archivo'));

        return $this->respuesta($doc, 'Nueva versión guardada. Se conserva el archivo anterior.', $nuevo ? 201 : 200);
    }

    public function versiones(Request $r, string $id)
    {
        $doc = $this->buscar($r, $id);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $q = VersionDocumentoOrganizacion::where('documento_id', $id)->where('organizacion_id', $doc->organizacion_id);
        if (! Gate::allows('update', $r->attributes->get('organizacion'))) {
            $q->where('_id', $doc->version_actual_id);
        }
        $p = $q->orderBy('numero', 'desc')->paginate(20);

        return response()->json(['versiones' => $p->getCollection()->map(fn ($v) => $this->version($v)), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function descargar(Request $r, string $id, ?string $version = null)
    {
        $doc = $this->buscar($r, $id);
        $version ??= $doc->version_actual_id;
        if (! Gate::allows('update', $r->attributes->get('organizacion'))) {
            abort_unless($version === $doc->version_actual_id, 404);
        }
        $v = VersionDocumentoOrganizacion::where('documento_id', $id)->where('organizacion_id', $doc->organizacion_id)->findOrFail($version);
        abort_unless(Storage::disk('organizacion_documentos')->exists($v->ruta), 404);
        AuditoriaComunidad::create(['organizacion_id' => $doc->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'accion' => 'organizacion_documento_descargado', 'entidad_id' => $id, 'antes' => null, 'despues' => ['version_id' => (string) $v->id, 'numero' => $v->numero]]);

        return Storage::disk('organizacion_documentos')->download($v->ruta, 'documento-'.$id.'-v'.$v->numero.'.'.pathinfo($v->ruta, PATHINFO_EXTENSION), ['Content-Type' => $v->tipo, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function retirar(Request $r, string $id, DocumentosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->respuesta($s->retirar($r->user(), $org, $id, $d), 'Documento retirado. Se conserva su historial privado.');
    }

    private function buscar(Request $r, string $id): DocumentoOrganizacion
    {
        $org = $this->organizacion($r);
        $doc = DocumentoOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
        abort_unless(Gate::allows('update', $org) || ($doc->estado === 'activo' && $doc->visibilidad === 'integrantes'), 404);

        return $doc;
    }

    private function metadatos(Request $r, bool $editar): array
    {
        $d = $r->validate(['titulo' => ['required', 'string', 'min:3', 'max:150'], 'categoria' => ['required', Rule::in(array_keys(DocumentosOrganizacion::CATEGORIAS))], 'descripcion' => ['nullable', 'string', 'max:1000'], 'fecha_documento' => ['required', 'date_format:Y-m-d'], 'visibilidad' => ['nullable', Rule::in(['presidencia', 'integrantes'])], ...($editar ? ['version' => ['required', 'integer', 'min:1']] : ['clave_solicitud' => ['required', 'uuid']])]);

        return [...$d, 'descripcion' => $d['descripcion'] ?? '', 'visibilidad' => $d['visibilidad'] ?? 'presidencia'];
    }

    private function validarArchivo(Request $r): void
    {
        $r->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120']]);
    }

    private function respuesta(DocumentoOrganizacion $doc, string $mensaje, int $status = 200)
    {
        return response()->json(['message' => $mensaje, 'documento' => $this->resumen($doc, VersionDocumentoOrganizacion::find($doc->version_actual_id))], $status)->header('Cache-Control', 'private, no-store');
    }

    private function resumen(DocumentoOrganizacion $doc, ?VersionDocumentoOrganizacion $version): array
    {
        return [...$doc->only(['id', 'organizacion_id', 'titulo', 'categoria', 'descripcion', 'fecha_documento', 'visibilidad', 'estado', 'version', 'creado_en', 'actualizado_en', 'retirado_en', 'motivo_retiro']), 'version_actual' => $version ? $this->version($version) : null];
    }

    private function version(VersionDocumentoOrganizacion $v): array
    {
        return $v->only(['id', 'numero', 'nombre', 'tipo', 'tamano', 'motivo', 'cargado_por', 'creado_en']);
    }
}
