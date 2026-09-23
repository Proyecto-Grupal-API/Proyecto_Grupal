<?php

namespace App\Http\Controllers;

use App\Models\Mensaje;
use App\Models\Organizacion;
use App\Models\PreferenciaComunicacion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BandejaController extends Controller
{
    private function propios(Request $r)
    {
        return Mensaje::where('usuario_id', (string) $r->user()->id)->whereNull('eliminado_en');
    }

    public function index(Request $r)
    {
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'filtro' => ['nullable', Rule::in(['recibidos', 'no_leidos', 'importantes', 'archivados'])], 'buscar' => ['nullable', 'string', 'max:120']]);
        $q = $this->propios($r);
        $f = $r->input('filtro', 'recibidos');
        if ($f === 'archivados') {
            $q->whereNotNull('archivado_en');
        } else {
            $q->whereNull('archivado_en');
        }
        if ($f === 'no_leidos') {
            $q->whereNull('leido_en');
        }if ($f === 'importantes') {
            $q->where('importante', true);
        }
        if ($r->filled('buscar')) {
            $q->where('asunto', 'like', '%'.$r->buscar.'%');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(15);

        return response()->json(['mensajes' => $p->getCollection()->map(fn ($m) => $m->only(['id', 'asunto', 'emisor_nombre', 'organizacion_id', 'leido_en', 'archivado_en', 'importante', 'creado_en'])), 'no_leidos' => $this->propios($r)->whereNull('archivado_en')->whereNull('leido_en')->count(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $id)
    {
        $m = $this->propios($r)->findOrFail($id);

        return response()->json($m->only(['id', 'asunto', 'cuerpo', 'emisor_nombre', 'organizacion_id', 'accion_url', 'texto_accion', 'leido_en', 'archivado_en', 'importante', 'creado_en']))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $r, string $id)
    {
        $d = $r->validate(['leida' => ['sometimes', 'required', 'boolean'], 'archivada' => ['sometimes', 'required', 'boolean'], 'importante' => ['sometimes', 'required', 'boolean']]);
        $m = $this->propios($r)->findOrFail($id);
        $cambios = [];
        if (array_key_exists('leida', $d)) {
            $cambios['leido_en'] = $d['leida'] ? now() : null;
            if ($d['leida']) {
                $this->propios($r)->where('_id', $id)->whereNull('primera_lectura_en')->update(['primera_lectura_en' => now()]);
            }
        }
        if (array_key_exists('archivada', $d)) {
            $cambios['archivado_en'] = $d['archivada'] ? now() : null;
        }
        if (array_key_exists('importante', $d)) {
            $cambios['importante'] = $d['importante'];
        }
        if ($cambios) {
            $m->update($cambios);
        }

        return response()->json(['message' => 'Mensaje actualizado.']);
    }

    public function preferencias(Request $r)
    {
        $prefs = PreferenciaComunicacion::where('usuario_id', (string) $r->user()->id)->get()->keyBy('organizacion_id');

        return response()->json(Organizacion::where('estado', 'activa')->orderBy('nombre')->get()->map(fn ($o) => ['id' => (string) $o->id, 'nombre' => $o->nombre, 'silenciada' => (bool) $prefs->get($o->id)?->silenciada]));
    }

    public function preferencia(Request $r, string $id)
    {
        $d = $r->validate(['silenciada' => ['required', 'boolean']]);
        Organizacion::where('estado', 'activa')->findOrFail($id);
        PreferenciaComunicacion::updateOrCreate(['usuario_id' => (string) $r->user()->id, 'organizacion_id' => $id], $d);

        return response()->json(['message' => $d['silenciada'] ? 'No recibirás nuevas campañas de esta organización.' : 'Volverás a recibir campañas de esta organización.']);
    }
}
