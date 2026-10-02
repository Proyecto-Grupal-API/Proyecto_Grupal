<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { computed, ref } from 'vue';

type SpaceType =
    | 'capsule'
    | 'chair'
    | 'silent';

type SpaceStatus =
    | 'available'
    | 'occupied'
    | 'maintenance';

type BookingStatus =
    | 'confirmed'
    | 'cancelled'
    | 'completed';

interface RestSpace {
    id: number;
    code: string;
    name: string;
    type: SpaceType;
    location: string;
    capacity: number;
    maxMinutes: number;
    status: SpaceStatus;
    description: string;
}

interface Booking {
    id: number;
    folio: string;
    spaceId: number;
    spaceName: string;
    date: string;
    startTime: string;
    endTime: string;
    status: BookingStatus;
}

const spaces = ref<RestSpace[]>([
    {
        id: 1,
        code: 'ZD-CAP-001',
        name: 'Cápsula de descanso 1',
        type: 'capsule',
        location: 'Biblioteca · Planta baja',
        capacity: 1,
        maxMinutes: 60,
        status: 'available',
        description:
            'Cápsula individual para descanso breve y recuperación.',
    },
    {
        id: 2,
        code: 'ZD-CAP-002',
        name: 'Cápsula de descanso 2',
        type: 'capsule',
        location: 'Biblioteca · Planta baja',
        capacity: 1,
        maxMinutes: 60,
        status: 'occupied',
        description:
            'Cápsula individual con espacio de descanso.',
    },
    {
        id: 3,
        code: 'ZD-SIL-001',
        name: 'Espacio silencioso 1',
        type: 'silent',
        location: 'Edificio A · Piso 2',
        capacity: 1,
        maxMinutes: 120,
        status: 'available',
        description:
            'Área individual destinada al descanso o concentración.',
    },
    {
        id: 4,
        code: 'ZD-SIL-002',
        name: 'Espacio silencioso 2',
        type: 'silent',
        location: 'Edificio A · Piso 2',
        capacity: 1,
        maxMinutes: 120,
        status: 'available',
        description:
            'Zona silenciosa con iluminación tenue.',
    },
    {
        id: 5,
        code: 'ZD-SIL-003',
        name: 'Espacio silencioso 3',
        type: 'silent',
        location: 'Edificio B · Piso 1',
        capacity: 2,
        maxMinutes: 90,
        status: 'maintenance',
        description:
            'Espacio de descanso compartido para máximo dos personas.',
    },
    {
        id: 6,
        code: 'ZD-SIL-004',
        name: 'Espacio silencioso 4',
        type: 'silent',
        location: 'Edificio B · Piso 1',
        capacity: 1,
        maxMinutes: 90,
        status: 'available',
        description:
            'Área tranquila alejada de zonas de tránsito.',
    },
    {
        id: 7,
        code: 'ZD-SIL-005',
        name: 'Espacio silencioso 5',
        type: 'silent',
        location: 'Biblioteca · Piso 1',
        capacity: 1,
        maxMinutes: 120,
        status: 'available',
        description:
            'Área silenciosa cercana a la zona de lectura.',
    },
    {
        id: 8,
        code: 'ZD-CHR-001',
        name: 'Sillón de descanso 1',
        type: 'chair',
        location: 'Centro estudiantil',
        capacity: 1,
        maxMinutes: 45,
        status: 'available',
        description:
            'Sillón individual para descansos cortos.',
    },
    {
        id: 9,
        code: 'ZD-CHR-002',
        name: 'Sillón de descanso 2',
        type: 'chair',
        location: 'Centro estudiantil',
        capacity: 1,
        maxMinutes: 45,
        status: 'available',
        description:
            'Sillón individual en zona de descanso.',
    },
]);

const bookings = ref<Booking[]>([
    {
        id: 1,
        folio: 'ZD-2026-001',
        spaceId: 3,
        spaceName: 'Espacio silencioso 1',
        date: '2026-09-30',
        startTime: '11:00',
        endTime: '12:00',
        status: 'confirmed',
    },
    {
        id: 2,
        folio: 'ZD-2026-002',
        spaceId: 8,
        spaceName: 'Sillón de descanso 1',
        date: '2026-09-26',
        startTime: '13:00',
        endTime: '13:45',
        status: 'completed',
    },
]);

const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');

const showBookingModal = ref(false);
const selectedSpace = ref<RestSpace | null>(null);

const bookingDate = ref('');
const bookingStart = ref('');
const bookingDuration = ref(30);

