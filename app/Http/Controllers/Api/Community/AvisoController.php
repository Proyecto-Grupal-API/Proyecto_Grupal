<?php

namespace App\Http\Controllers\Api\Community;

use App\Http\Controllers\Controller;
use App\Services\CatalogoAvisosServicios;
use App\Services\EntregaAvisos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvisoController extends Controller
{
    public function store(Request $r, EntregaAvisos $entrega)
    {
        $textoPlano = function ($atributo, $valor, $fallar) {
            if (is_string($valor) && strip_tags($valor) !== $valor) { $fallar('Utiliza texto sin HTML.'); }
        };
        $d = $r->validate([
            'destinatario_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'tipo' => ['required', Rule::in(array_keys(CatalogoAvisosServicios::TIPOS))],
            'asunto' => ['required', 'string', 'max:180', $textoPlano],
            'texto' => ['required', 'string', 'max:5000', $textoPlano],
            'accion' => ['required', 'array:destino,id'],
            'accion.destino' => ['required', Rule::in(array_keys(CatalogoAvisosServicios::DESTINOS))],
            'accion.id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'importancia' => ['sometimes', Rule::in(['normal', 'alta'])],
            'expira_en' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'accion_url' => ['prohibited'],
        ]);
        $result = $entrega->enviar($this->cliente($r), $this->clave($r), $d);
        return response()->json($result['resultado'], $result['creada'] ? 201 : 200);
    }

    public function index(Request $r, EntregaAvisos $entrega)
    {
        $d = $r->validate(['clave_idempotencia' => ['required', 'string', 'max:128']]);
        return response()->json($entrega->consultar($this->cliente($r), $d['clave_idempotencia']));
    }

    public function retiro(Request $r, string $id, EntregaAvisos $entrega)
    {
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);
        return response()->json($entrega->retirar($this->cliente($r), $this->clave($r), $id, $d['motivo']));
    }

    private function clave(Request $r): string
    {
        $d = validator(['clave' => $r->header('Idempotency-Key')], ['clave' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_.:-]+$/']])->validate();
        return $d['clave'];
    }

    private function cliente(Request $r): string
    {
        return $r->attributes->get('oauth_client_id');
    }
}
