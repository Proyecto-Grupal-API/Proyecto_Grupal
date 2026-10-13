<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ReservationStatus =
    | 'pending'
    | 'ready'
    | 'fulfilled'
    | 'cancelled'
    | 'expired';

interface BookReservation {
    id: string;
    book_id: string;
    book_title: string;
    student_id: string;
    assigned_copy_id: string | null;
    assigned_copy_code: string | null;
    reserved_at: string;
    ready_at: string | null;
    expires_at: string | null;
    fulfilled_at: string | null;
    status: ReservationStatus;
    notes: string | null;
}

interface BookOption {
    id: string;
    title: string;
    isbn: string | null;
    available_copies: number;
}

const props = defineProps<{
    reservations: BookReservation[];
    books: BookOption[];
}>();

const search = ref('');

const statusFilter = ref<'all' | ReservationStatus>('all');

const showForm = ref(false);

const processingActionId = ref<string | null>(null);

const form = useForm({
    book_id: '',
    student_id: '',
    notes: '',
});

const selectedBook = computed(() => {
    return props.books.find((book) => book.id === form.book_id) ?? null;
});

const pendingCount = computed(() => {
    return props.reservations.filter(
        (reservation) => reservation.status === 'pending',
    ).length;
});

const readyCount = computed(() => {
    return props.reservations.filter(
        (reservation) => reservation.status === 'ready',
    ).length;
});

const fulfilledCount = computed(() => {
    return props.reservations.filter(
        (reservation) => reservation.status === 'fulfilled',
    ).length;
});

const closedCount = computed(() => {
    return props.reservations.filter(
        (reservation) =>
            reservation.status === 'cancelled' ||
            reservation.status === 'expired',
    ).length;
});

const reservationFolio = (reservation: BookReservation): string => {
    return `RES-${reservation.id.slice(-6).toUpperCase()}`;
};

const filteredReservations = computed(() => {
    const value = search.value.trim().toLowerCase();

    return props.reservations.filter((reservation) => {
        const matchesSearch =
            value === '' ||
            reservationFolio(reservation).toLowerCase().includes(value) ||
            reservation.book_title.toLowerCase().includes(value) ||
            reservation.student_id.toLowerCase().includes(value) ||
            (reservation.assigned_copy_code ?? '')
                .toLowerCase()
                .includes(value);

        const matchesStatus =
            statusFilter.value === 'all' ||
            reservation.status === statusFilter.value;

        return matchesSearch && matchesStatus;
    });
});

const statusLabel = (status: ReservationStatus): string => {
    const labels: Record<ReservationStatus, string> = {
        pending: 'Pendiente',
        ready: 'Lista para recoger',
        fulfilled: 'Atendida',
        cancelled: 'Cancelada',
        expired: 'Expirada',
    };

    return labels[status];
};

const statusClass = (status: ReservationStatus): string => {
    return {
        pending: 'status-pending',
        ready: 'status-ready',
        fulfilled: 'status-fulfilled',
        cancelled: 'status-cancelled',
        expired: 'status-expired',
    }[status];
};

const formatDateTime = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('es-MX', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
};

const canExpire = (reservation: BookReservation): boolean => {
    if (reservation.status !== 'ready' || !reservation.expires_at) {
        return false;
    }

    return new Date(reservation.expires_at).getTime() <= Date.now();
};

const openCreateForm = () => {
    form.reset();
    form.clearErrors();

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
};

const closeForm = () => {
    showForm.value = false;

    form.reset();
    form.clearErrors();
};

const createReservation = () => {
    if (!form.book_id) {
        window.alert('Selecciona un libro.');

        return;
    }

    if (!form.student_id.trim()) {
        window.alert('Ingresa el ID del estudiante.');

        return;
    }

    form.student_id = form.student_id.trim().toUpperCase();

    form.notes = form.notes.trim();

    form.post('/servicios-estudiante/biblioteca/reservas', {
        preserveScroll: true,

        onSuccess: () => {
            closeForm();

            window.alert('Reserva registrada correctamente.');
        },
    });
};