const availableCount = computed(() =>
    spaces.value.filter(
        (space) =>
            space.status ===
            'available',
    ).length,
);

const occupiedCount = computed(() =>
    spaces.value.filter(
        (space) =>
            space.status ===
            'occupied',
    ).length,
);

const maintenanceCount = computed(() =>
    spaces.value.filter(
        (space) =>
            space.status ===
            'maintenance',
    ).length,
);

const activeBookings = computed(() =>
    bookings.value.filter(
        (booking) =>
            booking.status ===
            'confirmed',
    ),
);

const filteredSpaces = computed(() => {
    const term =
        search.value
            .trim()
            .toLowerCase();

    return spaces.value.filter(
        (space) => {
            if (
                typeFilter.value &&
                space.type !==
                typeFilter.value
            ) {
                return false;
            }

            if (
                statusFilter.value &&
                space.status !==
                statusFilter.value
            ) {
                return false;
            }

            if (!term) {
                return true;
            }

            return [
                space.code,
                space.name,
                space.location,
                space.description,
            ].some((value) =>
                value
                    .toLowerCase()
                    .includes(term),
            );
        },
    );
});

const calculatedEndTime =
    computed(() => {
        if (!bookingStart.value) {
            return '';
        }

        const [hours, minutes] =
            bookingStart.value
                .split(':')
                .map(Number);

        if (
            Number.isNaN(hours) ||
            Number.isNaN(minutes)
        ) {
            return '';
        }

        const date = new Date();

        date.setHours(
            hours,
            minutes +
            bookingDuration.value,
            0,
            0,
        );

        return date
            .toTimeString()
            .slice(0, 5);
    });

function typeLabel(
    type: SpaceType,
): string {
    const labels: Record<
        SpaceType,
        string
    > = {
        capsule: 'Cápsula',
        chair: 'Sillón',
        silent: 'Espacio silencioso',
    };

    return labels[type];
}

function statusLabel(
    status: SpaceStatus,
): string {
    const labels: Record<
        SpaceStatus,
        string
    > = {
        available: 'Disponible',
        occupied: 'Ocupado',
        maintenance:
            'Mantenimiento',
    };

    return labels[status];
}

function bookingStatusLabel(
    status: BookingStatus,
): string {
    const labels: Record<
        BookingStatus,
        string
    > = {
        confirmed: 'Confirmada',
        cancelled: 'Cancelada',
        completed: 'Completada',
    };

    return labels[status];
}

