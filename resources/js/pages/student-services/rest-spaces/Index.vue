<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import type {
    BookedRange,
    CalendarBlockRange,
    CalendarRules,
} from '@/lib/studentServicesCalendar';
import {
    addDays,
    blockFor,
    durationOptions,
    formatTimeOfIso,
    isOperatingDay,
    isWithinAdvance,
    localDate,
    minutesLabel,
    minutesToTime,
    peakOccupancy,
    slotStarts,
    timeToMinutes,
    todayKey,
    WEEKDAY_LABELS,
} from '@/lib/studentServicesCalendar';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type SpaceType = 'capsule' | 'chair' | 'silent';

type SpaceStatus = 'available' | 'occupied' | 'maintenance';

type BookingStatus =
    | 'confirmed'
    | 'waitlisted'
    | 'checked_in'
    | 'completed'
    | 'cancelled'
    | 'no_show'
    | 'expired';

interface RestSpace {
    id: string;
    code: string;
    name: string;
    type: SpaceType;
    location: string;
    capacity: number;
    description: string;
    status: SpaceStatus;
    rules: CalendarRules;
}

interface Booking {
    id: string;
    folio: string;
    space_id: string;
    space_name: string;
    start_at: string;
    end_at: string;
    status: BookingStatus;
    waitlist_position: number;
    can_cancel: boolean;
    checked_in_at: string | null;
    cancellation_reason: string | null;
}

const props = defineProps<{
    spaces: RestSpace[];
    bookings: Booking[];
    bookedRanges: Record<string, BookedRange[]>;
    blocks: Record<string, CalendarBlockRange[]>;
    spaceTypes: Record<SpaceType, string>;
    statusLabels: Record<string, string>;
}>();

const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const notice = ref<string | null>(null);

const availableCount = computed(
    () => props.spaces.filter((space) => space.status === 'available').length,
);

const occupiedCount = computed(
    () => props.spaces.filter((space) => space.status === 'occupied').length,
);

const maintenanceCount = computed(
    () => props.spaces.filter((space) => space.status === 'maintenance').length,
);

const activeBookings = computed(() =>
    props.bookings.filter((booking) =>
        ['confirmed', 'waitlisted', 'checked_in'].includes(booking.status),
    ),
);

const filteredSpaces = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.spaces.filter((space) => {
        if (typeFilter.value && space.type !== typeFilter.value) {
            return false;
        }

        if (statusFilter.value && space.status !== statusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [space.code, space.name, space.location, space.description].some(
            (value) => value.toLowerCase().includes(term),
        );
    });
});

function typeLabel(type: SpaceType): string {
    return props.spaceTypes[type] ?? type;
}

function statusLabel(status: SpaceStatus): string {
    const labels: Record<SpaceStatus, string> = {
        available: 'Disponible',
        occupied: 'Ocupado',
        maintenance: 'Mantenimiento',
    };

    return labels[status];
}