const assignCopy = (reservation: BookReservation) => {
    if (reservation.status !== 'pending') {
        return;
    }

    const confirmed = window.confirm(
        `¿Asignar automáticamente un ejemplar disponible a ${reservationFolio(
            reservation,
        )}?`,
    );

    if (!confirmed) {
        return;
    }

    processingActionId.value = reservation.id;

    router.patch(
        `/servicios-estudiante/biblioteca/reservas/${reservation.id}/asignar`,
        {
            pickup_hours: 24,
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert(
                    'Ejemplar asignado correctamente. La reserva estará disponible durante 24 horas.',
                );
            },

            onError: (errors) => {
                window.alert(
                    errors.reservation ?? 'No fue posible asignar un ejemplar.',
                );
            },

            onFinish: () => {
                processingActionId.value = null;
            },
        },
    );
};

const fulfillReservation = (reservation: BookReservation) => {
    if (reservation.status !== 'ready') {
        return;
    }

    const confirmed = window.confirm(
        `¿Atender ${reservationFolio(
            reservation,
        )} y generar el préstamo correspondiente?`,
    );

    if (!confirmed) {
        return;
    }

    processingActionId.value = reservation.id;

    router.patch(
        `/servicios-estudiante/biblioteca/reservas/${reservation.id}/completar`,
        {
            loan_days: 7,
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert(
                    'Reserva atendida. El préstamo fue generado correctamente.',
                );
            },

            onError: (errors) => {
                window.alert(
                    errors.reservation ?? 'No fue posible atender la reserva.',
                );
            },

            onFinish: () => {
                processingActionId.value = null;
            },
        },
    );
};

const cancelReservation = (reservation: BookReservation) => {
    if (reservation.status !== 'pending' && reservation.status !== 'ready') {
        return;
    }

    const confirmed = window.confirm(
        `¿Cancelar ${reservationFolio(reservation)}?`,
    );

    if (!confirmed) {
        return;
    }

    processingActionId.value = reservation.id;

    router.patch(
        `/servicios-estudiante/biblioteca/reservas/${reservation.id}/cancelar`,
        {
            notes: 'Reserva cancelada.',
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert('Reserva cancelada correctamente.');
            },

            onError: (errors) => {
                window.alert(
                    errors.reservation ?? 'No fue posible cancelar la reserva.',
                );
            },

            onFinish: () => {
                processingActionId.value = null;
            },
        },
    );
};

const expireReservation = (reservation: BookReservation) => {
    if (!canExpire(reservation)) {
        window.alert(
            'Esta reserva todavía no ha alcanzado su fecha de expiración.',
        );

        return;
    }

    const confirmed = window.confirm(
        `¿Marcar ${reservationFolio(reservation)} como expirada?`,
    );

    if (!confirmed) {
        return;
    }

    processingActionId.value = reservation.id;

    router.patch(
        `/servicios-estudiante/biblioteca/reservas/${reservation.id}/expirar`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert('Reserva marcada como expirada.');
            },

            onError: (errors) => {
                window.alert(
                    errors.reservation ?? 'No fue posible expirar la reserva.',
                );
            },

            onFinish: () => {
                processingActionId.value = null;
            },
        },
    );
};

const viewDetails = (reservation: BookReservation) => {
    const detail = [
        `Reserva: ${reservationFolio(reservation)}`,
        `Libro: ${reservation.book_title}`,
        `ID libro: ${reservation.book_id}`,
        `Estudiante: ${reservation.student_id}`,
        `Ejemplar: ${reservation.assigned_copy_code ?? 'Sin asignar'}`,
        `Reservada: ${formatDateTime(reservation.reserved_at)}`,
        `Lista para recoger: ${formatDateTime(reservation.ready_at)}`,
        `Expira: ${formatDateTime(reservation.expires_at)}`,
        `Atendida: ${formatDateTime(reservation.fulfilled_at)}`,
        `Estado: ${statusLabel(reservation.status)}`,
        `Notas: ${reservation.notes ?? 'Sin notas'}`,
    ].join('\n');

    window.alert(detail);
};