function formatDate(
    value: string,
): string {
    if (!value) {
        return '—';
    }

    const parts =
        value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function openBooking(
    space: RestSpace,
) {
    if (
        space.status !==
        'available'
    ) {
        return;
    }

    selectedSpace.value = space;

    bookingDate.value = '';
    bookingStart.value = '';
    bookingDuration.value =
        Math.min(
            30,
            space.maxMinutes,
        );

    showBookingModal.value =
        true;
}

function closeBooking() {
    showBookingModal.value =
        false;

    selectedSpace.value = null;
    bookingDate.value = '';
    bookingStart.value = '';
}

function createBooking() {
    if (
        selectedSpace.value === null
    ) {
        return;
    }

    if (
        !bookingDate.value ||
        !bookingStart.value
    ) {
        window.alert(
            'Selecciona fecha y horario.',
        );

        return;
    }

    if (
        bookingDuration.value >
        selectedSpace.value
            .maxMinutes
    ) {
        window.alert(
            `Este espacio permite máximo ${selectedSpace.value.maxMinutes} minutos.`,
        );

        return;
    }

    const newId =
        bookings.value.length + 1;

    bookings.value.unshift({
        id: newId,

        folio:
            'ZD-2026-' +
            String(newId + 2).padStart(
                3,
                '0',
            ),

        spaceId:
        selectedSpace.value.id,

        spaceName:
        selectedSpace.value.name,

        date: bookingDate.value,

        startTime:
        bookingStart.value,

        endTime:
        calculatedEndTime.value,

        status: 'confirmed',
    });

    closeBooking();
}

function cancelBooking(
    booking: Booking,
) {
    const confirmed =
        window.confirm(
            `¿Cancelar la reservación ${booking.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    booking.status =
        'cancelled';
}
</script>

<template>
    <StudentServicesLayout
        title="Zonas de descanso"
        subtitle="Reservación de espacios de descanso y zonas silenciosas"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    SERVICIOS · MÓDULO 5.6
                </span>

                <h2>
                    Zonas de descanso
                </h2>

                <p>
                    Consulta cápsulas,
                    sillones y espacios
                    silenciosos disponibles
                    dentro del campus y
                    reserva un horario de
                    uso.
                </p>
            </div>

            <div class="hero-total">
                <span>
                    Espacios registrados
                </span>

                <strong>
                    {{ spaces.length }}
                </strong>

                <small>
                    recursos de descanso
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Disponibles
                </span>

                <strong>
                    {{ availableCount }}
                </strong>

                <small>
                    Listos para reservar
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Ocupados
                </span>

                <strong>
                    {{ occupiedCount }}
                </strong>

                <small>
                    Actualmente en uso
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Mantenimiento
                </span>

                <strong>
                    {{ maintenanceCount }}
                </strong>

                <small>
                    Fuera de servicio
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Mis reservaciones
                </span>

                <strong>
                    {{
                        activeBookings.length
                    }}
                </strong>

                <small>
                    Reservas activas
                </small>
            </article>
        </section>

        <section class="rules-panel">
            <div class="rule-number">
                01
            </div>

            <div>
                <strong>
                    Reglas generales de uso
                </strong>

                <p>
                    Cada espacio tiene un
                    tiempo máximo de uso.
                    Las reservaciones deben
                    respetar el horario
                    seleccionado y pueden
                    cancelarse antes de su
                    inicio.
                </p>
            </div>

            <div class="rule-number">
                02
            </div>

            <div>
                <strong>
                    Uso responsable
                </strong>

                <p>
                    Mantén el área limpia,
                    respeta el silencio
                    cuando corresponda y
                    libera el espacio al
                    finalizar tu tiempo.
                </p>
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        DISPONIBILIDAD
                    </span>

                    <h3>
                        Espacios de descanso
                    </h3>

                    <p>
                        Selecciona un espacio
                        disponible para
                        consultar sus datos y
                        reservarlo.
                    </p>
                </div>
            </div>

            <div class="filters">
                <div class="search-field">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por nombre, código o ubicación..."
                    />
                </div>

                <select
                    v-model="typeFilter"
                >
                    <option value="">
                        Todos los tipos
                    </option>

                    <option value="capsule">
                        Cápsulas
                    </option>

                    <option value="chair">
                        Sillones
                    </option>

                    <option value="silent">
                        Espacios silenciosos
                    </option>
                </select>

                <select
                    v-model="
                        statusFilter
                    "
                >
                    <option value="">
                        Todos los estados
                    </option>

                    <option value="available">
                        Disponibles
                    </option>

                    <option value="occupied">
                        Ocupados
                    </option>

                    <option
                        value="maintenance"
                    >
                        Mantenimiento
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredSpaces.length >
                    0
                "
                class="space-grid"
            >
                <article
                    v-for="
                        space in
                        filteredSpaces
                    "
                    :key="space.id"
                    class="space-card"
                >
                    <div class="space-top">
                        <span
                            class="space-type"
                        >
                            {{
                                typeLabel(
                                    space.type,
                                )
                            }}
                        </span>

                        <span
                            class="status"
                            :class="`status-${space.status}`"
                        >
                            {{
                                statusLabel(
                                    space.status,
                                )
                            }}
                        </span>
                    </div>

                    <div
                        class="space-icon"
                        :class="space.type"
                    >
                        <span
                            v-if="
                                space.type ===
                                'capsule'
                            "
                        >
                            Z
                        </span>

                        <span
                            v-else-if="
                                space.type ===
                                'chair'
                            "
                        >
                            S
                        </span>

                        <span v-else>
                            Q
                        </span>
                    </div>

                    <h4>
                        {{ space.name }}
                    </h4>

                    <span
                        class="space-code"
                    >
                        {{ space.code }}
                    </span>

                    <p>
                        {{
                            space.description
                        }}
                    </p>

                    <div class="space-details">
                        <div>
                            <span>
                                Ubicación
                            </span>

                            <strong>
                                {{
                                    space.location
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Capacidad
                            </span>

                            <strong>
                                {{
                                    space.capacity
                                }}
                                {{
                                    space.capacity ===
                                    1
                                        ? 'persona'
                                        : 'personas'
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Tiempo máximo
                            </span>

                            <strong>
                                {{
                                    space.maxMinutes
                                }}
                                min
                            </strong>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="reserve-button"
                        :disabled="
                            space.status !==
                            'available'
                        "
                        @click="
                            openBooking(
                                space,
                            )
                        "
                    >
                        {{
                            space.status ===
                            'available'
                                ? 'Reservar espacio'
                                : 'No disponible'
                        }}
                    </button>
                </article>
            </div>

            <div
                v-else
                class="empty-state"
            >
                <h3>
                    No se encontraron
                    espacios
                </h3>

                <p>
                    Ajusta los filtros de
                    búsqueda.
                </p>
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        MIS RESERVACIONES
                    </span>

                    <h3>
                        Historial de uso
                    </h3>

                    <p>
                        Consulta tus
                        reservaciones actuales
                        y anteriores.
                    </p>
                </div>
            </div>

            <div
                v-if="
                    bookings.length > 0
                "
                class="table-container"
            >
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
                    <tr
                        v-for="
                                booking in
                                bookings
                            "
                        :key="
                                booking.id
                            "
                    >
                        <td>
                            <strong
                                class="folio"
                            >
                                {{
                                    booking.folio
                                }}
                            </strong>
                        </td>

                        <td>
                            {{
                                booking.spaceName
                            }}
                        </td>

                        <td>
                            {{
                                formatDate(
                                    booking.date,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                booking.startTime
                            }}
                            -
                            {{
                                booking.endTime
                            }}
                        </td>

                        <td>
                                <span
                                    class="booking-status"
                                    :class="`booking-${booking.status}`"
                                >
                                    {{
                                        bookingStatusLabel(
                                            booking.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <button
                                v-if="
                                        booking.status ===
                                        'confirmed'
                                    "
                                type="button"
                                class="cancel-button"
                                @click="
                                        cancelBooking(
                                            booking,
                                        )
                                    "
                            >
                                Cancelar
                            </button>

                            <span
                                v-else
                                class="no-action"
                            >
                                    —
                                </span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-if="
                showBookingModal &&
                selectedSpace
            "
            class="modal-backdrop"
            @click.self="
                closeBooking
            "
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span
                            class="panel-label"
                        >
                            NUEVA RESERVACIÓN
                        </span>

                        <h3>
                            {{
                                selectedSpace.name
                            }}
                        </h3>

                        <p>
                            {{
                                selectedSpace.location
                            }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeBooking
                        "
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>
                                Fecha
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    bookingDate
                                "
                                type="date"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Hora de inicio
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    bookingStart
                                "
                                type="time"
                                min="07:30"
                                max="18:00"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Duración
                            </label>

                            <select
                                v-model.number="
                                    bookingDuration
                                "
                            >
                                <option
                                    :value="30"
                                >
                                    30 minutos
                                </option>

                                <option
                                    v-if="
                                        selectedSpace.maxMinutes >=
                                        45
                                    "
                                    :value="45"
                                >
                                    45 minutos
                                </option>

                                <option
                                    v-if="
                                        selectedSpace.maxMinutes >=
                                        60
                                    "
                                    :value="60"
                                >
                                    60 minutos
                                </option>

                                <option
                                    v-if="
                                        selectedSpace.maxMinutes >=
                                        90
                                    "
                                    :value="90"
                                >
                                    90 minutos
                                </option>

                                <option
                                    v-if="
                                        selectedSpace.maxMinutes >=
                                        120
                                    "
                                    :value="120"
                                >
                                    120 minutos
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>
                                Hora de término
                            </label>

                            <input
                                :value="
                                    calculatedEndTime
                                "
                                type="text"
                                readonly
                                placeholder="--:--"
                            />
                        </div>
                    </div>

                    <div class="booking-summary">
                        <span>
                            Tiempo máximo de
                            este espacio
                        </span>

                        <strong>
                            {{
                                selectedSpace.maxMinutes
                            }}
                            minutos
                        </strong>
                    </div>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeBooking
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                createBooking
                            "
                        >
                            Confirmar reservación
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
    background:
        rgba(255, 255, 255, 0.1);
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
    grid-template-columns:
        repeat(4, 1fr);
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
    grid-template-columns:
        auto 1fr auto 1fr;
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
    border-bottom:
        1px solid #e5e9ef;
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
    border-bottom:
        1px solid #e5e9ef;
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
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(240px, 1fr)
        );
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
    border-top:
        1px solid #e9edf3;
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
    border-top:
        1px solid #e9edf3;
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
    background:
        rgba(18, 29, 47, 0.5);
}

.modal {
    width: min(560px, 100%);
    overflow: hidden;
    border-radius: 11px;
    background: white;
    box-shadow:
        0 18px 50px
        rgba(0, 0, 0, 0.18);
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
    grid-template-columns:
        repeat(2, 1fr);
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
    border-top:
        1px solid #e5e9ef;
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
        grid-template-columns:
            repeat(2, 1fr);
    }

    .rules-panel {
        grid-template-columns:
            auto 1fr;
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
        flex-direction:
            column-reverse;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
