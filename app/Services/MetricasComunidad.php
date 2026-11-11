<?php

namespace App\Services;

use App\Models\AsignacionBeneficio;
use App\Models\Campana;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\PagoReservaEvento;
use App\Models\RegistroEvento;
use App\Models\RespuestaEncuesta;
use App\Models\SolicitudBeca;
use App\Models\Voto;
use App\Support\BecaFinanceContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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
        $confirmados = (clone $registros)->conCupo($corte);
        $criterio = 'Eventos publicados cuyo inicio cae en el periodo y ya ocurrió al generar; reservas según su estado al generar.';
        $agregar('Eventos', 'eventos_iniciados', 'Eventos publicados con inicio en el periodo', $eventos->count(), $criterio);
        $agregar('Eventos', 'eventos_cancelados', 'Eventos cancelados', $periodo(Evento::where('organizacion_id', $org)->where('estado', 'cancelado'), 'fecha_hora_inicio')->count(), 'Eventos cancelados cuyo inicio previsto cae en el periodo y ya pasó.');
        $agregar('Eventos', 'reservas_confirmadas', 'Reservas confirmadas', (clone $confirmados)->count(), $criterio);
        $agregar('Eventos', 'reservas_espera', 'Reservas en espera', (clone $registros)->where('estado', 'espera')->count(), $criterio);
        $agregar('Eventos', 'reservas_pago_pendiente', 'Reservas con pago pendiente', (clone $confirmados)->where('estado_pago', 'pendiente')->count(), $criterio.' No representan cobros realizados.');
        $agregar('Eventos', 'asistencias', 'Asistencias registradas', (clone $confirmados)->where('estado_asistencia', 'asistio')->whereNotNull('asistio_en')->where('asistio_en', '<=', $corte)->count(), $criterio.' Requiere check-in registrado.');
        // El cobro no depende de que el evento ya haya iniciado ni de que siga publicado.
        $eventosCobro = Evento::where('organizacion_id', $org)->pluck('id');
        $reservasCobro = RegistroEvento::whereIn('evento_id', $eventosCobro)->where('moneda', 'MXN')
            ->get(['id', 'evento_id', 'usuario_id'])->keyBy('id');
        $cobros = PagoReservaEvento::where('organizacion_id', $org)->whereIn('evento_id', $eventosCobro)
            ->whereIn('registro_id', $reservasCobro->keys())->whereIn('estado', ['aplicado', 'devolucion_pendiente', 'devuelto'])
            ->where('creado_en', '<=', $corte)->where('actualizado_en', '<=', $corte)
            ->orderBy('actualizado_en', 'desc')->orderBy('_id', 'desc')
            ->get(['registro_id', 'evento_id', 'usuario_id', 'estado', 'monto_centavos', 'comprobante', 'comprobante_devolucion', 'confirmado_en', 'creado_en'])
            ->filter(fn (PagoReservaEvento $pago) => Str::isUuid($pago->comprobante))
            ->unique(fn (PagoReservaEvento $pago) => strtolower($pago->comprobante))
            ->filter(function (PagoReservaEvento $pago) use ($reservasCobro, $desde, $hasta, $corte) {
                $reserva = $reservasCobro->get((string) $pago->registro_id);
                // El cast integer del modelo ocultaría strings, floats y booleanos mal formados.
                $monto = $pago->getRawOriginal('monto_centavos');
                if (! $reserva || $reserva->evento_id !== $pago->evento_id || $reserva->usuario_id !== $pago->usuario_id
                    || ! is_int($monto) || $monto <= 0) {
                    return false;
                }
                // PagosEvento guarda el confirmed_at de E2 como string UTC con milisegundos.
                $confirmado = $pago->getRawOriginal('confirmado_en');
                if ($confirmado !== null) {
                    if (! is_string($confirmado) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $confirmado)) { return false; }
                    try {
                        $fecha = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $confirmado, 'UTC');
                        if ($fecha->format('Y-m-d\TH:i:s.v\Z') !== $confirmado) { return false; }
                    } catch (\Exception) {
                        return false;
                    }
                } else {
                    $fecha = $pago->creado_en;
                }

                return $fecha && $fecha->gte($desde) && $fecha->lt($hasta) && $fecha->lte($corte);
            });
        $devoluciones = $cobros->filter(fn (PagoReservaEvento $pago) => $pago->estado === 'devuelto'
            && Str::isUuid($pago->comprobante_devolucion))
            ->unique(fn (PagoReservaEvento $pago) => strtolower($pago->comprobante_devolucion));
        $bruto = $cobros->sum('monto_centavos');
        $devuelto = $devoluciones->sum('monto_centavos');
        $criterioCobros = 'Fecha de confirmación E2 (confirmado_en); si falta, cohorte de preparación del intento (creado_en). '
            .'Solo estados aplicado, devolucion_pendiente o devuelto, con comprobante UUID y monto entero positivo MXN de reservas y eventos propios. '
            .'Preparación y última actualización hasta el corte; por comprobante se conserva el registro más reciente. No es saldo ni dinero disponible.';
        $criterioDevoluciones = $criterioCobros.' De los cobros seleccionados, solo devuelto con comprobante_devolucion UUID único. '
            .'E6 no persiste fecha financiera de devolución: refleja evidencia registrada hasta el corte, no devoluciones ocurridas en el periodo.';
        $agregar('Eventos', 'eventos_cobros_confirmados', 'Cobros E2 (fecha E2 o cohorte de preparación)', $cobros->count(), $criterioCobros);
        $agregar('Eventos', 'eventos_cobrado_bruto_centavos', 'Cobrado bruto E2 (fecha E2 o cohorte de preparación)', $bruto, $criterioCobros, 'centavos_mxn');
        $agregar('Eventos', 'eventos_devoluciones_confirmadas', 'Devoluciones E2 verificables de los cobros seleccionados', $devoluciones->count(), $criterioDevoluciones);
        $agregar('Eventos', 'eventos_devuelto_centavos', 'Devuelto E2 de los cobros seleccionados', $devuelto, $criterioDevoluciones, 'centavos_mxn');
        $agregar('Eventos', 'eventos_cobrado_neto_centavos', 'Cobrado neto de devoluciones verificables (no saldo)', $bruto - $devuelto, $criterioDevoluciones, 'centavos_mxn');

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
        $tiposEntrega = ['locker' => 'Lockers entregados', 'impresiones' => 'Apoyos de impresiones entregados', 'bono' => 'Bonos BECA emitidos'];
        $entregadas = $periodo(AsignacionBeneficio::where('organizacion_id', $org)->where('estado', 'asignada')
            ->whereIn('contrato.tipo', array_keys($tiposEntrega)), 'entregado_en')
            ->get(['solicitud_id', 'contrato', 'recibo_servicios', 'recibo_finanzas'])
            ->filter(function (AsignacionBeneficio $a) {
                if ($a->contrato['tipo'] === 'bono') {
                    return ($a->contrato['equipo_destino'] ?? null) === 2
                        && ($a->recibo_finanzas['reference'] ?? null) === BecaFinanceContract::reference((string) $a->solicitud_id)
                        && Str::isUuid($a->recibo_finanzas['bonus_id'] ?? null)
                        && Str::isUuid($a->recibo_finanzas['operation_id'] ?? null);
                }

                $recibo = $a->recibo_servicios['asignacion_id'] ?? null;

                return ($a->contrato['equipo_destino'] ?? null) === 5 && is_string($recibo) && trim($recibo) !== '';
            })->unique('solicitud_id')->countBy(fn (AsignacionBeneficio $a) => $a->contrato['tipo']);
        $criterio = 'Entregas confirmadas por E5 o emisiones confirmadas por E2, registradas en Comunidad dentro del periodo, aunque el dictamen sea anterior.';
        $agregar('Apoyos', 'apoyos_entregados', 'Apoyos con entrega confirmada', $entregadas->sum(), $criterio);
        foreach ($tiposEntrega as $tipo => $etiqueta) {
            $agregar('Apoyos', 'apoyos_entregados_'.$tipo, $etiqueta, $entregadas->get($tipo, 0), $criterio);
        }

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
