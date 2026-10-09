<?php

namespace App\Services\StudentServices\Calendars;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Modulo 5.10 - Reglas puras del calendario (sin base de datos).
 *
 * Todo lo que se puede decidir solo con las reglas del recurso y las
 * fechas vive aquí para poder probarlo de forma aislada: horario,
 * franjas, duración, días de operación, anticipación, cancelación,
 * ventana de check-in, no-show y ocupación máxima simultánea.
 */
class CalendarRuleChecker
{
    /**
     * Minutos antes del inicio en los que ya se permite el check-in.
     */
    public const EARLY_CHECK_IN_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    public function violations(
        array $rules,
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $now
    ): array {
        if ($end->lessThanOrEqualTo($start)) {
            return ['La hora de fin debe ser posterior a la de inicio.'];
        }

        if (! $start->isSameDay($end)) {
            return ['La reserva debe iniciar y terminar el mismo día.'];
        }

        $violations = [];

        $slot = max(1, (int) $rules['slot_minutes']);

        if ($end->lessThanOrEqualTo($now) || $start->lessThan($now->copy()->subMinutes($slot))) {
            $violations[] = 'No puedes reservar un horario que ya pasó.';
        }

        $maxAdvanceDays = (int) $rules['max_advance_days'];

        if ($maxAdvanceDays > 0 && $start->copy()->startOfDay()->greaterThan($now->copy()->startOfDay()->addDays($maxAdvanceDays))) {
            $violations[] = "Solo se puede reservar con un máximo de {$maxAdvanceDays} día(s) de anticipación.";
        }

        $operatingDays = array_map('intval', (array) $rules['operating_days']);

        if ($operatingDays !== [] && ! in_array($start->dayOfWeekIso, $operatingDays, true)) {
            $violations[] = 'El recurso no opera ese día de la semana.';
        }

        $openMinutes = $this->timeToMinutes((string) $rules['open_time']);
        $closeMinutes = $this->timeToMinutes((string) $rules['close_time']);
        $startMinutes = $start->hour * 60 + $start->minute;
        $endMinutes = $end->hour * 60 + $end->minute;

        if ($startMinutes < $openMinutes || $endMinutes > $closeMinutes) {
            $violations[] = "El horario debe estar dentro de {$rules['open_time']} – {$rules['close_time']}.";
        }

        $duration = $endMinutes - $startMinutes;

        if (($startMinutes - $openMinutes) % $slot !== 0 || $duration % $slot !== 0) {
            $violations[] = "Las reservas deben ajustarse a franjas de {$slot} minutos.";
        }

        $minimum = (int) $rules['min_booking_minutes'];
        $maximum = (int) $rules['max_booking_minutes'];

        if ($minimum > 0 && $duration < $minimum) {
            $violations[] = "La duración mínima por reserva es de {$minimum} minutos.";
        }

        if ($maximum > 0 && $duration > $maximum) {
            $violations[] = "La duración máxima por reserva es de {$maximum} minutos.";
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function assertValid(
        array $rules,
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $now
    ): void {
        $violations = $this->violations($rules, $start, $end, $now);

        if ($violations !== []) {
            throw new RuntimeException($violations[0]);
        }
    }

    /**
     * Valida que las reglas capturadas por un administrador sean
     * coherentes entre sí.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    public function ruleViolations(array $rules): array
    {
        $violations = [];

        $open = $this->timeToMinutes((string) $rules['open_time']);
        $close = $this->timeToMinutes((string) $rules['close_time']);
        $slot = (int) $rules['slot_minutes'];

        if ($close <= $open) {
            $violations[] = 'La hora de cierre debe ser posterior a la de apertura.';
        }

        if ($slot < 5) {
            $violations[] = 'La franja mínima es de 5 minutos.';
        }

        if ($slot > 0 && ($close - $open) % $slot !== 0) {
            $violations[] = 'El horario de operación debe dividirse exactamente en franjas.';
        }

        if ((int) $rules['min_booking_minutes'] > (int) $rules['max_booking_minutes']) {
            $violations[] = 'La duración mínima no puede ser mayor que la máxima.';
        }

        if ($slot > 0 && (int) $rules['max_booking_minutes'] % $slot !== 0) {
            $violations[] = 'La duración máxima debe ser múltiplo de la franja.';
        }

        if ($slot > 0 && (int) $rules['min_booking_minutes'] % $slot !== 0) {
            $violations[] = 'La duración mínima debe ser múltiplo de la franja.';
        }

        return $violations;
    }

    /**
     * Franjas de un día según el horario y la duración de franja.
     *
     * @param  array<string, mixed>  $rules
     * @return list<array{start: string, end: string}>
     */
    public function slotsForDay(array $rules): array
    {
        $open = $this->timeToMinutes((string) $rules['open_time']);
        $close = $this->timeToMinutes((string) $rules['close_time']);
        $slot = max(5, (int) $rules['slot_minutes']);

        $slots = [];

        for ($minute = $open; $minute + $slot <= $close; $minute += $slot) {
            $slots[] = [
                'start' => $this->minutesToTime($minute),
                'end' => $this->minutesToTime($minute + $slot),
            ];
        }

        return $slots;
    }

    /**
     * Un estudiante puede cancelar una reserva confirmada solo si
     * todavía no entra a la ventana mínima de cancelación.
     *
     * @param  array<string, mixed>  $rules
     */
    public function canStudentCancel(
        array $rules,
        CarbonInterface $start,
        CarbonInterface $now
    ): bool {
        $limit = $start->copy()->subMinutes((int) $rules['cancel_before_minutes']);

        return $now->lessThan($limit);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{opens_at: CarbonInterface, closes_at: CarbonInterface}
     */
    public function checkInWindow(
        array $rules,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $closesAt = $start->copy()->addMinutes((int) $rules['no_show_tolerance_minutes']);

        if ($closesAt->greaterThan($end)) {
            $closesAt = $end->copy();
        }

        return [
            'opens_at' => $start->copy()->subMinutes(self::EARLY_CHECK_IN_MINUTES),
            'closes_at' => $closesAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function isNoShow(
        array $rules,
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $now
    ): bool {
        return $now->greaterThan($this->checkInWindow($rules, $start, $end)['closes_at']);
    }

    /**
     * Máximo de reservas simultáneas dentro de [start, end).
     *
     * Dos reservas 9:00-10:00 y 10:00-11:00 nunca coinciden, así que una
     * solicitud 9:00-11:00 sobre una sala de capacidad 2 sí cabe (ocupación
     * máxima 1), aunque se traslape con ambas.
     *
     * @param  iterable<array{0: CarbonInterface, 1: CarbonInterface}>  $ranges
     */
    public function peakOccupancy(
        iterable $ranges,
        CarbonInterface $start,
        CarbonInterface $end
    ): int {
        $events = [];

        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            $from = max($rangeStart->getTimestamp(), $start->getTimestamp());
            $to = min($rangeEnd->getTimestamp(), $end->getTimestamp());

            if ($from >= $to) {
                continue;
            }

            $events[] = [$from, 1];
            $events[] = [$to, -1];
        }

        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $current = 0;
        $peak = 0;

        foreach ($events as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    public function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hours * 60 + $minutes;
    }

    public function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
