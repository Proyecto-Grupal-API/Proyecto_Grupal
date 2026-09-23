<?php

namespace App\Services;

use App\Models\AsignacionBeneficio;
use App\Models\Campana;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\RegistroEvento;
use App\Models\RespuestaEncuesta;
use App\Models\SolicitudBeca;
use App\Models\Voto;
use Carbon\CarbonImmutable;

class MetricasComunidad
{
    public function calcular(string $org, string $inicio, string $fin, CarbonImmutable $corte): array
    {
        $desde = CarbonImmutable::createFromFormat('!Y-m-d', $inicio, 'America/Mexico_City')->utc();
        $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $fin, 'America/Mexico_City')->addDay()->utc();
        $periodo = fn ($q, $campo) => $q->where($campo, '>=', $desde)->where($campo, '<', $hasta)->where($campo, '<=', $corte);
        $metricas = [];
        $agregar = function ($grupo, $clave, $etiqueta, $valor, $criterio, $unidad = 'cantidad') use (&$metricas) {
            $metricas[] = compact('grupo', 'clave', 'etiqueta', 'valor', 'criterio', 'unidad');
        };
        $eventos = $periodo(Evento::where('organizacion_id', $org)->where('estado', 'publicado'), 'fecha_hora_inicio')->pluck('id');
        $registros = RegistroEvento::whereIn('evento_id', $eventos);
        $confirmados = (clone $registros)->where('estado', 'confirmada');
        $criterio = 'Eventos publicados cuyo inicio cae en el periodo y ya ocurrió al generar; reservas según su estado al generar.';
        $agregar('Eventos', 'eventos_iniciados', 'Eventos publicados con inicio en el periodo', $eventos->count(), $criterio);
        $agregar('Eventos', 'eventos_cancelados', 'Eventos cancelados', $periodo(Evento::where('organizacion_id', $org)->where('estado', 'cancelado'), 'fecha_hora_inicio')->count(), 'Eventos cancelados cuyo inicio previsto cae en el periodo y ya pasó.');
        $agregar('Eventos', 'reservas_confirmadas', 'Reservas confirmadas', (clone $confirmados)->count(), $criterio);
        $agregar('Eventos', 'reservas_espera', 'Reservas en espera', (clone $registros)->where('estado', 'espera')->count(), $criterio);
        $agregar('Eventos', 'reservas_pago_pendiente', 'Reservas con pago pendiente', (clone $confirmados)->where('estado_pago', 'pendiente')->count(), $criterio.' No representan cobros realizados.');
        $agregar('Eventos', 'asistencias', 'Asistencias registradas', (clone $confirmados)->where('estado_asistencia', 'asistio')->whereNotNull('asistio_en')->where('asistio_en', '<=', $corte)->count(), $criterio.' Requiere check-in registrado.');
        $solicitudes = $periodo(SolicitudBeca::where('organizacion_id', $org)->where('estado', '!=', 'borrador'), 'enviado_en');
        $criterio = 'Solicitudes enviadas en el periodo, según su estado al generar. Excluye borradores y expedientes nunca enviados.';
        $agregar('Becas', 'solicitudes_enviadas', 'Solicitudes enviadas', (clone $solicitudes)->count(), $criterio);
        foreach (['pendiente' => 'Pendientes de revisión', 'en_revision' => 'En revisión', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas', 'retirada' => 'Retiradas', 'cancelada' => 'Canceladas'] as $estado => $etiqueta) {
            $agregar('Becas', 'solicitudes_'.$estado, $etiqueta, (clone $solicitudes)->where('estado', $estado)->count(), $criterio);
        }
        $aprobadas = $periodo(SolicitudBeca::where('organizacion_id', $org)->whereNotNull('enviado_en')->where('estado', 'aprobada'), 'dictaminado_en')->get(['id', 'usuario_id']);
        $asignaciones = AsignacionBeneficio::where('organizacion_id', $org)->whereIn('solicitud_id', $aprobadas->pluck('id'))->where('estado', 'pendiente_integracion')->get()->unique('solicitud_id');
        $criterio = 'Aprobaciones dictaminadas en el periodo; pueden corresponder a solicitudes enviadas antes. La aprobación local no acredita una entrega.';
        $agregar('Apoyos', 'aprobaciones_periodo', 'Apoyos aprobados en el periodo', $aprobadas->count(), $criterio);
        $agregar('Apoyos', 'personas_aprobadas', 'Personas distintas con apoyo aprobado', $aprobadas->pluck('usuario_id')->filter()->unique()->count(), $criterio);
        $agregar('Apoyos', 'asignaciones_pendientes', 'Asignaciones pendientes de integración', $asignaciones->count(), $criterio);
        $monto = $asignaciones->sum(fn ($a) => ($a->contrato['moneda'] ?? null) === 'MXN' ? max(0, (int) ($a->contrato['monto_centavos'] ?? 0)) : 0);
        $agregar('Apoyos', 'monto_pendiente_centavos', 'Monto aprobado pendiente de integración', $monto, $criterio.' Suma los montos MXN de los contratos pendientes, no pagos ni saldo disponible.', 'centavos_mxn');
        $campanas = Campana::where('organizacion_id', $org)->pluck('id');
        $mensajes = $periodo(Mensaje::where('organizacion_id', $org)->whereIn('campaña_id', $campanas), 'creado_en');
        $agregar('Comunicación', 'mensajes_entregados', 'Mensajes entregados en bandeja', (clone $mensajes)->count(), 'Entregas de campañas propias creadas en el periodo, incluso archivadas o eliminadas lógicamente.');
        $agregar('Comunicación', 'mensajes_leidos', 'Entregas leídas al menos una vez', (clone $mensajes)->whereNotNull('primera_lectura_en')->where('primera_lectura_en', '<=', $corte)->count(), 'De los mensajes entregados en el periodo, lecturas acumuladas al generar, aunque se hayan marcado sin leer después.');
        foreach (['encuestas' => [Encuesta::class, RespuestaEncuesta::class, 'Encuestas'], 'votaciones' => [Eleccion::class, Voto::class, 'Votaciones']] as $clave => [$modelo, $respuesta, $nombre]) {
            $cerradas = $modelo::where('organizacion_id', $org)->whereNotNull('revision')->where(function ($q) use ($periodo) {
                $q->where(fn ($q) => $periodo($q->where('estado', 'cerrada'), 'cerrado_en'))->orWhere(fn ($q) => $periodo($q->where('estado', 'publicada'), 'fecha_fin'));
            })->pluck('id');
            $agregar('Participación', $clave.'_cerradas', $nombre.' cerradas', $cerradas->count(), 'Cierre efectivo dentro del periodo: anticipado o por fecha. Excluye borradores, procesos abiertos y cancelados.');
            $agregar('Participación', $clave.'_participaciones', 'Participaciones en '.$nombre, $respuesta::where('organizacion_id', $org)->whereIn('consulta_id', $cerradas)->count(), 'Participaciones de los procesos cerrados en el periodo. No se publican selecciones ni padrón nominal.');
        }

        return $metricas;
    }
}
