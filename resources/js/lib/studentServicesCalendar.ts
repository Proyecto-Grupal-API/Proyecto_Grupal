/*
|--------------------------------------------------------------------------
| Modulo 5.10 - Utilidades de calendario para el frontend
|--------------------------------------------------------------------------
|
| Replican (solo para mostrar disponibilidad) las reglas que el backend
| valida en CalendarRuleChecker. La decisión final siempre la toma el
| servidor; aquí solo se pinta qué franjas se ven libres, llenas o
| bloqueadas para guiar al estudiante.
|
*/

export interface CalendarRules {
    open_time: string;
    close_time: string;
    slot_minutes: number;
    min_booking_minutes: number;
    max_booking_minutes: number;
    cancel_before_minutes: number;
    no_show_tolerance_minutes: number;
    max_active_per_student: number;
    max_advance_days: number;
    max_no_shows: number;
    no_show_window_days: number;
    operating_days: number[];
}

export interface BookedRange {
    start_at: string;
    end_at: string;
}

export interface CalendarBlockRange extends BookedRange {
    reason: string;
}

export type SlotState = 'free' | 'full' | 'blocked' | 'past';

export interface SlotInfo {
    start: string;
    end: string;
    occupied: number;
    state: SlotState;
    blockReason: string | null;
}

export const WEEKDAY_LABELS: Record<number, string> = {
    1: 'Lun',
    2: 'Mar',
    3: 'Mié',
    4: 'Jue',
    5: 'Vie',
    6: 'Sáb',
    7: 'Dom',
};

export function timeToMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + (minutes || 0);
}

export function minutesToTime(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

export function formatTime12(time: string): string {
    const [h, m] = time.split(':').map(Number);
    const period = h < 12 ? 'a.m.' : 'p.m.';
    const h12 = h % 12 === 0 ? 12 : h % 12;

    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
}

export function dateKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function todayKey(): string {
    return dateKey(new Date());
}

export function addDays(key: string, days: number): string {
    const [y, m, d] = key.split('-').map(Number);

    return dateKey(new Date(y, m - 1, d + days));
}

/** Día ISO (1 = lunes ... 7 = domingo) de una fecha YYYY-MM-DD. */
export function isoWeekday(key: string): number {
    const [y, m, d] = key.split('-').map(Number);
    const day = new Date(y, m - 1, d).getDay();

    return day === 0 ? 7 : day;
}

export function localDate(key: string, time: string): Date {
    const [y, m, d] = key.split('-').map(Number);
    const [hh, mm] = time.split(':').map(Number);

    return new Date(y, m - 1, d, hh, mm, 0, 0);
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

export function formatTimeOfIso(iso: string): string {
    return new Date(iso).toLocaleTimeString('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

export function minutesLabel(value: number): string {
    if (value >= 1440 && value % 1440 === 0) {
        const days = value / 1440;

        return `${days} día${days === 1 ? '' : 's'}`;
    }

    if (value >= 60 && value % 60 === 0) {
        return `${value / 60} h`;
    }

    return `${value} min`;
}

/** Inicios de franja posibles según horario y duración de franja. */
export function slotStarts(rules: CalendarRules): string[] {
    const open = timeToMinutes(rules.open_time);
    const close = timeToMinutes(rules.close_time);
    const step = Math.max(5, rules.slot_minutes);
    const starts: string[] = [];

    for (let minute = open; minute + step <= close; minute += step) {
        starts.push(minutesToTime(minute));
    }

    return starts;
}

/** Duraciones válidas: múltiplos de la franja entre mínimo y máximo. */
export function durationOptions(rules: CalendarRules): number[] {
    const step = Math.max(5, rules.slot_minutes);
    const options: number[] = [];

    for (
        let minutes = Math.max(step, rules.min_booking_minutes);
        minutes <= rules.max_booking_minutes;
        minutes += step
    ) {
        options.push(minutes);
    }

    return options;
}

export function isOperatingDay(rules: CalendarRules, key: string): boolean {
    return (
        rules.operating_days.length === 0 ||
        rules.operating_days.includes(isoWeekday(key))
    );
}

export function isWithinAdvance(rules: CalendarRules, key: string): boolean {
    return (
        key >= todayKey() && key <= addDays(todayKey(), rules.max_advance_days)
    );
}

/** Ocupación máxima simultánea dentro de [start, end). */
export function peakOccupancy(
    ranges: BookedRange[],
    start: Date,
    end: Date,
): number {
    const events: [number, number][] = [];

    for (const range of ranges) {
        const from = Math.max(
            new Date(range.start_at).getTime(),
            start.getTime(),
        );
        const to = Math.min(new Date(range.end_at).getTime(), end.getTime());

        if (from < to) {
            events.push([from, 1], [to, -1]);
        }
    }

    events.sort((a, b) => a[0] - b[0] || a[1] - b[1]);

    let current = 0;
    let peak = 0;

    for (const [, delta] of events) {
        current += delta;
        peak = Math.max(peak, current);
    }

    return peak;
}

export function blockFor(
    blocks: CalendarBlockRange[],
    start: Date,
    end: Date,
): CalendarBlockRange | null {
    return (
        blocks.find(
            (block) =>
                new Date(block.start_at).getTime() < end.getTime() &&
                new Date(block.end_at).getTime() > start.getTime(),
        ) ?? null
    );
}

export function slotsForDay(
    rules: CalendarRules,
    key: string,
    capacity: number,
    ranges: BookedRange[],
    blocks: CalendarBlockRange[],
): SlotInfo[] {
    const now = Date.now();

    return slotStarts(rules).map((start) => {
        const startDate = localDate(key, start);
        const endDate = new Date(
            startDate.getTime() + rules.slot_minutes * 60000,
        );
        const occupied = peakOccupancy(ranges, startDate, endDate);
        const block = blockFor(blocks, startDate, endDate);

        let state: SlotState = occupied >= capacity ? 'full' : 'free';

        if (block) {
            state = 'blocked';
        } else if (endDate.getTime() <= now) {
            state = 'past';
        }

        return {
            start,
            end: minutesToTime(timeToMinutes(start) + rules.slot_minutes),
            occupied,
            state,
            blockReason: block?.reason ?? null,
        };
    });
}

/**
 * Resumen de un día para colorear el calendario mensual:
 * 0 = sin reservas, entre 0 y 1 = con reservas, 1 = lleno o bloqueado.
 */
export function dayRatio(
    rules: CalendarRules,
    key: string,
    capacity: number,
    ranges: BookedRange[],
    blocks: CalendarBlockRange[],
): number {
    const slots = slotsForDay(rules, key, capacity, ranges, blocks).filter(
        (slot) => slot.state !== 'past',
    );

    if (slots.length === 0) {
        return 1;
    }

    const unavailable = slots.filter(
        (slot) => slot.state === 'full' || slot.state === 'blocked',
    ).length;

    if (unavailable === slots.length) {
        return 1;
    }

    if (slots.some((slot) => slot.occupied > 0) || unavailable > 0) {
        return Math.max(0.01, unavailable / slots.length);
    }

    return 0;
}
