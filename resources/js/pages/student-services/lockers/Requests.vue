<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type RequestStatus =
    | 'pending'
    | 'paid'
    | 'assigned'
    | 'rejected'
    | 'cancelled';

type RequestType =
    | 'paid'
    | 'council'
    | 'scholarship';

interface LockerRequest {
    id: string;
    folio: string;
    student_id: string;
    period_id: string;
    period_name: string;
    locker_id: string | null;
    locker_code: string | null;
    preferred_size: string;
    preferred_building: string | null;
    request_type: RequestType;
    status: RequestStatus;
    amount: string | null;
    payment_reference: string | null;
    paid_at: string | null;
    created_at: string | null;
    notes: string | null;
}

interface Period {
    id: string;
    code: string;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    prices: {
        small: string | null;
        medium: string | null;
        large: string | null;
    };
    status: string;
}

interface Availability {
    building: string;
    size: string;
    count: number;
}

interface Locker {
    id: string;
    code: string;
    qr_code: string;
    building: string;
    zone: string;
    size: string;
    status: string;
    notes: string | null;
}

const props = defineProps<{
    requests: LockerRequest[];
    periods: Period[];
    availability: Availability[];
    available_lockers: Locker[];
    current_student_id: string;
}>();

const BASE_URL =
    '/servicios-estudiante/lockers';

const search = ref('');

const statusFilter = ref('');
const typeFilter = ref('');

const showForm = ref(false);

const actionMode = ref<
    'pay' | 'assign' | null
>(null);

const selectedRequest =
    ref<LockerRequest | null>(null);

const paymentReference = ref('');
const selectedLockerId = ref('');

const form = useForm({
    period_id: '',
    size: '',
    building: '',
});

const pendingCount = computed(() => {
    return props.requests.filter(
        (request) =>
            request.status ===
            'pending',
    ).length;
});

const paidCount = computed(() => {
    return props.requests.filter(
        (request) =>
            request.status ===
            'paid',
    ).length;
});

const assignedCount = computed(() => {
    return props.requests.filter(
        (request) =>
            request.status ===
            'assigned',
    ).length;
});

const cancelledCount = computed(() => {
    return props.requests.filter(
        (request) =>
            request.status ===
            'cancelled',
    ).length;
});

const buildings = computed(() => {
    return [
        ...new Set(
            props.available_lockers.map(
                (locker) =>
                    locker.building,
            ),
        ),
    ].sort();
});

const filteredRequests = computed(() => {
    const term =
        search.value
            .trim()
            .toLowerCase();

    return props.requests.filter(
        (request) => {
            if (
                statusFilter.value &&
                request.status !==
                statusFilter.value
            ) {
                return false;
            }

            if (
                typeFilter.value &&
                request.request_type !==
                typeFilter.value
            ) {
                return false;
            }

            if (!term) {
                return true;
            }

            return [
                request.folio,
                request.student_id,
                request.period_name,
                request.locker_code ?? '',
                request.preferred_building ?? '',
            ].some((value) =>
                value
                    .toLowerCase()
                    .includes(term),
            );
        },
    );
});

const assignableLockers =
    computed(() => {
        if (
            selectedRequest.value ===
            null
        ) {
            return [];
        }

        return props.available_lockers.filter(
            (locker) => {
                if (
                    selectedRequest.value
                        ?.preferred_size &&
                    locker.size !==
                    selectedRequest.value
                        .preferred_size
                ) {
                    return false;
                }

                const preferredBuilding =
                    selectedRequest.value
                        ?.preferred_building;

                if (
                    preferredBuilding &&
                    locker.building !==
                    preferredBuilding
                ) {
                    return false;
                }

                return true;
            },
        );
    });

function sizeLabel(
    size: string,
): string {
    const labels: Record<
        string,
        string
    > = {
        small: 'Chico',
        medium: 'Mediano',
        large: 'Grande',
    };

    return labels[size] ?? size;
}

function requestTypeLabel(
    type: RequestType,
): string {
    const labels: Record<
        RequestType,
        string
    > = {
        paid: 'Renta pagada',
        council: 'Consejo',
        scholarship: 'Beca',
    };

    return labels[type];
}

