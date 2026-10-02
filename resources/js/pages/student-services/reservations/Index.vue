<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Facility {
    id: string;
    name: string;
    type: string;
    building: string;
    capacity: number;
    cost_cents: number;
}

interface Reservation {
    id: string;
    folio: string;
    facility_id: string;
    facility_name: string;
    start_at: string;
    end_at: string;
    status: 'confirmed' | 'waitlisted' | 'cancelled';
}

const props = defineProps<{
    facilities: Facility[];
    myReservations: Reservation[];
    occupancyByFacility: Record<string, Record<string, number>>;
}>();

/*
|--------------------------------------------------------------------------
| Horario en lista: 7:30 a.m. a 6:30 p.m. cada 30 minutos (Modulo 5.10)
|--------------------------------------------------------------------------
*/
function generateTimeSlots(): string[] {
    const slots: string[] = [];
    let h = 7;
    let m = 30;

    while (h < 18 || (h === 18 && m <= 30)) {
        slots.push(
            `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`,
        );

        m += 30;

        if (m === 60) {
            m = 0;
            h += 1;
        }
    }

    return slots;
}

const TIME_SLOTS = generateTimeSlots();

function formatSlot(t: string): string {
    const [h, m] = t.split(':').map(Number);
    const period = h < 12 ? 'a.m.' : 'p.m.';
    const h12 = h % 12 === 0 ? 12 : h % 12;

    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
}

function formatCost(cents: number): string {
    if (cents === 0) {
        return 'Sin costo';
    }

    return `$${(cents / 100).toFixed(0)} MXN`;
}

function todayStr(): string {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/*
|--------------------------------------------------------------------------
| Vista: catálogo de instalaciones vs. calendario de disponibilidad
|--------------------------------------------------------------------------
*/
const currentView = ref<'instalaciones' | 'calendario'>('instalaciones');
const calendarFacility = ref<Facility | null>(null);
const viewYear = ref(new Date().getFullYear());
const viewMonth = ref(new Date().getMonth());
const selectedDay = ref<string | null>(null);

const search = ref('');

const filteredFacilities = computed(() => {
    const value = search.value.trim().toLowerCase();

    if (!value) {
        return props.facilities;
    }

    return props.facilities.filter(
        (f) =>
            f.name.toLowerCase().includes(value) ||
            f.type.toLowerCase().includes(value) ||
            f.building.toLowerCase().includes(value),
    );
});

const activeReservations = computed(
    () =>
        props.myReservations.filter((r) => r.status === 'confirmed').length,
);

const waitlistedReservations = computed(
    () =>
        props.myReservations.filter((r) => r.status === 'waitlisted').length,
);

function goToCalendarioTab() {
    currentView.value = 'calendario';
    calendarFacility.value = null;
    selectedDay.value = null;
}

function openFacilityCalendar(facility: Facility) {
    calendarFacility.value = facility;
    selectedDay.value = null;
}

function changeMonth(delta: number) {
    let m = viewMonth.value + delta;
    let y = viewYear.value;

    if (m < 0) {
        m = 11;
        y -= 1;
    }

    if (m > 11) {
        m = 0;
        y += 1;
    }

    viewMonth.value = m;
    viewYear.value = y;
    selectedDay.value = null;
}

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString(
        'es-MX',
        { month: 'long', year: 'numeric' },
    ),
);

function occupancyRatio(facility: Facility, dateStr: string): number {
    const byDate = props.occupancyByFacility[facility.id];
    const count = byDate?.[dateStr] ?? 0;

    if (count === 0) {
        return 0;
    }

    return count / facility.capacity;
}

function colorForRatio(ratio: number): string {
    if (ratio <= 0) return 'day-free';
    if (ratio < 1) return 'day-partial';

    return 'day-full';
}

interface CalendarCell {
    day: number;
    dateStr: string;
    colorClass: string;
}

