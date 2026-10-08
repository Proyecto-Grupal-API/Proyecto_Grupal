<?php

namespace App\Services\StudentServices\Benefits;

use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerPeriod;
use Carbon\CarbonInterface;

/**
 * Catálogo de beneficios de servicio que administra el Equipo 5 y
 * consulta informativa de disponibilidad (no reserva nada).
 *
 * Por acuerdo con el Equipo 6 solo existen dos: beca de locker y beca
 * de impresiones. Comidas o transporte no pertenecen a este dominio.
 */
class BenefitCatalog
{
    /**
     * Máximo de páginas por asignación de impresiones.
     */
    public const MAX_PRINT_PAGES = 2000;

    /**
     * @return list<string>
     */
    public function supportedBenefits(): array
    {
        return [BenefitAssignment::BENEFIT_LOCKER, BenefitAssignment::BENEFIT_PRINTS];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(?string $type = null): array
    {
        $items = [
            [
                'beneficio_id' => BenefitAssignment::BENEFIT_LOCKER,
                'tipo' => BenefitAssignment::BENEFIT_LOCKER,
                'nombre' => 'Locker becado',
                'unidad' => 'periodo',
                'cantidad_por_asignacion' => 1,
                'requisitos' => [
                    'beneficiario_id debe ser el User._id de un estudiante activo.',
                    'La vigencia solicitada debe traslaparse con un periodo de lockers activo.',
                ],
                'restricciones' => [
                    'Un locker por estudiante y periodo (también cuenta un locker pagado o en solicitud).',
                    'La vigencia real es la del periodo de lockers asignado.',
                    'Todos los lockers son del mismo tamaño; opcionalmente se puede pedir un edificio.',
                    'Cancelar libera el locker si la asignación sigue activa; una asignación terminada ya no es cancelable.',
                ],
                'vigencia' => [
                    'periodos' => $this->activePeriods(),
                ],
            ],
            [
                'beneficio_id' => BenefitAssignment::BENEFIT_PRINTS,
                'tipo' => BenefitAssignment::BENEFIT_PRINTS,
                'nombre' => 'Saldo de impresiones becado',
                'unidad' => 'paginas',
                'cantidad_maxima' => self::MAX_PRINT_PAGES,
                'requisitos' => [
                    'beneficiario_id debe ser el User._id de un estudiante activo.',
                    'cantidad en páginas (1 a '.self::MAX_PRINT_PAGES.').',
                ],
                'restricciones' => [
                    'Se consume al pagar órdenes de impresión del módulo 5.8 (blanco y negro o color, 1 página = 1 unidad).',
                    'Solo se usa dentro de la vigencia; el saldo no consumido no se reembolsa ni se transfiere.',
                    'Cancelar libera únicamente el saldo no consumido; lo ya consumido no se revierte.',
                ],
                'vigencia' => [
                    'definida_por' => 'convocatoria',
                ],
            ],
        ];

        if ($type === null || $type === '') {
            return $items;
        }

        return array_values(array_filter($items, fn (array $item): bool => $item['tipo'] === $type));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BenefitException
     */
    public function availability(
        string $benefit,
        CarbonInterface $from,
        CarbonInterface $until,
        int $quantity,
        ?string $building = null
    ): array {
        if ($benefit === BenefitAssignment::BENEFIT_PRINTS) {
            return [
                'beneficio_id' => $benefit,
                'disponible' => $quantity >= 1 && $quantity <= self::MAX_PRINT_PAGES,
                'cantidad_solicitada' => $quantity,
                'cantidad_maxima' => self::MAX_PRINT_PAGES,
                'alternativas' => [],
                'consultado_en' => now()->toIso8601String(),
                'nota' => 'Consulta informativa: no constituye reserva.',
            ];
        }

        if ($benefit !== BenefitAssignment::BENEFIT_LOCKER) {
            throw $this->unsupported($benefit);
        }

        $period = $this->lockerPeriodFor($from, $until);

        $byBuilding = Locker::query()
            ->where('status', 'available')
            ->get(['building'])
            ->countBy(fn (Locker $locker): string => (string) $locker->building)
            ->sortKeys();

        $requestedBuilding = $building !== null && $building !== '' ? $building : null;
        $available = $requestedBuilding !== null
            ? (int) ($byBuilding[$requestedBuilding] ?? 0)
            : (int) $byBuilding->sum();

        $alternatives = $requestedBuilding !== null && $available < $quantity
            ? $byBuilding->except([$requestedBuilding])
                ->filter(fn (int $count): bool => $count >= $quantity)
                ->map(fn (int $count, string $name): array => ['edificio' => $name, 'disponibles' => $count])
                ->values()
                ->all()
            : [];

        return [
            'beneficio_id' => $benefit,
            'disponible' => $period !== null && $available >= $quantity,
            'cantidad_solicitada' => $quantity,
            'cantidad_disponible' => $available,
            'edificio' => $requestedBuilding,
            'periodo' => $period !== null ? $this->periodPayload($period) : null,
            'por_edificio' => $byBuilding
                ->map(fn (int $count, string $name): array => ['edificio' => $name, 'disponibles' => $count])
                ->values()
                ->all(),
            'alternativas' => $alternatives,
            'consultado_en' => now()->toIso8601String(),
            'nota' => $period === null
                ? 'No hay un periodo de lockers activo que se traslape con la vigencia solicitada.'
                : 'Consulta informativa: no constituye reserva.',
        ];
    }

    /**
     * Periodo de lockers activo para la vigencia: el que contiene el
     * inicio o, si no, el primero que se traslapa con ella.
     */
    public function lockerPeriodFor(CarbonInterface $from, CarbonInterface $until): ?LockerPeriod
    {
        $periods = LockerPeriod::query()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        return $periods->first(
            fn (LockerPeriod $period): bool => $period->starts_at->lessThanOrEqualTo($from) && $period->ends_at->greaterThan($from)
        ) ?? $periods->first();
    }

    public function unsupported(string $benefit): BenefitException
    {
        return new BenefitException(
            'beneficio_no_soportado',
            "El beneficio [{$benefit}] no lo administra Servicios al Estudiante (Equipo 5). Disponibles: locker, impresiones.",
            422
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activePeriods(): array
    {
        return LockerPeriod::query()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (LockerPeriod $period): array => $this->periodPayload($period))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(LockerPeriod $period): array
    {
        return [
            'periodo_id' => (string) $period->id,
            'codigo' => $period->code,
            'nombre' => $period->name,
            'inicio' => $period->starts_at?->toIso8601String(),
            'fin_exclusiva' => $period->ends_at?->toIso8601String(),
        ];
    }
}
