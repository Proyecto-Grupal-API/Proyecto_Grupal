<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type AssignmentStatus =
    | 'active'
    | 'released'
    | 'expired';

type AssignmentSource =
    | 'paid'
    | 'council'
    | 'scholarship';

interface Assignment {
    id: string;
    folio: string;
    student_id: string;
    locker_id: string;
    locker_code: string;
    locker_location: string | null;
    locker_size: string | null;
    period_id: string;
    period_name: string;
    source: AssignmentSource;
    status: AssignmentStatus;
    starts_at: string | null;
    ends_at: string | null;
    released_at: string | null;
    renewal_count: number;
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

interface Summary {
    total: number;
    active: number;
    released: number;
    expired: number;
}

const props = defineProps<{
    assignments: Assignment[];
    periods: Period[];
    available_lockers: Locker[];
    summary: Summary;
}>();

const BASE_URL =
    '/servicios-estudiante/lockers';

const search = ref('');
const statusFilter = ref('');
const sourceFilter = ref('');

const showSponsoredForm =
    ref(false);

const actionMode = ref<
    'renew' | 'release' | null
>(null);

const selectedAssignment =
    ref<Assignment | null>(null);

const sponsoredForm = useForm({
    student_id: '',
    period_id: '',
    request_type:
        'scholarship' as
            | 'council'
            | 'scholarship',
    locker_id: '',
    size: '',
    reference: '',
});

const renewForm = useForm({
    period_id: '',
    payment_reference: '',
});

const releaseForm = useForm({
    reason: '',
});

const filteredAssignments =
    computed(() => {
        const term =
            search.value
                .trim()
                .toLowerCase();

        return props.assignments.filter(
            (assignment) => {
                if (
                    statusFilter.value &&
                    assignment.status !==
                    statusFilter.value
                ) {
                    return false;
                }

                if (
                    sourceFilter.value &&
                    assignment.source !==
                    sourceFilter.value
                ) {
                    return false;
                }

                if (!term) {
                    return true;
                }

                return [
                    assignment.folio,
                    assignment.student_id,
                    assignment.locker_code,
                    assignment.period_name,
                    assignment.locker_location ??
                    '',
                ].some((value) =>
                    value
                        .toLowerCase()
                        .includes(term),
                );
            },
        );
    });

const sponsoredAvailableLockers =
    computed(() => {
        if (
            sponsoredForm.locker_id
        ) {
            return props.available_lockers;
        }

        if (!sponsoredForm.size) {
            return props.available_lockers;
        }

        return props.available_lockers.filter(
            (locker) =>
                locker.size ===
                sponsoredForm.size,
        );
    });

function statusLabel(
    status: AssignmentStatus,
): string {
    const labels: Record<
        AssignmentStatus,
        string
    > = {
        active: 'Activa',
        released: 'Liberada',
        expired: 'Expirada',
    };

    return labels[status];
}

function sourceLabel(
    source: AssignmentSource,
): string {
    const labels: Record<
        AssignmentSource,
        string
    > = {
        paid: 'Renta pagada',
        council: 'Consejo',
        scholarship: 'Beca',
    };

    return labels[source];
}

function sizeLabel(
    size: string | null,
): string {
    if (!size) {
        return '—';
    }

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

function formatDate(
    value: string | null,
): string {
    if (!value) {
        return '—';
    }

    const clean =
        value.slice(0, 10);

    const parts =
        clean.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function openSponsoredForm() {
    sponsoredForm.reset();
    sponsoredForm.clearErrors();

    sponsoredForm.request_type =
        'scholarship';

    showSponsoredForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeSponsoredForm() {
    sponsoredForm.reset();
    sponsoredForm.clearErrors();

    showSponsoredForm.value =
        false;
}

function submitSponsored() {
    sponsoredForm.post(
        `${BASE_URL}/asignaciones/beca`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeSponsoredForm();
            },
        },
    );
}

function openRenew(
    assignment: Assignment,
) {
    selectedAssignment.value =
        assignment;

    renewForm.reset();
    renewForm.clearErrors();

    actionMode.value = 'renew';
}

function submitRenew() {
    if (
        selectedAssignment.value ===
        null
    ) {
        return;
    }

    renewForm.patch(
        `${BASE_URL}/asignaciones/${selectedAssignment.value.id}/renovar`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeAction();
            },
        },
    );
}

function openRelease(
    assignment: Assignment,
) {
    selectedAssignment.value =
        assignment;

    releaseForm.reset();
    releaseForm.clearErrors();

    actionMode.value = 'release';
}

function submitRelease() {
    if (
        selectedAssignment.value ===
        null
    ) {
        return;
    }

    releaseForm.patch(
        `${BASE_URL}/asignaciones/${selectedAssignment.value.id}/liberar`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeAction();
            },
        },
    );
}