const calendarCells = computed<(CalendarCell | null)[]>(() => {
    if (!calendarFacility.value) {
        return [];
    }

    const facility = calendarFacility.value;
    const firstOfMonth = new Date(viewYear.value, viewMonth.value, 1);
    const startOffset = firstOfMonth.getDay();
    const daysInMonth = new Date(
        viewYear.value,
        viewMonth.value + 1,
        0,
    ).getDate();

    const cells: (CalendarCell | null)[] = [];

    for (let i = 0; i < startOffset; i++) {
        cells.push(null);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const ratio = occupancyRatio(facility, dateStr);

        cells.push({
            day,
            dateStr,
            colorClass: colorForRatio(ratio),
        });
    }

    return cells;
});

const reservationsForSelectedDay = computed(() => {
    if (!selectedDay.value || !calendarFacility.value) {
        return [];
    }

    return props.myReservations.filter(
        (r) =>
            r.facility_id === calendarFacility.value?.id &&
            r.start_at.slice(0, 10) === selectedDay.value,
    );
});

/*
|--------------------------------------------------------------------------
| Modal de reserva (Modulo 5.5, usando el motor 5.10)
|--------------------------------------------------------------------------
*/
const modalFacility = ref<Facility | null>(null);

const form = useForm({
    facility_id: '',
    date: '',
    start_time: '',
    end_time: '',
    idempotency_key: '',
});

const endTimeOptions = computed(() =>
    form.start_time
        ? TIME_SLOTS.filter((t) => t > form.start_time)
        : TIME_SLOTS,
);

function openModal(facility: Facility) {
    modalFacility.value = facility;

    form.reset();
    form.clearErrors();

    form.facility_id = facility.id;
    form.date = todayStr();
}

function closeModal() {
    modalFacility.value = null;
    form.reset();
    form.clearErrors();
}

/*
 * Disponibilidad en vivo dentro del modal: usa occupancyByFacility, que
 * ya trae el conteo real de reservas CONFIRMADAS de todos los
 * estudiantes para esa instalación y ese día.
 */
const modalOccupied = computed(() => {
    if (!modalFacility.value || !form.date) {
        return 0;
    }

    const byDate = props.occupancyByFacility[modalFacility.value.id];

    return byDate?.[form.date] ?? 0;
});

const modalWillBeConfirmed = computed(() => {
    if (!modalFacility.value || !form.start_time || !form.end_time) {
        return null;
    }

    return modalOccupied.value < modalFacility.value.capacity;
});

const modalMessage = computed(() => {
    if (modalWillBeConfirmed.value === null || !modalFacility.value) {
        return '';
    }

    if (modalWillBeConfirmed.value) {
        return `✅ Hay cupo (${modalOccupied.value}/${modalFacility.value.capacity} ocupado ese día) — quedará confirmada.`;
    }

    return `⏳ Capacidad máxima ese día (${modalOccupied.value}/${modalFacility.value.capacity}).`;
});

function submitReservation() {
    form.idempotency_key = crypto.randomUUID();

    form.post('/servicios-estudiante/reservas', {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
        },
    });
}

function cancelReservation(reservation: Reservation) {
    router.patch(
        `/servicios-estudiante/reservas/${reservation.id}/cancelar`,
        {},
        { preserveScroll: true },
    );
}

function statusLabel(status: Reservation['status']): string {
    return (
        {
            confirmed: 'Confirmada',
            waitlisted: 'En espera',
            cancelled: 'Cancelada',
        } as Record<string, string>
    )[status];
}
</script>

