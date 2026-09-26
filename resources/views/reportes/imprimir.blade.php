<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $reporte['titulo'] }}</title>
<style>body{font:15px system-ui,sans-serif;color:#17253e;max-width:1000px;margin:32px auto;padding:0 20px}h1{overflow-wrap:anywhere}table{border-collapse:collapse;width:100%;margin:24px 0}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left;vertical-align:top}td:last-child{font-size:12px}tr{break-inside:avoid}.nota{white-space:pre-wrap;overflow-wrap:anywhere}@media print{button{display:none}body{margin:0;font-size:11px}thead{display:table-header-group}}@page{size:A4;margin:15mm}</style></head><body>
<button onclick="window.print()">Imprimir / guardar PDF</button><p>Campus Digital · {{ $reporte['organizacion_nombre'] }}</p><h1>{{ $reporte['titulo'] }}</h1>
<p>Estado: <strong>{{ $reporte['estado'] }}</strong> · Del {{ $reporte['fecha_inicio'] }} al {{ $reporte['fecha_fin'] }} (inclusive, Ciudad de México).</p>
<p>Generado: {{ \Carbon\Carbon::parse($reporte['generado_en'])->timezone('America/Mexico_City')->format('d/m/Y H:i:s') }}</p>
<p class="nota">{{ $reporte['descripcion'] }}</p>
@if($reporte['motivo_retiro'])<p class="nota">Motivo de retiro: {{ $reporte['motivo_retiro'] }}</p>@endif
<p>Las cifras conservan la copia generada. Las aprobaciones y asignaciones pendientes no acreditan pagos ni entrega de servicios. Caja y entregas externas: integración pendiente.</p>
<table><thead><tr><th>Grupo</th><th>Indicador</th><th>Valor</th><th>Criterio</th></tr></thead><tbody>
@foreach($reporte['metricas'] as $m)<tr><td>{{ $m['grupo'] }}</td><td>{{ $m['etiqueta'] }}</td><td>{{ $m['unidad'] === 'centavos_mxn' ? '$'.number_format($m['valor']/100, 2).' MXN' : $m['valor'] }}</td><td>{{ $m['criterio'] }}</td></tr>@endforeach
</tbody></table><p>Referencia del reporte: {{ $reporte['id'] }} · Definición de indicadores v{{ $reporte['version'] }}.</p></body></html>
