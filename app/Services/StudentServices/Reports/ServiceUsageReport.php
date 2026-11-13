<?php

namespace App\Services\StudentServices\Reports;

use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\StudentServices\Lockers\LockerRequest;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\StudentServices\Support\SupportTicket;
use Carbon\CarbonImmutable;

/**
 * Reporte operativo de Servicios (rúbrica 10.x): registros creados en un periodo, por servicio y estado,
 * con importes en centavos cuando el servicio los tiene. Lee sólo modelos de E5; no modifica datos.
 */
final class ServiceUsageReport
{
    public const SERVICES = [
        'biblioteca' => [Loan::class, 'Préstamos de biblioteca', null],
        'multas' => [LibraryFine::class, 'Multas de biblioteca', 'amount_cents'],
        'lockers' => [LockerRequest::class, 'Solicitudes de locker', null],
        'reservaciones' => [Reservation::class, 'Reservaciones de instalaciones', null],
        'rentas' => [Rental::class, 'Rentas de equipo', null],
        'ordenes' => [ServiceOrder::class, 'Órdenes de servicio e impresión', 'quoted_amount_cents'],
        'soporte' => [SupportTicket::class, 'Tickets de soporte', null],
    ];

    /** @return array{periodo: array, servicios: list<array>, totales: array} */
    public function build(CarbonImmutable $from, CarbonImmutable $to, ?string $service = null): array
    {
        $tz = config('app.timezone');
        $start = $from->setTimezone($tz)->startOfDay();
        $end = $to->setTimezone($tz)->endOfDay();
        $rows = [];
        foreach (self::SERVICES as $key => [$model, $label, $amountField]) {
            if ($service !== null && $service !== $key) {
                continue;
            }
            $fields = array_values(array_filter(['status', $amountField, $key === 'soporte' ? 'priority' : null, in_array($key, ['biblioteca', 'rentas'], true) ? 'due_at' : null]));
            $records = $model::query()->where('created_at', '>=', $start)->where('created_at', '<=', $end)->get($fields);
            $byStatus = [];
            foreach ($records->groupBy(fn ($r) => (string) ($r->status instanceof \BackedEnum ? $r->status->value : ($r->status ?? 'sin_estado'))) as $status => $group) {
                $byStatus[$status] = ['registros' => $group->count(), 'importe_cents' => $amountField ? (int) $group->sum($amountField) : null];
            }
            ksort($byStatus);
            $row = ['clave' => $key, 'servicio' => $label, 'total' => $records->count(), 'por_estado' => $byStatus,
                'importe_cents' => $amountField ? (int) $records->sum($amountField) : null];
            if ($key === 'soporte') {
                $row['por_prioridad'] = $records->countBy(fn ($r) => (string) ($r->priority instanceof \BackedEnum ? $r->priority->value : $r->priority))->sortKeys()->all();
            }
            if (in_array($key, ['biblioteca', 'rentas'], true)) {
                $row['vencidos'] = $records->filter(fn ($r) => in_array($this->statusOf($r), ['active', 'overdue'], true) && $r->due_at && $r->due_at->isPast())->count();
            }
            $rows[] = $row;
        }

        return ['periodo' => ['desde' => $start->toDateString(), 'hasta' => $end->toDateString(), 'zona_horaria' => $tz],
            'servicios' => $rows, 'totales' => ['registros' => array_sum(array_column($rows, 'total'))]];
    }

    public function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['desde', 'hasta', 'servicio', 'estado', 'registros', 'importe_cents'], escape: '\\');
        foreach ($report['servicios'] as $row) {
            foreach ($row['por_estado'] ?: ['sin_registros' => ['registros' => 0, 'importe_cents' => null]] as $status => $data) {
                fputcsv($out, [$report['periodo']['desde'], $report['periodo']['hasta'], $row['servicio'], $status, $data['registros'], $data['importe_cents'] ?? ''], escape: '\\');
            }
        }
        rewind($out);

        return "\u{FEFF}".stream_get_contents($out);
    }

    private function statusOf($record): string
    {
        return (string) ($record->status instanceof \BackedEnum ? $record->status->value : $record->status);
    }
}