<template>
    <StudentServicesLayout
        title="Reservas"
        subtitle="Consulta instalaciones y administra tus reservaciones"
    >
        <section class="reservation-hero">
            <div class="hero-information">
                <span class="section-label">
                    MÓDULOS 5.5 Y 5.10 · SERVICIOS AL ESTUDIANTE
                </span>

                <h2>Reserva tu espacio</h2>

                <p>
                    Consulta la disponibilidad de instalaciones, realiza
                    reservaciones y administra tus solicitudes desde un
                    solo lugar.
                </p>
            </div>

            <div class="hero-total">
                <span>Instalaciones</span>
                <strong>{{ facilities.length }}</strong>
                <small>registradas</small>
            </div>
        </section>

        <nav class="view-tabs">
            <button
                type="button"
                class="view-tab"
                :class="{ active: currentView === 'instalaciones' }"
                @click="currentView = 'instalaciones'"
            >
                Instalaciones
            </button>

            <button
                type="button"
                class="view-tab"
                :class="{ active: currentView === 'calendario' }"
                @click="goToCalendarioTab"
            >
                Calendarios (5.10)
            </button>
        </nav>

        <template v-if="currentView === 'instalaciones'">
            <section class="statistics-grid">
                <article class="stat-card">
                    <span>Reservas activas</span>
                    <strong>{{ activeReservations }}</strong>
                    <small>Confirmadas y vigentes</small>
                </article>

                <article class="stat-card">
                    <span>En lista de espera</span>
                    <strong>{{ waitlistedReservations }}</strong>
                    <small>Se promueven automáticamente</small>
                </article>

                <article class="stat-card">
                    <span>Catálogo</span>
                    <strong>{{ facilities.length }}</strong>
                    <small>Instalaciones registradas</small>
                </article>
            </section>

            <section class="reservation-content">
                <div class="catalog-panel">
                    <div class="panel-header">
                        <div>
                            <h3>Catálogo de instalaciones</h3>
                            <p>Motor de disponibilidad: módulo 5.10</p>
                        </div>

                        <div class="search-wrapper">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Buscar instalación..."
                            />
                        </div>
                    </div>

                    <div class="facility-list">
                        <article
                            v-for="facility in filteredFacilities"
                            :key="facility.id"
                            class="facility-card"
                        >
                            <div class="facility-information">
                                <div class="facility-title">
                                    <h4>{{ facility.name }}</h4>
                                </div>

                                <p>
                                    {{ facility.type }} ·
                                    {{ facility.building }} · Capacidad
                                    {{ facility.capacity }} ·
                                    {{ formatCost(facility.cost_cents) }}
                                </p>
                            </div>

                            <button
                                type="button"
                                class="reserve-button"
                                @click="openModal(facility)"
                            >
                                Reservar
                            </button>
                        </article>

                        <div
                            v-if="filteredFacilities.length === 0"
                            class="empty-catalog"
                        >
                            No se encontraron instalaciones con ese
                            criterio.
                        </div>
                    </div>
                </div>

                <aside class="my-reservations">
                    <div class="panel-header">
                        <div>
                            <h3>Mis reservas</h3>
                            <p>Confirmadas o en espera, no vencidas.</p>
                        </div>
                    </div>

                    <div
                        v-if="myReservations.length === 0"
                        class="empty-reservations"
                    >
                        <div class="empty-icon">◷</div>

                        <strong>Aún no tienes reservas</strong>

                        <span>
                            Selecciona una instalación del catálogo para
                            comenzar.
                        </span>
                    </div>

                    <div v-else class="reservation-list">
                        <article
                            v-for="reservation in myReservations"
                            :key="reservation.id"
                            class="reservation-card"
                        >
                            <div class="reservation-card-header">
                                <strong>
                                    {{ reservation.facility_name }}
                                </strong>

                                <span
                                    class="reservation-status"
                                    :class="{
                                        waiting:
                                            reservation.status ===
                                            'waitlisted',
                                    }"
                                >
                                    {{ statusLabel(reservation.status) }}
                                </span>
                            </div>

                            <div class="reservation-details">
                                <span>{{ reservation.folio }}</span>

                                <span>
                                    {{
                                        new Date(
                                            reservation.start_at,
                                        ).toLocaleString('es-MX', {
                                            dateStyle: 'short',
                                            timeStyle: 'short',
                                        })
                                    }}
                                </span>
                            </div>

                            <button
                                type="button"
                                class="cancel-button"
                                @click="cancelReservation(reservation)"
                            >
                                Cancelar
                            </button>
                        </article>
                    </div>
                </aside>
            </section>
        </template>

        <!-- Vista de calendario (Modulo 5.10) -->
        <template v-else>
            <section
                v-if="!calendarFacility"
                class="reservation-content single"
            >
                <div class="catalog-panel full-width">
                    <div class="panel-header">
                        <div>
                            <h3>
                                ¿De qué instalación quieres ver el
                                calendario?
                            </h3>

                            <p>
                                Cada instalación tiene su propio cupo —
                                elige una para ver su disponibilidad día
                                por día.
                            </p>
                        </div>
                    </div>

                    <div class="facility-grid">
                        <button
                            v-for="facility in facilities"
                            :key="facility.id"
                            type="button"
                            class="facility-pick-card"
                            @click="openFacilityCalendar(facility)"
                        >
                            <strong>{{ facility.name }}</strong>

                            <span>
                                {{ facility.type }} ·
                                {{ facility.building }} · capacidad
                                {{ facility.capacity }}
                            </span>

                            <small>Ver calendario →</small>
                        </button>
                    </div>
                </div>
            </section>

            <section v-else class="reservation-content single">
                <div class="catalog-panel full-width">
                    <div class="panel-header">
                        <div>
                            <button
                                type="button"
                                class="back-link"
                                @click="calendarFacility = null"
                            >
                                ← Todas las instalaciones
                            </button>

                            <h3>{{ calendarFacility.name }}</h3>

                            <p>
                                Capacidad:
                                {{ calendarFacility.capacity }}
                                reservas simultáneas.
                            </p>
                        </div>
                    </div>

                    <div class="calendar-nav">
                        <button
                            type="button"
                            @click="changeMonth(-1)"
                        >
                            ← Anterior
                        </button>

                        <strong>{{ monthLabel }}</strong>

                        <button
                            type="button"
                            @click="changeMonth(1)"
                        >
                            Siguiente →
                        </button>
                    </div>

                    <div class="calendar-weekdays">
                        <span
                            v-for="d in [
                                'Dom',
                                'Lun',
                                'Mar',
                                'Mié',
                                'Jue',
                                'Vie',
                                'Sáb',
                            ]"
                            :key="d"
                        >
                            {{ d }}
                        </span>
                    </div>

                    <div class="calendar-grid">
                        <div
                            v-for="(cell, idx) in calendarCells"
                            :key="idx"
                        >
                            <button
                                v-if="cell"
                                type="button"
                                class="calendar-day"
                                :class="[
                                    cell.colorClass,
                                    {
                                        selected:
                                            selectedDay ===
                                            cell.dateStr,
                                    },
                                ]"
                                @click="
                                    selectedDay =
                                        cell.dateStr
                                "
                            >
                                {{ cell.day }}
                            </button>
                        </div>
                    </div>

                    <div class="calendar-legend">
                        <span>
                            <i class="dot day-free"></i>
                            Sin reservas ese día
                        </span>

                        <span>
                            <i class="dot day-partial"></i>
                            Con reservas, aún hay cupo
                        </span>

                        <span>
                            <i class="dot day-full"></i>
                            Instalación llena
                        </span>
                    </div>

                    <div
                        v-if="selectedDay"
                        class="day-detail"
                    >
                        <h4>
                            Reservas del {{ selectedDay }}
                        </h4>

                        <p
                            v-if="
                                reservationsForSelectedDay.length ===
                                0
                            "
                            class="empty-catalog"
                        >
                            No tienes reservas tuyas ese día en esta
                            instalación.
                        </p>

                        <article
                            v-for="r in reservationsForSelectedDay"
                            :key="r.id"
                            class="reservation-card"
                        >
                            <div class="reservation-card-header">
                                <strong>{{ r.folio }}</strong>

                                <span
                                    class="reservation-status"
                                    :class="{
                                        waiting:
                                            r.status ===
                                            'waitlisted',
                                    }"
                                >
                                    {{ statusLabel(r.status) }}
                                </span>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </template>

        <!-- Modal de reserva -->
        <div
            v-if="modalFacility"
            class="modal-backdrop"
            @click.self="closeModal"
        >
            <div class="reservation-modal">
                <div class="modal-header">
                    <div>
                        <span class="section-label">
                            NUEVA RESERVA
                        </span>

                        <h3>{{ modalFacility.name }}</h3>

                        <p>
                            {{ modalFacility.type }} ·
                            {{ modalFacility.building }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeModal"
                    >
                        ×
                    </button>
                </div>

                <div class="form-grid">
                    <label class="form-field">
                        <span>Fecha</span>

                        <input
                            v-model="form.date"
                            type="date"
                            :min="todayStr()"
                        />
                    </label>

                    <label class="form-field">
                        <span>Hora inicio</span>

                        <select v-model="form.start_time">
                            <option value="" disabled>
                                Elige hora
                            </option>

                            <option
                                v-for="t in TIME_SLOTS"
                                :key="t"
                                :value="t"
                            >
                                {{ formatSlot(t) }}
                            </option>
                        </select>
                    </label>

                    <label class="form-field">
                        <span>Hora fin</span>

                        <select v-model="form.end_time">
                            <option value="" disabled>
                                Elige hora
                            </option>

                            <option
                                v-for="t in endTimeOptions"
                                :key="t"
                                :value="t"
                            >
                                {{ formatSlot(t) }}
                            </option>
                        </select>
                    </label>
                </div>

                <p class="helper-text">
                    Toda reserva inicia y termina el mismo día ·
                    horario 7:30 a.m. – 6:30 p.m.
                </p>

                <p
                    v-if="form.errors.facility_id"
                    class="form-error"
                >
                    {{ form.errors.facility_id }}
                </p>

                <p
                    v-else-if="form.errors.end_time"
                    class="form-error"
                >
                    {{ form.errors.end_time }}
                </p>

                <p
                    v-else-if="modalMessage"
                    class="form-hint"
                    :class="{
                        warn:
                            modalWillBeConfirmed === false,
                    }"
                >
                    {{ modalMessage }}
                </p>

                <div class="facility-summary">
                    <div>
                        <span>Capacidad</span>

                        <strong>
                            {{ modalFacility.capacity }} personas
                        </strong>
                    </div>

                    <div>
                        <span>Costo</span>

                        <strong>
                            {{
                                formatCost(
                                    modalFacility.cost_cents,
                                )
                            }}
                        </strong>
                    </div>
                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="secondary-button"
                        @click="closeModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="primary-button"
                        :disabled="
                            !form.date ||
                            !form.start_time ||
                            !form.end_time ||
                            modalWillBeConfirmed === false ||
                            form.processing
                        "
                        @click="submitReservation"
                    >
                        Confirmar reserva
                    </button>
                </div>
            </div>
        </div>
    </StudentServicesLayout>