function statusLabel(
    status: RequestStatus,
): string {
    const labels: Record<
        RequestStatus,
        string
    > = {
        pending: 'Pendiente',
        paid: 'Pagada',
        assigned: 'Asignada',
        rejected: 'Rechazada',
        cancelled: 'Cancelada',
    };

    return labels[status];
}

function money(
    value: string | null,
): string {
    if (value === null) {
        return '—';
    }

    const numberValue =
        Number(value);

    return new Intl.NumberFormat(
        'es-MX',
        {
            style: 'currency',
            currency: 'MXN',
        },
    ).format(
        Number.isNaN(numberValue)
            ? 0
            : numberValue,
    );
}

function formatDateTime(
    value: string | null,
): string {
    if (!value) {
        return '—';
    }

    const parsed =
        new Date(value);

    if (
        Number.isNaN(
            parsed.getTime(),
        )
    ) {
        return value;
    }

    return parsed.toLocaleString(
        'es-MX',
        {
            dateStyle: 'short',
            timeStyle: 'short',
        },
    );
}

function openRequestForm() {
    form.reset();
    form.clearErrors();

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeRequestForm() {
    form.reset();
    form.clearErrors();

    showForm.value = false;
}

function submitRequest() {
    form.post(
        `${BASE_URL}/solicitudes`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeRequestForm();
            },
        },
    );
}

function openPayment(
    request: LockerRequest,
) {
    selectedRequest.value =
        request;

    paymentReference.value = '';

    actionMode.value = 'pay';
}

function submitPayment() {
    if (
        selectedRequest.value ===
        null
    ) {
        return;
    }

    if (
        !paymentReference.value
            .trim()
    ) {
        window.alert(
            'Escribe una referencia de pago.',
        );

        return;
    }

    router.patch(
        `${BASE_URL}/solicitudes/${selectedRequest.value.id}/pagar`,
        {
            payment_reference:
                paymentReference.value
                    .trim(),
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                closeAction();
            },

            onError: (
                errors,
            ) => {
                window.alert(
                    errors.status ??
                    errors.payment_reference ??
                    'No se pudo registrar el pago.',
                );
            },
        },
    );
}

function openAssignment(
    request: LockerRequest,
) {
    selectedRequest.value =
        request;

    selectedLockerId.value = '';

    actionMode.value = 'assign';
}

function submitAssignment() {
    if (
        selectedRequest.value ===
        null
    ) {
        return;
    }

    if (
        !selectedLockerId.value
    ) {
        window.alert(
            'Selecciona un locker.',
        );

        return;
    }

    router.patch(
        `${BASE_URL}/solicitudes/${selectedRequest.value.id}/asignar`,
        {
            locker_id:
            selectedLockerId.value,
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                closeAction();
            },

            onError: (
                errors,
            ) => {
                window.alert(
                    errors.status ??
                    errors.locker_id ??
                    'No se pudo realizar la asignación.',
                );
            },
        },
    );
}