function closeAction() {
    actionMode.value = null;
    selectedAssignment.value =
        null;

    renewForm.reset();
    releaseForm.reset();

    renewForm.clearErrors();
    releaseForm.clearErrors();
}

function goToAccess(
    assignment: Assignment,
) {
    router.visit(
        `${BASE_URL}/acceso`,
        {
            data: {
                locker:
                assignment.locker_code,
            },
        },
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Asignaciones de lockers"
        subtitle="Administración, renovación y liberación de lockers"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    LOCKERS · MÓDULO 5.4
                </span>

                <h2>
                    Asignaciones
                </h2>

                <p>
                    Consulta lockers
                    asignados, realiza
                    renovaciones y administra
                    asignaciones otorgadas por
                    beca o Consejo
                    Estudiantil.
                </p>
            </div>

            <div class="hero-total">
                <span>
                    Asignaciones
                </span>

                <strong>
                    {{ summary.total }}
                </strong>

                <small>
                    registros totales
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Activas
                </span>

                <strong>
                    {{ summary.active }}
                </strong>

                <small>
                    Lockers en uso
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Liberadas
                </span>

                <strong>
                    {{ summary.released }}
                </strong>

                <small>
                    Finalizadas
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Expiradas
                </span>

                <strong>
                    {{ summary.expired }}
                </strong>

                <small>
                    Periodo vencido
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Lockers disponibles
                </span>

                <strong>
                    {{
                        available_lockers.length
                    }}
                </strong>

                <small>
                    Para nuevas asignaciones
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
                class="module-link"
            >
                Solicitudes
            </Link>

            <Link
                :href="`${BASE_URL}/asignaciones`"
                class="module-link active"
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
                        ASIGNACIONES
                    </span>

                    <h3>
                        Lockers asignados
                    </h3>

                    <p>
                        Control de asignaciones
                        activas e historial del
                        servicio.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="
                        openSponsoredForm
                    "
                >
                    + Asignación especial
                </button>
            </div>

            <section
                v-if="
                    showSponsoredForm
                "
                class="form-panel"
            >
                <div class="form-header">
                    <div>
                        <span class="panel-label">
                            BECA / CONSEJO
                        </span>

                        <h3>
                            Asignación especial
                        </h3>

                        <p>
                            Asigna un locker sin
                            requerir el flujo de
                            renta pagada.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeSponsoredForm
                        "
                    >
                        ×
                    </button>
                </div>

                <form
                    class="assignment-form"
                    @submit.prevent="
                        submitSponsored
                    "
                >
                    <div class="form-grid">
                        <div class="form-field">
                            <label>
                                ID del estudiante
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    sponsoredForm.student_id
                                "
                                type="text"
                                placeholder="Ej. EST-0001"
                            />

                            <small
                                v-if="
                                    sponsoredForm
                                        .errors
                                        .student_id
                                "
                                class="field-error"
                            >
                                {{
                                    sponsoredForm
                                        .errors
                                        .student_id
                                }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label>
                                Periodo
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    sponsoredForm.period_id
                                "
                            >
                                <option value="">
                                    Selecciona
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
                                    sponsoredForm
                                        .errors
                                        .period_id
                                "
                                class="field-error"
                            >
                                {{
                                    sponsoredForm
                                        .errors
                                        .period_id
                                }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label>
                                Tipo
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    sponsoredForm.request_type
                                "
                            >
                                <option
                                    value="scholarship"
                                >
                                    Beca
                                </option>

                                <option
                                    value="council"
                                >
                                    Consejo
                                    Estudiantil
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>
                                Tamaño
                            </label>

                            <select
                                v-model="
                                    sponsoredForm.size
                                "
                                :disabled="
                                    Boolean(
                                        sponsoredForm.locker_id,
                                    )
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
                                    sponsoredForm
                                        .errors
                                        .size
                                "
                                class="field-error"
                            >
                                {{
                                    sponsoredForm
                                        .errors
                                        .size
                                }}
                            </small>
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Locker específico
                            </label>

                            <select
                                v-model="
                                    sponsoredForm.locker_id
                                "
                            >
                                <option value="">
                                    Asignar
                                    automáticamente
                                </option>

                                <option
                                    v-for="
                                        locker in
                                        sponsoredAvailableLockers
                                    "
                                    :key="
                                        locker.id
                                    "
                                    :value="
                                        locker.id
                                    "
                                >
                                    {{
                                        locker.code
                                    }}
                                    ·
                                    {{
                                        locker.building
                                    }}
                                    ·
                                    {{
                                        locker.zone
                                    }}
                                    ·
                                    {{
                                        sizeLabel(
                                            locker.size,
                                        )
                                    }}
                                </option>
                            </select>

                            <small
                                v-if="
                                    sponsoredForm
                                        .errors
                                        .locker_id
                                "
                                class="field-error"
                            >
                                {{
                                    sponsoredForm
                                        .errors
                                        .locker_id
                                }}
                            </small>
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Referencia /
                                observación
                            </label>

                            <input
                                v-model="
                                    sponsoredForm.reference
                                "
                                type="text"
                                placeholder="Ej. BECA-2026-001"
                            />
                        </div>
                    </div>

                    <div
                        v-if="
                            sponsoredForm
                                .errors
                                .status
                        "
                        class="error-box"
                    >
                        {{
                            sponsoredForm
                                .errors
                                .status
                        }}
                    </div>

                    <div class="information-box">
                        Puedes seleccionar un
                        locker específico o
                        indicar solamente el
                        tamaño para que el
                        sistema elija uno
                        disponible
                        automáticamente.
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeSponsoredForm
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="primary-button"
                            :disabled="
                                sponsoredForm.processing
                            "
                        >
                            {{
                                sponsoredForm.processing
                                    ? 'Asignando...'
                                    : 'Crear asignación'
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
                        placeholder="Buscar por folio, estudiante, locker o periodo..."
                    />
                </div>

                <select
                    v-model="
                        sourceFilter
                    "
                >
                    <option value="">
                        Todos los tipos
                    </option>

                    <option value="paid">
                        Renta pagada
                    </option>

                    <option value="scholarship">
                        Beca
                    </option>

                    <option value="council">
                        Consejo
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

                    <option value="active">
                        Activas
                    </option>

                    <option value="released">
                        Liberadas
                    </option>

                    <option value="expired">
                        Expiradas
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredAssignments
                        .length > 0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Estudiante</th>
                        <th>Locker</th>
                        <th>Periodo</th>
                        <th>Origen</th>
                        <th>Vigencia</th>
                        <th>Renovaciones</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                assignment in
                                filteredAssignments
                            "
                        :key="
                                assignment.id
                            "
                    >
                        <td>
                            <strong class="folio">
                                {{
                                    assignment.folio
                                }}
                            </strong>
                        </td>

                        <td>
                                <span class="student">
                                    {{
                                        assignment.student_id
                                    }}
                                </span>
                        </td>

                        <td>
                            <div class="locker-info">
                                <strong>
                                    {{
                                        assignment.locker_code
                                    }}
                                </strong>

                                <small>
                                    {{
                                        assignment.locker_location
                                        ?? 'Sin ubicación'
                                    }}
                                </small>

                                <small>
                                    {{
                                        sizeLabel(
                                            assignment.locker_size,
                                        )
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                            {{
                                assignment.period_name
                            }}
                        </td>

                        <td>
                            {{
                                sourceLabel(
                                    assignment.source,
                                )
                            }}
                        </td>

                        <td>
                            <div class="dates">
                                    <span>
                                        {{
                                            formatDate(
                                                assignment.starts_at,
                                            )
                                        }}
                                    </span>

                                <small>
                                    hasta
                                    {{
                                        formatDate(
                                            assignment.ends_at,
                                        )
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                                <span class="renewals">
                                    {{
                                        assignment.renewal_count
                                    }}
                                </span>
                        </td>

                        <td>
                                <span
                                    class="status"
                                    :class="`status-${assignment.status}`"
                                >
                                    {{
                                        statusLabel(
                                            assignment.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <div class="actions">
                                <button
                                    v-if="
                                            assignment.status ===
                                            'active'
                                        "
                                    type="button"
                                    class="action-button renew"
                                    @click="
                                            openRenew(
                                                assignment,
                                            )
                                        "
                                >
                                    Renovar
                                </button>

                                <button
                                    v-if="
                                            assignment.status ===
                                            'active'
                                        "
                                    type="button"
                                    class="action-button access"
                                    @click="
                                            goToAccess(
                                                assignment,
                                            )
                                        "
                                >
                                    Acceso
                                </button>

                                <button
                                    v-if="
                                            assignment.status ===
                                            'active'
                                        "
                                    type="button"
                                    class="action-button release"
                                    @click="
                                            openRelease(
                                                assignment,
                                            )
                                        "
                                >
                                    Liberar
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
                    No hay asignaciones
                </h3>

                <p>
                    Cambia los filtros o
                    realiza una nueva
                    asignación.
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
                                'renew'
                                    ? 'RENOVAR LOCKER'
                                    : 'LIBERAR LOCKER'
                            }}
                        </span>

                        <h3>
                            {{
                                selectedAssignment
                                    ?.locker_code
                            }}
                        </h3>

                        <p>
                            {{
                                selectedAssignment
                                    ?.student_id
                            }}
                        </p>
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

                <form
                    v-if="
                        actionMode ===
                        'renew'
                    "
                    class="modal-body"
                    @submit.prevent="
                        submitRenew
                    "
                >
                    <div class="form-field">
                        <label>
                            Nuevo periodo
                            <span>*</span>
                        </label>

                        <select
                            v-model="
                                renewForm.period_id
                            "
                        >
                            <option value="">
                                Selecciona
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
                                :disabled="
                                    period.id ===
                                    selectedAssignment
                                        ?.period_id
                                "
                            >
                                {{
                                    period.name
                                }}
                            </option>
                        </select>

                        <small
                            v-if="
                                renewForm.errors
                                    .period_id
                            "
                            class="field-error"
                        >
                            {{
                                renewForm.errors
                                    .period_id
                            }}
                        </small>
                    </div>

                    <div
                        v-if="
                            selectedAssignment
                                ?.source ===
                            'paid'
                        "
                        class="form-field modal-field"
                    >
                        <label>
                            Referencia de pago
                            <span>*</span>
                        </label>

                        <input
                            v-model="
                                renewForm.payment_reference
                            "
                            type="text"
                            placeholder="Ej. PAY-REN-0001"
                        />

                        <small
                            v-if="
                                renewForm.errors
                                    .payment_reference
                            "
                            class="field-error"
                        >
                            {{
                                renewForm.errors
                                    .payment_reference
                            }}
                        </small>
                    </div>

                    <div
                        v-if="
                            renewForm.errors
                                .status
                        "
                        class="error-box"
                    >
                        {{
                            renewForm.errors
                                .status
                        }}
                    </div>

                    <div class="information-box">
                        El estudiante conservará
                        el mismo locker durante
                        el nuevo periodo.
                    </div>

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
                            type="submit"
                            class="primary-button"
                            :disabled="
                                renewForm.processing
                            "
                        >
                            Renovar
                        </button>
                    </div>
                </form>

                <form
                    v-else
                    class="modal-body"
                    @submit.prevent="
                        submitRelease
                    "
                >
                    <div class="form-field">
                        <label>
                            Motivo de liberación
                            <span>*</span>
                        </label>

                        <textarea
                            v-model="
                                releaseForm.reason
                            "
                            rows="4"
                            placeholder="Ej. Fin de uso del servicio"
                        />

                        <small
                            v-if="
                                releaseForm.errors
                                    .reason
                            "
                            class="field-error"
                        >
                            {{
                                releaseForm.errors
                                    .reason
                            }}
                        </small>
                    </div>

                    <div
                        v-if="
                            releaseForm.errors
                                .status
                        "
                        class="error-box"
                    >
                        {{
                            releaseForm.errors
                                .status
                        }}
                    </div>

                    <div class="information-box">
                        Al liberar la asignación,
                        el locker volverá
                        automáticamente al estado
                        <strong>
                            Disponible
                        </strong>.
                    </div>

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
                            type="submit"
                            class="danger-button"
                            :disabled="
                                releaseForm.processing
                            "
                        >
                            Liberar locker
                        </button>
                    </div>
                </form>
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
    min-width: 165px;
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
.form-header p,
.modal-header p {
    margin: 5px 0 0;
    color: #8794a7;
    font-size: 10px;
}

.primary-button,
.secondary-button,
.danger-button {
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

.danger-button {
    border: 1px solid #b84f59;
    background: #b84f59;
    color: white;
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

.assignment-form,
.modal-body {
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
    font-size: 11px;
}

.form-field textarea {
    resize: vertical;
}

.modal-field {
    margin-top: 15px;
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

.error-box {
    border: 1px solid #ebc6c6;
    background: #fceded;
    color: #a04444;
}

.form-actions,
.modal-actions {
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

.folio {
    color: #285aa6;
}

.student {
    color: #42546d;
    font-weight: 700;
}

.locker-info,
.dates {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.locker-info strong {
    color: #315fa6;
}

.locker-info small,
.dates small {
    color: #8b98aa;
    font-size: 8px;
}

.renewals {
    display: inline-flex;
    min-width: 25px;
    padding: 4px 7px;
    justify-content: center;
    border-radius: 6px;
    background: #f0f3f7;
    font-weight: 700;
}

.status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.status-active {
    background: #e4f6ec;
    color: #217a4e;
}

.status-released {
    background: #eaf0fc;
    color: #315fa6;
}

.status-expired {
    background: #f3e8e9;
    color: #98505a;
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

.action-button.renew {
    border: 1px solid #ead7aa;
    background: #fff8e6;
    color: #936814;
}

.action-button.access {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.release {
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
    width: min(520px, 100%);
    overflow: hidden;
    border-radius: 11px;
    background: white;
    box-shadow:
        0 18px 50px
        rgba(0, 0, 0, 0.18);
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

    .hero-total {
        width: 100%;
        box-sizing: border-box;
    }

    .panel-header,
    .form-header,
    .modal-header {
        align-items: stretch;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-field.full {
        grid-column: auto;
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