</template>

<style scoped>
.reservation-hero {
    padding: 26px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-radius: 12px;
    background: #2d57ac;
    color: white;
}

.hero-information {
    max-width: 680px;
}

.section-label {
    display: block;
    margin-bottom: 7px;
    color: #8fb5f0;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.reservation-hero h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
}

.reservation-hero p {
    max-width: 620px;
    margin: 8px 0 0;
    color: #dbe7f8;
    font-size: 12px;
    line-height: 1.6;
}

.hero-total {
    min-width: 130px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-total span {
    font-size: 9px;
    color: #d8e5fa;
}

.hero-total strong {
    margin-top: 3px;
    font-size: 26px;
}

.hero-total small {
    margin-top: 2px;
    color: #bfd3f1;
    font-size: 8px;
}

.view-tabs {
    margin-top: 14px;
    display: flex;
    gap: 8px;
}

.view-tab {
    padding: 8px 14px;
    border: 1px solid #dfe5ee;
    border-radius: 8px;
    background: white;
    color: #5c697b;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.view-tab.active {
    background: #2d57ac;
    border-color: #2d57ac;
    color: white;
}

.statistics-grid {
    margin-top: 17px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.stat-card {
    min-height: 112px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.stat-card span {
    color: #66768c;
    font-size: 11px;
}

.stat-card strong {
    margin-top: 6px;
    color: #25324a;
    font-size: 24px;
}

.stat-card small {
    margin-top: 4px;
    color: #98a4b4;
    font-size: 9px;
}

.reservation-content {
    margin-top: 17px;
    display: grid;
    grid-template-columns:
        minmax(0, 1.8fr)
        minmax(290px, 0.8fr);
    gap: 15px;
    align-items: start;
}

.reservation-content.single {
    grid-template-columns: 1fr;
}

.catalog-panel,
.my-reservations {
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.catalog-panel {
    padding: 20px;
}

.catalog-panel.full-width {
    padding: 22px;
}

.my-reservations {
    padding: 20px;
    min-height: 350px;
}

.panel-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 16px;
}

.panel-header h3 {
    margin: 0;
    color: #2c394f;
    font-size: 15px;
    font-weight: 700;
}

.panel-header p {
    margin: 4px 0 0;
    color: #8b97a9;
    font-size: 10px;
}

.search-wrapper input {
    width: 210px;
    height: 36px;
    padding: 0 11px;
    border: 1px solid #dbe2ec;
    border-radius: 7px;
    outline: none;
    color: #34445b;
    font: inherit;
    font-size: 10px;
    background: white;
}

.facility-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.facility-card {
    padding: 15px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border: 1px solid #e2e7ef;
    border-radius: 9px;
}

.facility-information {
    min-width: 0;
    flex: 1;
}

.facility-title h4 {
    margin: 0;
    color: #28364d;
    font-size: 12px;
    font-weight: 700;
}

.facility-information p {
    margin: 5px 0 0;
    color: #8995a8;
    font-size: 9px;
}

.reserve-button {
    min-height: 34px;
    padding: 0 14px;
    flex-shrink: 0;
    border: 0;
    border-radius: 7px;
    background: #2d57ac;
    color: white;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.reserve-button:hover {
    background: #244a94;
}

.empty-catalog {
    padding: 35px 20px;
    text-align: center;
    color: #8c99aa;
    font-size: 10px;
}

.empty-reservations {
    min-height: 245px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.empty-icon {
    width: 42px;
    height: 42px;
    margin-bottom: 11px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #edf3fb;
    color: #315fa8;
}

.empty-reservations strong {
    color: #3a475b;
    font-size: 11px;
}

.empty-reservations span {
    max-width: 210px;
    margin-top: 5px;
    color: #97a2b2;
    font-size: 9px;
    line-height: 1.5;
}

.reservation-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.reservation-card {
    padding: 13px;
    border: 1px solid #e1e7ef;
    border-radius: 8px;
}

.reservation-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 7px;
}

.reservation-card-header strong {
    color: #334159;
    font-size: 10px;
}

.reservation-status {
    padding: 3px 6px;
    border-radius: 999px;
    background: #e9f6ef;
    color: #3d805c;
    font-size: 7px;
    font-weight: 800;
}

.reservation-status.waiting {
    background: #fff2dc;
    color: #9d6917;
}

.reservation-details {
    margin-top: 9px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    color: #8490a1;
    font-size: 8px;
}

.cancel-button {
    margin-top: 10px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #9d4850;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    padding: 20px;
    display: grid;
    place-items: center;
    background: rgba(20, 34, 56, 0.42);
}

.reservation-modal {
    width: min(520px, 100%);
    padding: 23px;
    border-radius: 12px;
    background: white;
    box-shadow:
        0 20px 55px
        rgba(26, 45, 78, 0.18);
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
}

.modal-header .section-label {
    color: #315a9f;
}

.modal-header h3 {
    margin: 0;
    color: #29374d;
    font-size: 17px;
}

.modal-header p {
    margin: 5px 0 0;
    color: #8b97a9;
    font-size: 9px;
}

.close-button {
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 7px;
    background: #f0f3f7;
    color: #5c697b;
    font-size: 18px;
    cursor: pointer;
}

.form-grid {
    margin-top: 20px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 13px;
}

.form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-field span {
    color: #506078;
    font-size: 9px;
    font-weight: 700;
}

.form-field input,
.form-field select {
    height: 38px;
    padding: 0 10px;
    border: 1px solid #dce3ec;
    border-radius: 7px;
    outline: none;
    color: #34445b;
    font: inherit;
    font-size: 10px;
    background: white;
}

.helper-text {
    margin-top: 8px;
    color: #97a2b2;
    font-size: 8px;
}

.form-error {
    margin-top: 8px;
    color: #b33c46;
    font-size: 9px;
}

.form-hint {
    margin-top: 8px;
    color: #2f8a5c;
    font-size: 9px;
}

.form-hint.warn {
    color: #a26b16;
}

.facility-summary {
    margin-top: 17px;
    padding: 13px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    border-radius: 8px;
    background: #f5f7fa;
}

.facility-summary div {
    display: flex;
    flex-direction: column;
}

.facility-summary span {
    color: #8996a7;
    font-size: 8px;
}

.facility-summary strong {
    margin-top: 3px;
    color: #39475d;
    font-size: 9px;
}

.modal-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
}

.secondary-button,
.primary-button {
    min-height: 36px;
    padding: 0 15px;
    border-radius: 7px;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.secondary-button {
    border: 1px solid #d8e0ea;
    background: white;
    color: #607087;
}

.primary-button {
    border: 0;
    background: #2d57ac;
    color: white;
}

.primary-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

/*
|--------------------------------------------------------------------------
| Calendario - Módulo 5.10
|--------------------------------------------------------------------------
*/

.facility-grid {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fill,
            minmax(200px, 1fr)
        );
    gap: 12px;
}

.facility-pick-card {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    text-align: left;
    border: 1px solid #e2e7ef;
    border-radius: 9px;
    background: white;
    cursor: pointer;
}

.facility-pick-card:hover {
    border-color: #9fb8de;
}

.facility-pick-card strong {
    color: #28364d;
    font-size: 12px;
}

.facility-pick-card span {
    color: #8995a8;
    font-size: 9px;
}

.facility-pick-card small {
    margin-top: 4px;
    color: #2d57ac;
    font-size: 9px;
    font-weight: 700;
}

.back-link {
    padding: 0;
    border: 0;
    background: transparent;
    color: #5c78ad;
    font: inherit;
    font-size: 9px;
    cursor: pointer;
}

.calendar-nav {
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.calendar-nav button {
    padding: 6px 10px;
    border: 0;
    border-radius: 7px;
    background: #f0f3f7;
    color: #5c697b;
    font: inherit;
    font-size: 9px;
    cursor: pointer;
}

.calendar-nav strong {
    color: #2c394f;
    font-size: 11px;
    text-transform: capitalize;
}

.calendar-weekdays {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
    color: #97a2b2;
    font-size: 9px;
    font-weight: 700;
}

.calendar-grid {
    margin-top: 6px;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
}

.calendar-day {
    width: 100%;
    aspect-ratio: 1;
    border: 2px solid transparent;
    border-radius: 8px;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.calendar-day.selected {
    border-color: #2c394f;
}

.day-free {
    background: #b9ecd2;
    color: #1f7a4c;
}

.day-partial {
    background: #ffe6a8;
    color: #8a5c10;
}

.day-full {
    background: #f7b8bf;
    color: #96222f;
}

.calendar-legend {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    color: #8490a1;
    font-size: 9px;
}

.calendar-legend .dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    margin-right: 4px;
    border-radius: 3px;
    vertical-align: middle;
}

.day-detail {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #e6ebf1;
}

.day-detail h4 {
    margin: 0 0 10px;
    color: #2c394f;
    font-size: 11px;
}

@media (max-width: 1000px) {
    .reservation-content {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 750px) {
    .statistics-grid {
        grid-template-columns: 1fr;
    }

    .reservation-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-total {
        width: 100%;
    }

    .panel-header {
        align-items: stretch;
        flex-direction: column;
    }

    .search-wrapper input {
        width: 100%;
    }
}

@media (max-width: 560px) {
    .facility-card {
        align-items: stretch;
        flex-direction: column;
    }

    .reserve-button {
        width: 100%;
    }

    .form-grid,
    .facility-summary {
        grid-template-columns: 1fr;
    }
}
</style>
