<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Comprobante Campus Digital</title><style>
@page{margin:45px 40px;}body{font-family:DejaVu Sans,sans-serif;font-size:10px;line-height:1.45;color:#24334b;}h1{font-size:21px;color:#00338d;margin:5px 0;}h2{font-size:13px;color:#00338d;margin:22px 0 8px;}p{margin:6px 0;}.brand{color:#00338d;font-weight:bold;font-size:11px;letter-spacing:2px;}.rule{border-bottom:2px solid #00338d;padding-bottom:15px;}.muted{color:#62718a;}.id{font-size:9px;word-wrap:break-word;}.notice{border:1px solid #dec886;background:#fff8e6;padding:10px;margin:14px 0;}table{border-collapse:collapse;width:100%;table-layout:fixed;}th,td{padding:9px 7px;border-bottom:1px solid #dce3ed;vertical-align:top;word-wrap:break-word;}th{text-align:left;color:#00338d;background:#f2f5f9;}thead{display:table-header-group;}tr{page-break-inside:avoid;}.amount{text-align:right;width:30%;}.small{font-size:8px;}.footer{border-top:1px solid #dce3ed;padding-top:10px;margin-top:24px;font-size:8px;color:#62718a;}</style></head><body>
<div class="rule"><div class="brand">CAMPUS DIGITAL</div><h1>Comprobante de operación</h1><p>{{ $receipt['source']==='CASH'?'Comprobante de caja':'Participación de tu wallet' }}</p></div>
<p class="id"><strong>ID:</strong> {{ $receipt['id'] }}</p><p><strong>Estado:</strong> {{ $receipt['status'] }} · <strong>Moneda:</strong> {{ $receipt['currency'] }}</p>
<p><strong>Fecha de operación:</strong> {{ $date($receipt['created_at']) }}</p>
@if(!empty($receipt['cash_register_name']))<p><strong>Caja:</strong> {{ $receipt['cash_register_name'] }}</p><p class="id"><strong>Asociación:</strong> {{ $receipt['association_id'] }}</p>
@endif

@if(!empty($receipt['historical_notice']))<div class="notice">{{ $receipt['historical_notice']['message'] }}</div>
@endif

@if(!empty($receipt['purchase']))<h2>Desglose del pago</h2><table><tbody>
<tr><td>Total</td><td class="amount">{{ $money($receipt['purchase']['total_amount_cents'],$receipt['currency']) }}</td></tr>
<tr><td>Wallet</td><td class="amount">{{ $money($receipt['purchase']['wallet_amount_cents'],$receipt['currency']) }}</td></tr>
<tr><td>Bonos</td><td class="amount">{{ $money($receipt['purchase']['bonus_amount_cents'],$receipt['currency']) }}</td></tr>
@foreach($receipt['purchase']['bonuses'] as $bonus)<tr><td class="id">Bono {{ $bonus['bonus_id'] }}</td><td class="amount">{{ $money($bonus['amount_cents'],$receipt['currency']) }}</td></tr>
@endforeach

</tbody></table>
@endif

@if(count($receipt['entries']))<h2>Movimientos registrados</h2><table><thead><tr><th>Movimiento</th><th class="amount">Importe</th></tr></thead><tbody>
@foreach($receipt['entries'] as $entry)<tr><td>{{ $entry['movement_type'] }}<p class="small muted">{{ $date($entry['created_at']) }}</p>
@if(array_key_exists('available_balance_after_cents',$entry))<p class="small">Disponible después: {{ $money($entry['available_balance_after_cents'],$receipt['currency']) }}</p>
@endif

@if(array_key_exists('held_balance_after_cents',$entry))<p class="small">Retenido después: {{ $money($entry['held_balance_after_cents'],$receipt['currency']) }}</p>
@endif

</td><td class="amount">{{ $money($entry['amount_cents'],$receipt['currency']) }}</td></tr>
@endforeach
</tbody></table>
@endif

@if(count($receipt['cash_receipts']))<h2>Folios de caja existentes</h2>@foreach($receipt['cash_receipts'] as $cash)<p class="id"><strong>{{ $cash['folio'] }}</strong></p><p class="small muted">{{ $date($cash['issued_at']) }}</p>
@endforeach

@endif

@if(!empty($receipt['original_transaction_id']))<p class="id"><strong>Operación original:</strong> {{ $receipt['original_transaction_id'] }}</p>
@endif

<div class="footer"><p>Archivo generado: {{ $date($generated_at) }}. Esta fecha no sustituye la fecha de operación.</p><p>Consulta de registros existentes. No genera un nuevo movimiento ni un folio adicional. No constituye factura fiscal ni contiene firma digital.</p></div>
</body></html>
