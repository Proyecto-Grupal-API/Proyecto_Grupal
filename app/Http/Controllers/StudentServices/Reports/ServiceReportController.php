<?php

namespace App\Http\Controllers\StudentServices\Reports;

use App\Http\Controllers\Controller;
use App\Services\StudentServices\Reports\ServiceUsageReport;
use App\Services\StudentServices\ServiceAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reporte operativo de Servicios para personal autorizado (panel con indicadores y exportación CSV con los mismos filtros). */
final class ServiceReportController extends Controller
{
    public function __construct(private readonly ServiceUsageReport $report, private readonly ServiceAuthority $authority) {}

    public function index(Request $request): Response
    {
        [$from, $to, $service] = $this->filters($request);

        return Inertia::render('student-services/reports/Index', [
            'report' => $this->report->build($from, $to, $service),
            'filters' => ['desde' => $from->toDateString(), 'hasta' => $to->toDateString(), 'servicio' => $service ?? ''],
            'services' => collect(ServiceUsageReport::SERVICES)->map(fn ($s, $k) => ['clave' => $k, 'nombre' => $s[1]])->values(),
        ]);
    }

    public function csv(Request $request)
    {
        [$from, $to, $service] = $this->filters($request);
        $name = 'reporte-servicios-'.$from->toDateString().'-a-'.$to->toDateString().'.csv';

        return response($this->report->csv($this->report->build($from, $to, $service)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store, private']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?string} */
    private function filters(Request $request): array
    {
        abort_unless($this->authority->staff($request->user()), 403, 'El reporte de Servicios requiere personal autorizado.');
        $v = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'servicio' => ['nullable', Rule::in(array_keys(ServiceUsageReport::SERVICES))],
        ], ['hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);
        $tz = config('app.timezone');
        $to = isset($v['hasta']) ? CarbonImmutable::createFromFormat('!Y-m-d', $v['hasta'], $tz) : CarbonImmutable::now($tz)->startOfDay();
        $from = isset($v['desde']) ? CarbonImmutable::createFromFormat('!Y-m-d', $v['desde'], $tz) : $to->subDays(29);
        if ($from->diffInDays($to) > 366) {
            throw \Illuminate\Validation\ValidationException::withMessages(['desde' => 'El periodo no puede exceder un año.']);
        }

        return [$from, $to, $v['servicio'] ?? null];
    }
}