function cancelRequest(
    request: LockerRequest,
) {
    const confirmed =
        window.confirm(
            `¿Cancelar la solicitud ${request.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    router.patch(
        `${BASE_URL}/solicitudes/${request.id}/cancelar`,
        {},
        {
            preserveScroll: true,

            onError: (
                errors,
            ) => {
                window.alert(
                    errors.status ??
                    'No se pudo cancelar la solicitud.',
                );
            },
        },
    );
}

function closeAction() {
    actionMode.value = null;
    selectedRequest.value = null;
    paymentReference.value = '';
    selectedLockerId.value = '';
}

function availabilityCount(
    building: string,
    size: string,
): number {
    return (
        props.availability.find(
            (item) =>
                item.building ===
                building &&
                item.size === size,
        )?.count ?? 0
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Solicitudes de locker"
        subtitle="Solicitud, pago y asignación de lockers"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    LOCKERS · MÓDULO 5.4
                </span>

                <h2>
                    Solicitud y asignación
                </h2>

                <p>
                    Solicita un locker para
                    un periodo activo,
                    registra el pago y
                    consulta el proceso de
                    asignación.
                </p>
            </div>

            <div class="hero-user">
                <span>
                    Usuario actual
                </span>

                <strong>
                    {{
                        current_student_id
                    }}
                </strong>

                <small>
                    Identificado por
                    Fortify
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Pendientes
                </span>

                <strong>
                    {{ pendingCount }}
                </strong>

                <small>
                    Esperan pago
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Pagadas
                </span>

                <strong>
                    {{ paidCount }}
                </strong>

                <small>
                    Esperan asignación
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Asignadas
                </span>

                <strong>
                    {{ assignedCount }}
                </strong>

                <small>
                    Con locker
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Canceladas
                </span>

                <strong>
                    {{ cancelledCount }}
                </strong>

                <small>
                    Solicitudes cerradas
                </small>
            </article>
        </section>

        <section class="module-navigation">
            <Link
                :href="BASE_URL"
                class="module-link"
            >
                Catálogo
            </Link>

            <Link
                :href="`${BASE_URL}/periodos`"
                class="module-link"
            >
                Periodos y costos
            </Link>

            <Link
                :href="`${BASE_URL}/solicitudes`"
                class="module-link active"
            >
                Solicitudes
            </Link>

            <Link
                :href="`${BASE_URL}/asignaciones`"
                class="module-link"
            >
                Asignaciones
            </Link>

            <Link
                :href="`${BASE_URL}/acceso`"
                class="module-link"
            >
                Validar acceso
            </Link>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        DISPONIBILIDAD
                    </span>

                    <h3>
                        Lockers disponibles
                    </h3>

                    <p>
                        Existencias actuales
                        agrupadas por edificio
                        y tamaño.
                    </p>
                </div>

                <span class="availability-total">
                    {{
                        available_lockers.length
                    }}
                    disponibles
                </span>
            </div>

            <div
                v-if="
                    buildings.length > 0
                "
                class="availability-grid"
            >
                <article
                    v-for="
                        building in
                        buildings
                    "
                    :key="building"
                    class="availability-card"
                >
                    <strong>
                        {{ building }}
                    </strong>

                    <div class="availability-values">
                        <div>
                            <span>
                                Chico
                            </span>

                            <strong>
                                {{
                                    availabilityCount(
                                        building,
                                        'small',
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Mediano
                            </span>

                            <strong>
                                {{
                                    availabilityCount(
                                        building,
                                        'medium',
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Grande
                            </span>

                            <strong>
                                {{
                                    availabilityCount(
                                        building,
                                        'large',
                                    )
                                }}
                            </strong>
                        </div>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="small-empty"
            >
                No hay lockers disponibles
                actualmente.
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        SOLICITUDES
                    </span>

                    <h3>
                        Solicitudes registradas
                    </h3>

                    <p>
                        Consulta el estado,
                        pago y locker asignado.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="
                        openRequestForm
                    "
                >
                    + Solicitar locker
                </button>
            </div>

            <section
                v-if="showForm"
                class="form-panel"
            >
                <div class="form-header">
                    <div>
                        <span class="panel-label">
                            NUEVA SOLICITUD
                        </span>

                        <h3>
                            Solicitar locker
                        </h3>

                        <p>
                            Selecciona periodo,
                            tamaño y ubicación
                            preferida.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeRequestForm
                        "
                    >
                        ×
                    </button>
                </div>

                <form
                    class="request-form"
                    @submit.prevent="
                        submitRequest
                    "
                >
                    <div class="form-grid">
                        <div class="form-field">
                            <label>
                                Periodo
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    form.period_id
                                "
                            >
                                <option value="">
                                    Selecciona un
                                    periodo
                                </option>

                                <option
                                    v-for="
                                        period in
                                        periods
                                    "
                                    :key="
                                        period.id
                                    "
                                    :value="
                                        period.id
                                    "
                                >
                                    {{
                                        period.name
                                    }}
                                </option>
                            </select>

                            <small
                                v-if="
                                    form.errors
                                        .period_id
                                "
                                class="field-error"
                            >
                                {{
                                    form.errors
                                        .period_id
                                }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label>
                                Tamaño
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    form.size
                                "
                            >
                                <option value="">
                                    Selecciona
                                </option>

                                <option value="small">
                                    Chico
                                </option>

                                <option value="medium">
                                    Mediano
                                </option>

                                <option value="large">
                                    Grande
                                </option>
                            </select>

                            <small
                                v-if="
                                    form.errors
                                        .size
                                "
                                class="field-error"
                            >
                                {{
                                    form.errors
                                        .size
                                }}
                            </small>
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Edificio preferido
                            </label>

                            <select
                                v-model="
                                    form.building
                                "
                            >
                                <option value="">
                                    Cualquier edificio
                                </option>

                                <option
                                    v-for="
                                        building in
                                        buildings
                                    "
                                    :key="
                                        building
                                    "
                                    :value="
                                        building
                                    "
                                >
                                    {{ building }}
                                </option>
                            </select>

                            <small
                                v-if="
                                    form.errors
                                        .building
                                "
                                class="field-error"
                            >
                                {{
                                    form.errors
                                        .building
                                }}
                            </small>
                        </div>
                    </div>

                    <div
                        v-if="
                            form.errors.status
                        "
                        class="error-box"
                    >
                        {{
                            form.errors
                                .status
                        }}
                    </div>

                    <div class="information-box">
                        <strong>
                            Flujo:
                        </strong>

                        la solicitud se crea
                        como pendiente. Una
                        vez registrado el
                        pago, el sistema
                        intentará asignar un
                        locker disponible de
                        manera automática.
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            :disabled="
                                form.processing
                            "
                            @click="
                                closeRequestForm
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="primary-button"
                            :disabled="
                                form.processing
                            "
                        >
                            {{
                                form.processing
                                    ? 'Registrando...'
                                    : 'Crear solicitud'
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
                        placeholder="Buscar por folio, estudiante, periodo o locker..."
                    />
                </div>

                <select
                    v-model="
                        typeFilter
                    "
                >
                    <option value="">
                        Todos los tipos
                    </option>

                    <option value="paid">
                        Renta pagada
                    </option>

                    <option value="council">
                        Consejo
                    </option>

                    <option value="scholarship">
                        Beca
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

                    <option value="pending">
                        Pendiente
                    </option>

                    <option value="paid">
                        Pagada
                    </option>

                    <option value="assigned">
                        Asignada
                    </option>

                    <option value="cancelled">
                        Cancelada
                    </option>

                    <option value="rejected">
                        Rechazada
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredRequests.length >
                    0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Estudiante</th>
                        <th>Periodo</th>
                        <th>Preferencia</th>
                        <th>Monto</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th>Locker</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                request in
                                filteredRequests
                            "
                        :key="
                                request.id
                            "
                    >
                        <td>
                            <strong class="folio">
                                {{
                                    request.folio
                                }}
                            </strong>

                            <small class="date">
                                {{
                                    formatDateTime(
                                        request.created_at,
                                    )
                                }}
                            </small>
                        </td>

                        <td>
                                <span class="student">
                                    {{
                                        request.student_id
                                    }}
                                </span>
                        </td>

                        <td>
                            {{
                                request.period_name
                            }}
                        </td>

                        <td>
                            <div class="preference">
                                <strong>
                                    {{
                                        sizeLabel(
                                            request.preferred_size,
                                        )
                                    }}
                                </strong>

                                <small>
                                    {{
                                        request.preferred_building
                                        ??
                                        'Cualquier edificio'
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                            <strong>
                                {{
                                    money(
                                        request.amount,
                                    )
                                }}
                            </strong>
                        </td>

                        <td>
                            {{
                                requestTypeLabel(
                                    request.request_type,
                                )
                            }}
                        </td>

                        <td>
                                <span
                                    class="status"
                                    :class="`status-${request.status}`"
                                >
                                    {{
                                        statusLabel(
                                            request.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <strong
                                v-if="
                                        request.locker_code
                                    "
                                class="locker-code"
                            >
                                {{
                                    request.locker_code
                                }}
                            </strong>

                            <span v-else>
                                    —
                                </span>
                        </td>

                        <td>
                            <div class="actions">
                                <button
                                    v-if="
                                            request.request_type ===
                                                'paid' &&
                                            request.status ===
                                                'pending'
                                        "
                                    type="button"
                                    class="action-button pay"
                                    @click="
                                            openPayment(
                                                request,
                                            )
                                        "
                                >
                                    Registrar pago
                                </button>

                                <button
                                    v-if="
                                            request.status ===
                                            'paid'
                                        "
                                    type="button"
                                    class="action-button assign"
                                    @click="
                                            openAssignment(
                                                request,
                                            )
                                        "
                                >
                                    Asignar
                                </button>

                                <button
                                    v-if="
                                            request.status ===
                                            'pending'
                                        "
                                    type="button"
                                    class="action-button cancel"
                                    @click="
                                            cancelRequest(
                                                request,
                                            )
                                        "
                                >
                                    Cancelar
                                </button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="empty-state"
            >
                <h3>
                    No hay solicitudes
                </h3>

                <p>
                    Registra una nueva
                    solicitud o cambia los
                    filtros.
                </p>
            </div>
        </section>

        <div
            v-if="actionMode"
            class="modal-backdrop"
            @click.self="closeAction"
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">
                            {{
                                actionMode ===
                                'pay'
                                    ? 'REGISTRAR PAGO'
                                    : 'ASIGNAR LOCKER'
                            }}
                        </span>

                        <h3>
                            {{
                                selectedRequest
                                    ?.folio
                            }}
                        </h3>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeAction
                        "
                    >
                        ×
                    </button>
                </div>

                <div
                    v-if="
                        actionMode ===
                        'pay'
                    "
                    class="modal-body"
                >
                    <p>
                        Monto:
                        <strong>
                            {{
                                money(
                                    selectedRequest
                                        ?.amount ??
                                    null,
                                )
                            }}
                        </strong>
                    </p>

                    <label>
                        Referencia de pago
                    </label>

                    <input
                        v-model="
                            paymentReference
                        "
                        type="text"
                        placeholder="Ej. PAY-LOCKER-0001"
                    />

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeAction
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                submitPayment
                            "
                        >
                            Confirmar pago
                        </button>
                    </div>
                </div>

                <div
                    v-else
                    class="modal-body"
                >
                    <label>
                        Locker disponible
                    </label>

                    <select
                        v-model="
                            selectedLockerId
                        "
                    >
                        <option value="">
                            Selecciona un locker
                        </option>

                        <option
                            v-for="
                                locker in
                                assignableLockers
                            "
                            :key="
                                locker.id
                            "
                            :value="
                                locker.id
                            "
                        >
                            {{ locker.code }}
                            ·
                            {{ locker.building }}
                            ·
                            {{ locker.zone }}
                        </option>
                    </select>

                    <p
                        v-if="
                            assignableLockers.length ===
                            0
                        "
                        class="modal-warning"
                    >
                        No existen lockers
                        disponibles que
                        coincidan con esta
                        solicitud.
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeAction
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="
                                assignableLockers.length ===
                                0
                            "
                            @click="
                                submitAssignment
                            "
                        >
                            Asignar locker
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

.hero-user {
    min-width: 185px;
    padding: 16px 19px;
    border-radius: 10px;
    background:
        rgba(255, 255, 255, 0.1);
}

.hero-user span {
    display: block;
    color: #d8e4f8;
    font-size: 9px;
}

.hero-user strong {
    display: block;
    margin-top: 5px;
    font-size: 12px;
    word-break: break-all;
}

.hero-user small {
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

.module-navigation {
    margin-top: 18px;
    padding: 6px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    border: 1px solid #dfe5ee;
    border-radius: 10px;
    background: white;
}

.module-link {
    min-height: 36px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    border-radius: 7px;
    color: #697a91;
    text-decoration: none;
    font-size: 10px;
    font-weight: 700;
}

.module-link:hover {
    background: #f0f4fa;
}

.module-link.active {
    background: #e8f0fc;
    color: #2e5da3;
}

.content-panel {
    margin-top: 18px;
    overflow: hidden;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.panel-header,
.form-header,
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
.form-header h3,
.modal-header h3 {
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

.availability-total {
    padding: 6px 10px;
    border-radius: 7px;
    background: #eaf6ef;
    color: #26734c;
    font-size: 9px;
    font-weight: 800;
}

.availability-grid {
    padding: 17px 21px 21px;
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(230px, 1fr)
        );
    gap: 12px;
}

.availability-card {
    padding: 15px;
    border: 1px solid #e0e6ee;
    border-radius: 9px;
    background: #fafcff;
}

.availability-card > strong {
    color: #34435a;
    font-size: 11px;
}

.availability-values {
    margin-top: 12px;
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 7px;
}

.availability-values div {
    padding: 8px;
    border-radius: 6px;
    background: white;
    text-align: center;
}

.availability-values span {
    display: block;
    color: #8b98aa;
    font-size: 8px;
}

.availability-values strong {
    display: block;
    margin-top: 3px;
    color: #315a9f;
    font-size: 14px;
}

.small-empty {
    padding: 25px;
    color: #8c99aa;
    text-align: center;
    font-size: 10px;
}

.primary-button,
.secondary-button {
    min-height: 38px;
    padding: 0 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
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

.secondary-button {
    border: 1px solid #d6dee9;
    background: white;
    color: #55667d;
}

.primary-button:disabled,
.secondary-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.form-panel {
    border-bottom:
        1px solid #e5e9ef;
    background: #fafcff;
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

.request-form {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns:
        repeat(2, 1fr);
    gap: 17px 19px;
}

.form-field.full {
    grid-column: 1 / -1;
}

.form-field label,
.modal-body label {
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
.modal-body input,
.modal-body select {
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

.field-error {
    display: block;
    margin-top: 5px;
    color: #b83b3b;
    font-size: 9px;
    font-weight: 700;
}

.information-box,
.error-box {
    margin-top: 16px;
    padding: 11px 13px;
    border-radius: 7px;
    font-size: 10px;
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
    border: 1px solid #ebc6c6;
    background: #fceded;
    color: #a04444;
}

.form-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top:
        1px solid #e5e9ef;
}

.filters {
    padding: 14px 21px;
    display: grid;
    grid-template-columns:
        minmax(230px, 2fr)
        180px
        180px;
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
    vertical-align: middle;
}

tbody tr:hover {
    background: #fafcff;
}

.folio {
    display: block;
    color: #285aa6;
}

.date {
    display: block;
    margin-top: 3px;
    color: #99a4b4;
    font-size: 8px;
}

.student {
    color: #42546d;
    font-weight: 700;
}

.preference {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.preference strong {
    color: #42546d;
}

.preference small {
    color: #8c99aa;
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

.status-paid {
    background: #e8f0fc;
    color: #315fa6;
}

.status-assigned {
    background: #e4f6ec;
    color: #217a4e;
}

.status-cancelled,
.status-rejected {
    background: #f3e8e9;
    color: #98505a;
}

.locker-code {
    color: #315fa6;
}

.actions {
    min-width: 170px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.action-button {
    min-height: 29px;
    padding: 0 8px;
    border-radius: 6px;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.action-button.pay {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
}

.action-button.assign {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
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
    width: min(500px, 100%);
    overflow: hidden;
    border-radius: 11px;
    background: white;
    box-shadow:
        0 18px 50px
        rgba(0, 0, 0, 0.18);
}

.modal-body {
    padding: 21px;
}

.modal-body p {
    margin: 0 0 16px;
    color: #68788e;
    font-size: 11px;
}

.modal-warning {
    margin-top: 10px !important;
    color: #9a631d !important;
}

.modal-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

@media (max-width: 1000px) {
    .stats-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .filters {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-user {
        width: 100%;
        box-sizing: border-box;
    }

    .panel-header,
    .form-header {
        align-items: stretch;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-field.full {
        grid-column: auto;
    }

    .availability-values {
        grid-template-columns: 1fr;
    }

    .form-actions,
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