function bookingStatusLabel(status: BookingStatus): string {
    return props.statusLabels[status] ?? status;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function daysLabel(days: number[]): string {
    return days.map((day) => WEEKDAY_LABELS[day]).join(', ');
}

/*
|--------------------------------------------------------------------------
| Nueva reservación (franjas y límites vienen del motor 5.10)
|--------------------------------------------------------------------------
*/
const selectedSpace = ref<RestSpace | null>(null);

const bookingForm = useForm({
    rest_space_id: '',
    date: '',
    start_time: '',
    duration_minutes: 0,
    idempotency_key: '',
});

const durations = computed(() =>
    selectedSpace.value ? durationOptions(selectedSpace.value.rules) : [],
);

const startOptions = computed(() => {
    if (!selectedSpace.value || !bookingForm.date) {
        return [];
    }

    const rules = selectedSpace.value.rules;
    const close = timeToMinutes(rules.close_time);
    const now = Date.now();

    return slotStarts(rules).filter((start) => {
        const startMinutes = timeToMinutes(start);

        return (
            startMinutes + bookingForm.duration_minutes <= close &&
            localDate(bookingForm.date, start).getTime() +
                rules.slot_minutes * 60000 >
                now
        );
    });
});

const calculatedEndTime = computed(() => {
    if (!bookingForm.start_time || !bookingForm.duration_minutes) {
        return '';
    }

    return minutesToTime(
        timeToMinutes(bookingForm.start_time) + bookingForm.duration_minutes,
    );
});

const dayProblem = computed(() => {
    if (!selectedSpace.value || !bookingForm.date) {
        return '';
    }

    const rules = selectedSpace.value.rules;

    if (!isOperatingDay(rules, bookingForm.date)) {
        return `Este espacio no opera ese día (opera: ${daysLabel(rules.operating_days)}).`;
    }

    if (!isWithinAdvance(rules, bookingForm.date)) {
        return `Solo se puede reservar de hoy a ${rules.max_advance_days} día(s) adelante.`;
    }

    return '';
});

const selectedRange = computed(() => {
    if (
        !bookingForm.date ||
        !bookingForm.start_time ||
        !calculatedEndTime.value
    ) {
        return null;
    }

    return {
        start: localDate(bookingForm.date, bookingForm.start_time),
        end: localDate(bookingForm.date, calculatedEndTime.value),
    };
});

const selectedBlock = computed(() => {
    if (!selectedSpace.value || !selectedRange.value) {
        return null;
    }

    return blockFor(
        props.blocks[selectedSpace.value.id] ?? [],
        selectedRange.value.start,
        selectedRange.value.end,
    );
});

const selectedOccupied = computed(() => {
    if (!selectedSpace.value || !selectedRange.value) {
        return 0;
    }

    return peakOccupancy(
        props.bookedRanges[selectedSpace.value.id] ?? [],
        selectedRange.value.start,
        selectedRange.value.end,
    );
});

const willBeConfirmed = computed(() => {
    if (!selectedSpace.value || !selectedRange.value) {
        return null;
    }

    return selectedOccupied.value < selectedSpace.value.capacity;
});

const bookingError = computed(() => {
    const errors = bookingForm.errors as Record<string, string>;

    return errors.booking ?? Object.values(errors)[0] ?? '';
});

function openBooking(space: RestSpace) {
    if (space.status === 'maintenance') {
        return;
    }

    selectedSpace.value = space;
    notice.value = null;

    bookingForm.reset();
    bookingForm.clearErrors();
    bookingForm.rest_space_id = space.id;
    bookingForm.date = todayKey();
    bookingForm.duration_minutes =
        durationOptions(space.rules)[0] ?? space.rules.slot_minutes;
}

function closeBooking() {
    selectedSpace.value = null;
    bookingForm.reset();
    bookingForm.clearErrors();
}

function createBooking() {
    bookingForm.idempotency_key = crypto.randomUUID();

    const waiting = willBeConfirmed.value === false;

    bookingForm.post('/servicios-estudiante/zonas-descanso/reservas', {
        preserveScroll: true,
        onSuccess: () => {
            notice.value = waiting
                ? 'Sin cupo por ahora: quedaste en lista de espera y se confirmará sola si se libera el espacio.'
                : 'Reservación confirmada. Presenta tu credencial o el folio al llegar.';
            closeBooking();
        },
    });
}

function cancelBooking(booking: Booking) {
    if (!window.confirm(`¿Cancelar la reservación ${booking.folio}?`)) {
        return;
    }

    router.patch(
        `/servicios-estudiante/zonas-descanso/reservas/${booking.id}/cancelar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                notice.value = `Reservación ${booking.folio} cancelada.`;
            },
            onError: (errors) => {
                window.alert(
                    errors.booking ?? 'No fue posible cancelar la reservación.',
                );
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Administración del catálogo
|--------------------------------------------------------------------------
*/
const showSpaceForm = ref(false);

const spaceForm = useForm({
    code: '',
    name: '',
    type: 'silent' as SpaceType,
    location: '',
    capacity: 1,
    description: '',
});

function openSpaceForm() {
    spaceForm.reset();
    spaceForm.clearErrors();
    showSpaceForm.value = true;
}

function createSpace() {
    spaceForm.post('/servicios-estudiante/zonas-descanso/espacios', {
        preserveScroll: true,
        onSuccess: () => {
            notice.value = `Espacio ${spaceForm.code.toUpperCase()} registrado. Sus reglas se pueden ajustar en Calendarios (5.10).`;
            showSpaceForm.value = false;
            spaceForm.reset();
        },
    });
}

const spaceFormError = computed(() => {
    const errors = spaceForm.errors as Record<string, string>;

    return Object.values(errors)[0] ?? '';
});

function toggleMaintenance(space: RestSpace) {
    const toMaintenance = space.status !== 'maintenance';

    if (
        toMaintenance &&
        !window.confirm(
            `¿Poner ${space.name} en mantenimiento? Se cancelarán sus reservaciones próximas.`,
        )
    ) {
        return;
    }

    router.patch(
        `/servicios-estudiante/zonas-descanso/espacios/${space.id}/${toMaintenance ? 'mantenimiento' : 'disponible'}`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                notice.value = toMaintenance
                    ? `${space.name} quedó en mantenimiento.`
                    : `${space.name} está disponible de nuevo.`;
            },
            onError: (errors) => {
                window.alert(
                    errors.space ?? 'No fue posible actualizar el espacio.',
                );
            },
        },
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Zonas de descanso"
        subtitle="Reservación de espacios de descanso y zonas silenciosas"
    >
        <section class="hero">
            <div>
                <span class="hero-label">SERVICIOS · MÓDULO 5.6</span>

                <h2>Zonas de descanso</h2>

                <p>
                    Consulta cápsulas, sillones y espacios silenciosos
                    disponibles dentro del campus y reserva un horario de uso.
                    Las franjas, límites y lista de espera los administra el
                    motor de calendarios (5.10).
                </p>
            </div>

            <div class="hero-total">
                <span>Espacios registrados</span>
                <strong>{{ spaces.length }}</strong>
                <small>recursos de descanso</small>
            </div>
        </section>

        <div v-if="notice" class="page-notice">
            <span>{{ notice }}</span>
            <button type="button" @click="notice = null">×</button>
        </div>

        <section class="stats-grid">
            <article class="stat-card">
                <span>Disponibles</span>
                <strong>{{ availableCount }}</strong>
                <small>Con cupo en este momento</small>
            </article>

            <article class="stat-card">
                <span>Ocupados</span>
                <strong>{{ occupiedCount }}</strong>
                <small>Actualmente en uso</small>
            </article>

            <article class="stat-card">
                <span>Mantenimiento</span>
                <strong>{{ maintenanceCount }}</strong>
                <small>Fuera de servicio</small>
            </article>

            <article class="stat-card">
                <span>Mis reservaciones</span>
                <strong>{{ activeBookings.length }}</strong>
                <small>Activas o en espera</small>
            </article>
        </section>

        <section class="rules-panel">
            <div class="rule-number">01</div>

            <div>
                <strong>Reglas generales de uso</strong>

                <p>
                    Cada espacio tiene un tiempo máximo de uso y solo puedes
                    tener una reservación activa a la vez. Puedes cancelar hasta
                    unos minutos antes del inicio; si no llegas dentro de la
                    tolerancia, la reservación cuenta como inasistencia.
                </p>
            </div>

            <div class="rule-number">02</div>

            <div>
                <strong>Uso responsable</strong>

                <p>
                    Registra tu entrada y salida con tu credencial en el punto
                    de validación (5.11), mantén el área limpia y respeta el
                    silencio cuando corresponda.
                </p>
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">DISPONIBILIDAD</span>

                    <h3>Espacios de descanso</h3>

                    <p>
                        Selecciona un espacio para consultar sus datos y
                        reservarlo.
                    </p>
                </div>

                <button
                    type="button"
                    class="secondary-button"
                    @click="
                        showSpaceForm
                            ? (showSpaceForm = false)
                            : openSpaceForm()
                    "
                >
                    {{ showSpaceForm ? 'Cerrar' : '+ Nuevo espacio' }}
                </button>
            </div>

            <form
                v-if="showSpaceForm"
                class="space-form"
                @submit.prevent="createSpace"
            >
                <div class="form-grid">
                    <div class="form-field">
                        <label>Código <span>*</span></label>
                        <input
                            v-model="spaceForm.code"
                            type="text"
                            placeholder="Ej. ZD-SIL-006"
                        />
                    </div>

                    <div class="form-field">
                        <label>Nombre <span>*</span></label>
                        <input v-model="spaceForm.name" type="text" />
                    </div>

                    <div class="form-field">
                        <label>Tipo</label>
                        <select v-model="spaceForm.type">
                            <option
                                v-for="(label, value) in spaceTypes"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label>Ubicación <span>*</span></label>
                        <input v-model="spaceForm.location" type="text" />
                    </div>

                    <div class="form-field">
                        <label>Capacidad</label>
                        <input
                            v-model.number="spaceForm.capacity"
                            type="number"
                            min="1"
                        />
                    </div>

                    <div class="form-field">
                        <label>Descripción</label>
                        <input v-model="spaceForm.description" type="text" />
                    </div>
                </div>

                <p v-if="spaceFormError" class="form-error">
                    {{ spaceFormError }}
                </p>

                <div class="modal-actions">
                    <button
                        type="submit"
                        class="primary-button"
                        :disabled="spaceForm.processing"
                    >
                        Registrar espacio
                    </button>
                </div>
            </form>

            <div class="filters">
                <div class="search-field">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por nombre, código o ubicación..."
                    />
                </div>

                <select v-model="typeFilter">
                    <option value="">Todos los tipos</option>
                    <option value="capsule">Cápsulas</option>
                    <option value="chair">Sillones</option>
                    <option value="silent">Espacios silenciosos</option>
                </select>

                <select v-model="statusFilter">
                    <option value="">Todos los estados</option>
                    <option value="available">Disponibles</option>
                    <option value="occupied">Ocupados</option>
                    <option value="maintenance">Mantenimiento</option>
                </select>
            </div>

            <div v-if="filteredSpaces.length > 0" class="space-grid">
                <article
                    v-for="space in filteredSpaces"
                    :key="space.id"
                    class="space-card"
                >
                    <div class="space-top">
                        <span class="space-type">
                            {{ typeLabel(space.type) }}
                        </span>

                        <span class="status" :class="`status-${space.status}`">
                            {{ statusLabel(space.status) }}
                        </span>
                    </div>

                    <div class="space-icon" :class="space.type">
                        <span v-if="space.type === 'capsule'">Z</span>
                        <span v-else-if="space.type === 'chair'">S</span>
                        <span v-else>Q</span>
                    </div>

                    <h4>{{ space.name }}</h4>

                    <span class="space-code">{{ space.code }}</span>

                    <p>{{ space.description }}</p>

                    <div class="space-details">
                        <div>
                            <span>Ubicación</span>
                            <strong>{{ space.location }}</strong>
                        </div>

                        <div>
                            <span>Capacidad</span>
                            <strong>
                                {{ space.capacity }}
                                {{
                                    space.capacity === 1
                                        ? 'persona'
                                        : 'personas'
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>Tiempo máximo</span>
                            <strong>
                                {{
                                    minutesLabel(
                                        space.rules.max_booking_minutes,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>Horario</span>
                            <strong>
                                {{ space.rules.open_time }} –
                                {{ space.rules.close_time }}
                            </strong>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="reserve-button"
                        :disabled="space.status === 'maintenance'"
                        @click="openBooking(space)"
                    >
                        {{
                            space.status === 'maintenance'
                                ? 'No disponible'
                                : space.status === 'occupied'
                                  ? 'Reservar otro horario'
                                  : 'Reservar espacio'
                        }}
                    </button>

                    <button
                        type="button"
                        class="admin-link"
                        @click="toggleMaintenance(space)"
                    >
                        {{
                            space.status === 'maintenance'
                                ? 'Habilitar espacio'
                                : 'Enviar a mantenimiento'
                        }}
                    </button>
                </article>
            </div>

            <div v-else class="empty-state">
                <h3>No se encontraron espacios</h3>
                <p>Ajusta los filtros de búsqueda.</p>
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">MIS RESERVACIONES</span>

                    <h3>Historial de uso</h3>

                    <p>Consulta tus reservaciones actuales y anteriores.</p>
                </div>
            </div>

            <div v-if="bookings.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Espacio</th>
                            <th>Fecha</th>
                            <th>Horario</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="booking in bookings" :key="booking.id">
                            <td>
                                <strong class="folio">{{
                                    booking.folio
                                }}</strong>
                            </td>

                            <td>{{ booking.space_name }}</td>

                            <td>{{ formatDate(booking.start_at) }}</td>

                            <td>
                                {{ formatTimeOfIso(booking.start_at) }} -
                                {{ formatTimeOfIso(booking.end_at) }}
                            </td>

                            <td>
                                <span
                                    class="booking-status"
                                    :class="`booking-${booking.status}`"
                                >
                                    {{ bookingStatusLabel(booking.status) }}
                                    <template v-if="booking.waitlist_position">
                                        · #{{ booking.waitlist_position }}
                                    </template>
                                </span>

                                <small
                                    v-if="booking.cancellation_reason"
                                    class="booking-note"
                                >
                                    {{ booking.cancellation_reason }}
                                </small>
                            </td>

                            <td>
                                <button
                                    v-if="booking.can_cancel"
                                    type="button"
                                    class="cancel-button"
                                    @click="cancelBooking(booking)"
                                >
                                    Cancelar
                                </button>

                                <span v-else class="no-action">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                <h3>Aún no tienes reservaciones</h3>
                <p>Elige un espacio disponible para comenzar.</p>
            </div>
        </section>

        <div
            v-if="selectedSpace"
            class="modal-backdrop"
            @click.self="closeBooking"
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">NUEVA RESERVACIÓN</span>

                        <h3>{{ selectedSpace.name }}</h3>

                        <p>{{ selectedSpace.location }}</p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeBooking"
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>Fecha <span>*</span></label>
                            <input
                                v-model="bookingForm.date"
                                type="date"
                                :min="todayKey()"
                                :max="
                                    addDays(
                                        todayKey(),
                                        selectedSpace.rules.max_advance_days,
                                    )
                                "
                                @change="bookingForm.start_time = ''"
                            />
                        </div>

                        <div class="form-field">
                            <label>Duración</label>
                            <select
                                v-model.number="bookingForm.duration_minutes"
                                @change="bookingForm.start_time = ''"
                            >
                                <option
                                    v-for="minutes in durations"
                                    :key="minutes"
                                    :value="minutes"
                                >
                                    {{ minutes }} minutos
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Hora de inicio <span>*</span></label>
                            <select
                                v-model="bookingForm.start_time"
                                :disabled="!!dayProblem"
                            >
                                <option value="" disabled>Elige hora</option>
                                <option
                                    v-for="start in startOptions"
                                    :key="start"
                                    :value="start"
                                >
                                    {{ start }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Hora de término</label>
                            <input
                                :value="calculatedEndTime"
                                type="text"
                                readonly
                                placeholder="--:--"
                            />
                        </div>
                    </div>

                    <p v-if="dayProblem" class="form-error">{{ dayProblem }}</p>

                    <p v-else-if="bookingError" class="form-error">
                        {{ bookingError }}
                    </p>

                    <p v-else-if="selectedBlock" class="form-error">
                        Horario bloqueado: {{ selectedBlock.reason }}.
                    </p>

                    <p
                        v-else-if="willBeConfirmed !== null"
                        class="form-hint"
                        :class="{ warn: willBeConfirmed === false }"
                    >
                        {{
                            willBeConfirmed
                                ? `✅ Hay cupo (${selectedOccupied}/${selectedSpace.capacity}) — quedará confirmada.`
                                : '⏳ Ocupado en ese horario: puedes unirte a la lista de espera.'
                        }}
                    </p>

                    <div class="booking-summary">
                        <span>Tiempo máximo · cancelación · tolerancia</span>

                        <strong>
                            {{
                                minutesLabel(
                                    selectedSpace.rules.max_booking_minutes,
                                )
                            }}
                            ·
                            {{
                                minutesLabel(
                                    selectedSpace.rules.cancel_before_minutes,
                                )
                            }}
                            antes ·
                            {{ selectedSpace.rules.no_show_tolerance_minutes }}
                            min
                        </strong>
                    </div>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="closeBooking"
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="
                                !bookingForm.start_time ||
                                !!dayProblem ||
                                !!selectedBlock ||
                                bookingForm.processing
                            "
                            @click="createBooking"
                        >
                            {{
                                willBeConfirmed === false
                                    ? 'Unirme a lista de espera'
                                    : 'Confirmar reservación'
                            }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </StudentServicesLayout>
</template>

<style scoped>
.hero {
    padding: 24px 27px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-radius: 12px;
    background: #2f5eb6;
    color: white;
}

.hero-label,
.panel-label {
    display: block;
    margin-bottom: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.hero-label {
    color: #b8cdf0;
}

.hero h2 {
    margin: 0;
    font-size: 23px;
}

.hero p {
    max-width: 650px;
    margin: 7px 0 0;
    color: #dce8fa;
    font-size: 12px;
    line-height: 1.6;
}

.hero-total {
    min-width: 175px;
    padding: 16px 19px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-total span {
    display: block;
    color: #d8e4f8;
    font-size: 9px;
}

.hero-total strong {
    display: block;
    margin-top: 4px;
    font-size: 28px;
}

.hero-total small {
    color: #bfd2ef;
    font-size: 8px;
}

.stats-grid {
    margin-top: 18px;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
}

.stat-card {
    padding: 17px 18px;
    border: 1px solid #dfe5ee;
    border-radius: 10px;
    background: white;
}

.stat-card span {
    color: #73839a;
    font-size: 10px;
    font-weight: 700;
}

.stat-card strong {
    display: block;
    margin-top: 6px;
    color: #25324a;
    font-size: 24px;
}

.stat-card small {
    display: block;
    margin-top: 4px;
    color: #97a3b5;
    font-size: 9px;
}

.rules-panel {
    margin-top: 18px;
    padding: 17px 19px;
    display: grid;
    grid-template-columns: auto 1fr auto 1fr;
    gap: 14px;
    align-items: flex-start;
    border: 1px solid #d9e3f0;
    border-radius: 10px;
    background: #f3f7fc;
}

.rule-number {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 7px;
    background: #dfeafb;
    color: #315fa6;
    font-size: 9px;
    font-weight: 900;
}

.rules-panel strong {
    color: #3f5068;
    font-size: 10px;
}

.rules-panel p {
    margin: 4px 0 0;
    color: #748399;
    font-size: 9px;
    line-height: 1.6;
}

.content-panel {
    margin-top: 18px;
    overflow: hidden;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.panel-header,
.modal-header {
    padding: 18px 21px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border-bottom: 1px solid #e5e9ef;
}

.panel-label {
    color: #315a9f;
}

.panel-header h3,
.modal-header h3 {
    margin: 0;
    color: #25324a;
    font-size: 16px;
}

.panel-header p,
.modal-header p {
    margin: 5px 0 0;
    color: #8794a7;
    font-size: 11px;
}

.filters {
    padding: 14px 21px;
    display: grid;
    grid-template-columns:
        minmax(230px, 2fr)
        190px
        190px;
    gap: 10px;
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.filters input,
.filters select {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 10px;
}

.space-grid {
    padding: 18px 21px 21px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 13px;
}

.space-card {
    padding: 17px;
    border: 1px solid #e0e6ee;
    border-radius: 10px;
    background: white;
}

.space-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.space-type {
    color: #73839a;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.status,
.booking-status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.status-available,
.booking-confirmed {
    background: #e4f6ec;
    color: #217a4e;
}

.status-occupied {
    background: #fff3d7;
    color: #946510;
}

.status-maintenance,
.booking-cancelled {
    background: #f4e7e8;
    color: #9c4c55;
}

.booking-completed {
    background: #e8f0fc;
    color: #315fa6;
}

.space-icon {
    width: 48px;
    height: 48px;
    margin-top: 15px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: #edf3fc;
    color: #315fa6;
    font-size: 16px;
    font-weight: 900;
}

.space-icon.capsule {
    background: #edf3fc;
}

.space-icon.chair {
    background: #f2eefc;
    color: #6a56a3;
}

.space-icon.silent {
    background: #edf7f2;
    color: #367a58;
}

.space-card h4 {
    margin: 13px 0 2px;
    color: #34435a;
    font-size: 13px;
}

.space-code {
    color: #8c99aa;
    font-size: 8px;
    font-weight: 700;
}

.space-card > p {
    min-height: 32px;
    margin: 10px 0 14px;
    color: #77869a;
    font-size: 9px;
    line-height: 1.5;
}

.space-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #e9edf3;
}

.space-details div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.space-details span {
    color: #8d99aa;
    font-size: 8px;
}

.space-details strong {
    color: #52637a;
    text-align: right;
    font-size: 8px;
}

.reserve-button {
    width: 100%;
    min-height: 36px;
    margin-top: 15px;
    border: 1px solid #2c63b7;
    border-radius: 7px;
    background: #2c63b7;
    color: white;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.reserve-button:disabled {
    border-color: #dfe4eb;
    background: #eef1f5;
    color: #9aa5b5;
    cursor: not-allowed;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    padding: 12px 14px;
    background: #f4f7fb;
    color: #6f7f96;
    text-align: left;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}

td {
    padding: 14px;
    border-top: 1px solid #e9edf3;
    color: #5c6980;
    font-size: 10px;
}

.folio {
    color: #285aa6;
}

.cancel-button {
    min-height: 29px;
    padding: 0 9px;
    border: 1px solid #e6c9cd;
    border-radius: 6px;
    background: #fbebed;
    color: #9d4650;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.no-action {
    color: #a0a9b6;
}

.empty-state {
    padding: 50px;
    text-align: center;
}

.empty-state h3 {
    margin: 0;
    color: #334056;
    font-size: 15px;
}

.empty-state p {
    margin: 6px 0 0;
    color: #8d99aa;
    font-size: 11px;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 20px;
    background: rgba(18, 29, 47, 0.5);
}

.modal {
    width: min(560px, 100%);
    overflow: hidden;
    border-radius: 11px;
    background: white;
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.18);
}

.close-button {
    width: 34px;
    height: 34px;
    border: 1px solid #d8e0ea;
    border-radius: 7px;
    background: white;
    color: #657389;
    font-size: 20px;
    cursor: pointer;
}

.modal-body {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.form-field label {
    display: block;
    margin-bottom: 6px;
    color: #45556c;
    font-size: 10px;
    font-weight: 700;
}

.form-field label span {
    color: #bc4545;
}

.form-field input,
.form-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 11px;
}

.form-field input[readonly] {
    background: #f3f5f8;
}

.booking-summary {
    margin-top: 17px;
    padding: 12px 13px;
    display: flex;
    justify-content: space-between;
    gap: 15px;
    border-radius: 7px;
    background: #eef4fc;
}

.booking-summary span {
    color: #73839a;
    font-size: 9px;
}

.booking-summary strong {
    color: #315a9f;
    font-size: 10px;
}

.modal-actions {
    margin-top: 20px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #e5e9ef;
}

.primary-button,
.secondary-button {
    min-height: 38px;
    padding: 0 15px;
    border-radius: 7px;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.primary-button {
    border: 1px solid #2c63b7;
    background: #2c63b7;
    color: white;
}

.secondary-button {
    border: 1px solid #d6dee9;
    background: white;
    color: #55667d;
}

@media (max-width: 950px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .rules-panel {
        grid-template-columns: auto 1fr;
    }

    .filters {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header,
    .modal-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-total {
        width: 100%;
        box-sizing: border-box;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .modal-actions {
        flex-direction: column-reverse;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<style scoped>
/* Estilos agregados al conectar el módulo 5.6 con el backend */
.page-notice {
    margin-top: 14px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-radius: 10px;
    background: #e9f6ef;
    color: #2f6d4c;
    font-size: 10px;
    font-weight: 700;
}

.page-notice button {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 14px;
    cursor: pointer;
}

.panel-header {
    gap: 14px;
}

.space-form {
    padding: 16px 21px;
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.form-error {
    margin: 10px 0 0;
    color: #9c4c55;
    font-size: 9px;
    font-weight: 700;
}

.form-hint {
    margin: 10px 0 0;
    color: #217a4e;
    font-size: 9px;
    font-weight: 700;
}

.form-hint.warn {
    color: #946510;
}

.admin-link {
    margin-top: 8px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #8794a7;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    text-decoration: underline;
    cursor: pointer;
}

.booking-waitlisted {
    background: #fff3d7;
    color: #946510;
}

.booking-checked_in {
    background: #e8f0fc;
    color: #315fa6;
}

.booking-no_show,
.booking-expired {
    background: #f1f3f6;
    color: #5b6778;
}

.booking-note {
    display: block;
    margin-top: 3px;
    color: #8794a7;
    font-size: 8px;
}
</style>