/**
 * Errores del servidor que no corresponden a un campo del formulario
 * (por ejemplo, reglas de negocio devueltas con withErrors).
 */
const serverError = (form: { errors: object }, key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <StudentServicesLayout
        title="Reservas de biblioteca"
        subtitle="Control de reservas y asignación de ejemplares"
    >
        <section class="summary">
            <div>
                <span class="section-label"> BIBLIOTECA · MÓDULO 5.1 </span>

                <h2>Reservas de libros</h2>

                <p>
                    Administra solicitudes de libros, asigna ejemplares
                    disponibles y controla el periodo de recolección.
                </p>
            </div>

            <div class="total-box">
                <span> Total registradas </span>

                <strong>
                    {{ reservations.length }}
                </strong>

                <small> reservas </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span> Pendientes </span>

                <strong>
                    {{ pendingCount }}
                </strong>

                <small> Esperan ejemplar </small>
            </article>

            <article class="stat-card">
                <span> Listas </span>

                <strong>
                    {{ readyCount }}
                </strong>

                <small> Listas para recoger </small>
            </article>

            <article class="stat-card">
                <span> Atendidas </span>

                <strong>
                    {{ fulfilledCount }}
                </strong>

                <small> Convertidas a préstamo </small>
            </article>

            <article class="stat-card">
                <span> Cerradas </span>

                <strong>
                    {{ closedCount }}
                </strong>

                <small> Canceladas o expiradas </small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h3>Reservas registradas</h3>

                    <p>Datos almacenados en MongoDB.</p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Nueva reserva
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="form-label"> NUEVA RESERVA </span>

                        <h3>Registrar reserva</h3>

                        <p>
                            Selecciona el libro y registra al estudiante
                            solicitante.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeForm"
                    >
                        ×
                    </button>
                </div>

                <form
                    class="reservation-form"
                    @submit.prevent="createReservation"
                >
                    <div class="form-grid">
                        <div class="form-field form-field-full">
                            <label for="book_id">
                                Libro
                                <span>*</span>
                            </label>

                            <select id="book_id" v-model="form.book_id">
                                <option value="">Selecciona un libro</option>

                                <option
                                    v-for="book in books"
                                    :key="book.id"
                                    :value="book.id"
                                >
                                    {{ book.title }}
                                    ·
                                    {{ book.available_copies }}
                                    disponible(s)
                                </option>
                            </select>

                            <small
                                v-if="form.errors.book_id"
                                class="form-error"
                            >
                                {{ form.errors.book_id }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="student_id">
                                ID del estudiante
                                <span>*</span>
                            </label>

                            <input
                                id="student_id"
                                v-model="form.student_id"
                                type="text"
                                placeholder="Ej. EST-0001"
                            />

                            <small
                                v-if="form.errors.student_id"
                                class="form-error"
                            >
                                {{ form.errors.student_id }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label> Ejemplares disponibles </label>

                            <input
                                type="text"
                                :value="
                                    selectedBook
                                        ? String(selectedBook.available_copies)
                                        : ''
                                "
                                disabled
                            />
                        </div>

                        <div class="form-field form-field-full">
                            <label> ISBN </label>

                            <input
                                type="text"
                                :value="selectedBook?.isbn ?? ''"
                                disabled
                            />
                        </div>

                        <div class="form-field form-field-full">
                            <label for="notes"> Notas </label>

                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Observaciones opcionales..."
                            ></textarea>
                        </div>
                    </div>

                    <div
                        v-if="serverError(form, 'reservation')"
                        class="error-box"
                    >
                        {{ serverError(form, 'reservation') }}
                    </div>

                    <div class="information-box">
                        La reserva se crea como
                        <strong> Pendiente </strong>. Después se puede asignar
                        automáticamente un ejemplar disponible. Cuando se
                        atiende, el sistema genera el préstamo correspondiente.
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="closeForm"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="primary-button"
                            :disabled="form.processing"
                        >
                            {{
                                form.processing
                                    ? 'Registrando...'
                                    : 'Registrar reserva'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <div class="filters">
                <div class="search-field">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por reserva, libro, estudiante o ejemplar..."
                    />
                </div>

                <select v-model="statusFilter">
                    <option value="all">Todos los estados</option>

                    <option value="pending">Pendientes</option>

                    <option value="ready">Listas para recoger</option>

                    <option value="fulfilled">Atendidas</option>

                    <option value="cancelled">Canceladas</option>

                    <option value="expired">Expiradas</option>
                </select>
            </div>

            <div v-if="filteredReservations.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Reserva</th>
                            <th>Libro</th>
                            <th>Estudiante</th>
                            <th>Ejemplar</th>
                            <th>Reservada</th>
                            <th>Expira</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="reservation in filteredReservations"
                            :key="reservation.id"
                        >
                            <td>
                                <strong class="reservation-id">
                                    {{ reservationFolio(reservation) }}
                                </strong>
                            </td>

                            <td>
                                <div class="book-info">
                                    <strong>
                                        {{ reservation.book_title }}
                                    </strong>

                                    <small>
                                        {{ reservation.book_id }}
                                    </small>
                                </div>
                            </td>

                            <td>
                                <span class="student-id">
                                    {{ reservation.student_id }}
                                </span>
                            </td>

                            <td>
                                {{
                                    reservation.assigned_copy_code ??
                                    'Sin asignar'
                                }}
                            </td>

                            <td>
                                {{ formatDateTime(reservation.reserved_at) }}
                            </td>

                            <td>
                                {{ formatDateTime(reservation.expires_at) }}
                            </td>

                            <td>
                                <span
                                    class="status"
                                    :class="statusClass(reservation.status)"
                                >
                                    {{ statusLabel(reservation.status) }}
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-button details"
                                        @click="viewDetails(reservation)"
                                    >
                                        Ver
                                    </button>

                                    <button
                                        v-if="reservation.status === 'pending'"
                                        type="button"
                                        class="action-button assign"
                                        :disabled="
                                            processingActionId ===
                                            reservation.id
                                        "
                                        @click="assignCopy(reservation)"
                                    >
                                        Asignar
                                    </button>

                                    <button
                                        v-if="reservation.status === 'ready'"
                                        type="button"
                                        class="action-button fulfill"
                                        :disabled="
                                            processingActionId ===
                                            reservation.id
                                        "
                                        @click="fulfillReservation(reservation)"
                                    >
                                        Atender
                                    </button>

                                    <button
                                        v-if="canExpire(reservation)"
                                        type="button"
                                        class="action-button expire"
                                        :disabled="
                                            processingActionId ===
                                            reservation.id
                                        "
                                        @click="expireReservation(reservation)"
                                    >
                                        Expirar
                                    </button>

                                    <button
                                        v-if="
                                            reservation.status === 'pending' ||
                                            reservation.status === 'ready'
                                        "
                                        type="button"
                                        class="action-button cancel"
                                        :disabled="
                                            processingActionId ===
                                            reservation.id
                                        "
                                        @click="cancelReservation(reservation)"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                <h3>No se encontraron reservas</h3>

                <p>Cambia los filtros o registra una nueva reserva.</p>
            </div>
        </section>
    </StudentServicesLayout>
</template>

<style scoped>
.summary {
    padding: 24px 27px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 25px;
    border-radius: 12px;
    background: #2f5eb6;
    color: white;
}

.section-label,
.form-label {
    display: block;
    margin-bottom: 7px;
    color: #a9c5f2;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.summary h2 {
    margin: 0;
    font-size: 23px;
}

.summary p {
    max-width: 650px;
    margin: 7px 0 0;
    color: #dce8fa;
    font-size: 12px;
    line-height: 1.6;
}

.total-box {
    min-width: 150px;
    padding: 16px 19px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.total-box span {
    display: block;
    color: #d7e4f8;
    font-size: 10px;
}

.total-box strong {
    display: block;
    margin-top: 5px;
    font-size: 28px;
}

.total-box small {
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

.content-panel {
    margin-top: 18px;
    overflow: hidden;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.panel-header,
.form-header {
    padding: 18px 21px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.panel-header {
    border-bottom: 1px solid #e5e9ef;
}

.panel-header h3,
.form-header h3 {
    margin: 0;
    color: #25324a;
    font-size: 16px;
}

.panel-header p,
.form-header p {
    margin: 5px 0 0;
    color: #8794a7;
    font-size: 11px;
}

.primary-button,
.secondary-button {
    min-height: 38px;
    padding: 0 15px;
    border-radius: 7px;
    font: inherit;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.primary-button {
    border: 1px solid #2c63b7;
    background: #2c63b7;
    color: white;
}

.primary-button:disabled {
    cursor: default;
    opacity: 0.55;
}

.secondary-button {
    border: 1px solid #d6dee9;
    background: white;
    color: #55667d;
}

.form-panel {
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.form-header {
    border-bottom: 1px solid #e5e9ef;
}

.form-header .form-label {
    color: #315a9f;
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

.reservation-form {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px 19px;
}

.form-field-full {
    grid-column: 1 / -1;
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
.form-field select,
.form-field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 12px;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    border-color: #3970c1;
    box-shadow: 0 0 0 3px rgba(57, 112, 193, 0.08);
}

.form-field input:disabled {
    background: #f1f3f6;
    color: #768297;
}

.form-field textarea {
    resize: vertical;
}

.form-error {
    display: block;
    margin-top: 5px;
    color: #a94442;
    font-size: 9px;
}

.information-box,
.error-box {
    margin-top: 16px;
    padding: 11px 13px;
    border-radius: 7px;
    font-size: 10px;
    line-height: 1.5;
}

.information-box {
    border: 1px solid #d5e1f1;
    background: #eef4fc;
    color: #657690;
}

.information-box strong {
    color: #315a9f;
}

.error-box {
    border: 1px solid #e8c9cc;
    background: #fcedee;
    color: #9d434c;
}

.form-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid #e5e9ef;
}

.filters {
    padding: 15px 21px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.search-field {
    flex: 1;
}

.filters input,
.filters select {
    height: 38px;
    box-sizing: border-box;
    border: 1px solid #d7dfe9;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #415168;
    font: inherit;
    font-size: 10px;
}

.filters input {
    width: 100%;
    padding: 0 12px;
}

.filters select {
    min-width: 190px;
    padding: 0 10px;
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
    font-size: 11px;
    vertical-align: middle;
}

tbody tr:hover {
    background: #fafcff;
}

.reservation-id {
    color: #285aa6;
}

.book-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.book-info strong {
    color: #303d53;
}

.book-info small {
    color: #99a4b4;
    font-size: 9px;
}

.student-id {
    color: #42546d;
    font-weight: 700;
}

.status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
    white-space: nowrap;
}

.status-pending {
    background: #fff3d7;
    color: #946510;
}

.status-ready {
    background: #e4f6ec;
    color: #217a4e;
}

.status-fulfilled {
    background: #eaf0fc;
    color: #315fa6;
}

.status-cancelled {
    background: #f3e8e9;
    color: #98505a;
}

.status-expired {
    background: #f2e8e8;
    color: #8f4242;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 250px;
}

.action-button {
    min-height: 29px;
    padding: 0 8px;
    border-radius: 6px;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.action-button:disabled {
    cursor: default;
    opacity: 0.5;
}

.action-button.details {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.assign {
    border: 1px solid #ead7aa;
    background: #fff8e6;
    color: #936814;
}

.action-button.fulfill {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
}

.action-button.expire {
    border: 1px solid #e2d5b8;
    background: #fbf4e4;
    color: #85621d;
}

.action-button.cancel {
    border: 1px solid #e6c9cd;
    background: #fbebed;
    color: #9d4650;
}

.empty-state {
    padding: 55px 25px;
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

@media (max-width: 1000px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 750px) {
    .summary {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-field-full {
        grid-column: auto;
    }

    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .filters select {
        width: 100%;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .panel-header {
        align-items: stretch;
        flex-direction: column;
    }

    .form-actions {
        flex-direction: column-reverse;
    }
}
</style>
