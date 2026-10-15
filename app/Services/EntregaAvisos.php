<?php

namespace App\Services;

use App\Exceptions\AvisoApiException;
use App\Models\Mensaje;
use App\Models\PreferenciaBandeja;
use App\Models\RetiroAviso;
use App\Support\ExecutesMongoAtomically;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Throwable;

class EntregaAvisos
{
    use ExecutesMongoAtomically;

    public function enviar(string $cliente, string $clave, array $datos): array
    {
        $datos['importancia'] ??= 'normal';
        $datos['expira_en'] = isset($datos['expira_en']) ? Carbon::parse($datos['expira_en'])->utc()->toIso8601String() : null;
        $huella = $this->huella($datos);
        if ($anterior = $this->buscar($cliente, $clave)) {
            $this->comparar($anterior->huella, $huella);
            return ['resultado' => $anterior->resultado_envio, 'creada' => false];
        }
        $accion = app(CatalogoAvisosServicios::class)->accion($datos['tipo'], $datos['accion']);
        if ($datos['expira_en'] && Carbon::parse($datos['expira_en'])->lte(now())) {
            throw new AvisoApiException(422, 'fecha_invalida', 'La expiración debe ser posterior al primer envío.');
        }
        try {
            $identidad = app(IdentidadComunidadApi::class)->estado($datos['destinatario_id']);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                throw new AvisoApiException(422, 'destinatario_invalido', 'Identidad no reconoce al estudiante.');
            }
            throw new AvisoApiException(503, 'identidad_no_disponible', 'No fue posible validar al destinatario con Identidad.');
        } catch (Throwable) {
            throw new AvisoApiException(503, 'identidad_no_disponible', 'No fue posible validar al destinatario con Identidad.');
        }
        if (($identidad['user_id'] ?? $identidad['student_id'] ?? null) !== $datos['destinatario_id']) {
            throw new AvisoApiException(422, 'destinatario_invalido', 'Usa el identificador canónico que devuelve Identidad.');
        }
        $omitida = ! $accion['obligatoria'] && PreferenciaBandeja::where('usuario_id', $datos['destinatario_id'])
            ->where('categoria', 'servicios_estudiante')->where('silenciada', true)->exists();
        $m = new Mensaje;
        // Generate the ID before the atomic insert: the receipt and inbox message are one document.
        $m->id = (string) new \MongoDB\BSON\ObjectId;
        $resultado = ['notificacion_id' => (string) $m->id, 'estado' => $omitida ? 'omitida' : 'entregada',
            'motivo' => $omitida ? 'preferencia_silenciada' : null];
        $m->fill(['cliente_id' => $cliente, 'clave_idempotencia' => $clave, 'huella' => $huella,
            'resultado_envio' => $resultado, 'estado_entrega' => $resultado['estado'], 'categoria' => 'servicios_estudiante',
            'tipo_aviso' => $datos['tipo'], 'usuario_id' => $datos['destinatario_id'],
            'asunto' => $datos['asunto'], 'cuerpo' => $datos['texto'], 'emisor_nombre' => 'Servicios al estudiante',
            'accion_url' => $accion['url'], 'texto_accion' => $accion['texto'],
            'importante' => $accion['obligatoria'] || $datos['importancia'] === 'alta', 'expira_en' => $datos['expira_en']]);
        try {
            $m->save();
        } catch (Throwable $e) {
            // The unique index resolves simultaneous sends and a response lost after insert.
            $anterior = $this->buscar($cliente, $clave);
            if (! $anterior) { throw $e; }
            $this->comparar($anterior->huella, $huella);
            return ['resultado' => $anterior->resultado_envio, 'creada' => false];
        }
        return ['resultado' => $resultado, 'creada' => true];
    }

    public function consultar(string $cliente, string $clave): array
    {
        $m = $this->buscar($cliente, $clave);
        if (! $m) { throw new AvisoApiException(404, 'no_encontrada', 'No se recibió esa clave para este cliente.'); }
        // Immutable delivery receipt; retirement is returned by its own operation.
        return $m->resultado_envio;
    }

    public function retirar(string $cliente, string $clave, string $id, string $motivo): array
    {
        $huella = $this->huella(['notificacion_id' => $id, 'motivo' => $motivo]);
        $buscar = fn () => RetiroAviso::where('cliente_id', $cliente)->where('clave_idempotencia', $clave)->first();
        if ($r = $buscar()) {
            $this->comparar($r->huella, $huella);
            return $r->resultado;
        }
        try {
            return $this->mongoTransaction(function () use ($cliente, $clave, $id, $motivo, $huella): array {
                $m = Mensaje::where('cliente_id', $cliente)->find($id);
                if (! $m) { throw new AvisoApiException(404, 'no_encontrada', 'El aviso no existe para este cliente.'); }
                Mensaje::where('cliente_id', $cliente)->where('_id', $id)->whereNull('retirada_en')
                    ->update(['retirada_en' => now(), 'motivo_retiro' => $motivo]);
                $resultado = ['notificacion_id' => $id, 'estado' => 'retirada'];
                RetiroAviso::create(['cliente_id' => $cliente, 'clave_idempotencia' => $clave,
                    'huella' => $huella, 'notificacion_id' => $id, 'resultado' => $resultado]);
                return $resultado;
            });
        } catch (Throwable $e) {
            if (! $r = $buscar()) { throw $e; }
            $this->comparar($r->huella, $huella);
            return $r->resultado;
        }
    }

    private function buscar(string $cliente, string $clave): ?Mensaje
    {
        return Mensaje::where('cliente_id', $cliente)->where('clave_idempotencia', $clave)->first();
    }

    private function comparar(string $anterior, string $actual): void
    {
        if (! hash_equals($anterior, $actual)) {
            throw new AvisoApiException(409, 'clave_en_conflicto', 'La clave ya se usó con un contenido diferente.');
        }
    }

    private function huella(array $datos): string
    {
        $ordenar = function (array $a) use (&$ordenar): array {
            ksort($a);
            foreach ($a as &$v) { if (is_array($v)) { $v = $ordenar($v); } }
            return $a;
        };
        return hash('sha256', json_encode($ordenar($datos), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
