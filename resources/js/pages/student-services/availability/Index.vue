<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import type { CalendarRules } from '@/lib/studentServicesCalendar';
import {
    formatTimeOfIso,
    minutesLabel,
    todayKey,
    WEEKDAY_LABELS,
} from '@/lib/studentServicesCalendar';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ResourceType = 'facility' | 'rest_space';

type WaitlistStatus = 'waiting' | 'promoted' | 'cancelled' | 'expired';

interface Resource {
    key: string;
    id: string;
    resource_type: ResourceType;
    resource_label: string;
    name: string;
    category: string;
    location: string;
    active: boolean;
    capacity: number;
    rules: CalendarRules;
}

interface CalendarBlock {
    id: string;
    folio: string;
    resource_key: string;
    resource_name: string;
    start_at: string;
    end_at: string;
    reason: string;
    cancelled_bookings: number;
}

interface WaitlistEntry {
    id: string;
    folio: string;
    resource_type: ResourceType;
    resource_key: string;
    resource_name: string;
    student_id: string;
    start_at: string;
    end_at: string;
    position: number;
    status: WaitlistStatus;
}

const props = defineProps<{
    resources: Resource[];
    blocks: CalendarBlock[];
    waitlist: WaitlistEntry[];
}>();

const activeTab = ref<'calendar' | 'rules' | 'waitlist'>('calendar');

const search = ref('');
const typeFilter = ref<'' | ResourceType>('');
const notice = ref<string | null>(null);

const activeResources = computed(
    () => props.resources.filter((resource) => resource.active).length,
);

const totalCapacity = computed(() =>
    props.resources.reduce((total, resource) => total + resource.capacity, 0),
);

const waitingCount = computed(
    () => props.waitlist.filter((item) => item.status === 'waiting').length,
);

const filteredResources = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.resources.filter((resource) => {
        if (typeFilter.value && resource.resource_type !== typeFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [resource.name, resource.category, resource.location].some(
            (value) => value.toLowerCase().includes(term),
        );
    });
});

function daysLabel(days: number[]): string {
    if (days.length === 7) {
        return 'Todos los días';
    }

    return days.map((day) => WEEKDAY_LABELS[day]).join(', ');
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function waitlistStatusLabel(status: WaitlistStatus): string {
    const labels: Record<WaitlistStatus, string> = {
        waiting: 'En espera',
        promoted: 'Promovida',
        cancelled: 'Cancelada',
        expired: 'Expirada',
    };

    return labels[status];
}

function firstError(errors: Record<string, string>, fallback: string): string {
    return Object.values(errors)[0] ?? fallback;
}

/*
|--------------------------------------------------------------------------
| Edición de cupos y reglas
|--------------------------------------------------------------------------
*/
const selectedResource = ref<Resource | null>(null);

const ruleForm = useForm({
    capacity: 1,
    open_time: '',
    close_time: '',
    slot_minutes: 30,
    min_booking_minutes: 30,
    max_booking_minutes: 60,
    cancel_before_minutes: 60,
    no_show_tolerance_minutes: 15,
    max_active_per_student: 1,
    max_advance_days: 7,
    max_no_shows: 3,
    no_show_window_days: 30,
    operating_days: [] as number[],
});

const ruleErrors = computed(() => ruleForm.errors as Record<string, string>);

function openRuleEditor(resource: Resource) {
    selectedResource.value = resource;

    ruleForm.clearErrors();
    ruleForm.capacity = resource.capacity;
    ruleForm.open_time = resource.rules.open_time;
    ruleForm.close_time = resource.rules.close_time;
    ruleForm.slot_minutes = resource.rules.slot_minutes;
    ruleForm.min_booking_minutes = resource.rules.min_booking_minutes;
    ruleForm.max_booking_minutes = resource.rules.max_booking_minutes;
    ruleForm.cancel_before_minutes = resource.rules.cancel_before_minutes;
    ruleForm.no_show_tolerance_minutes =
        resource.rules.no_show_tolerance_minutes;
    ruleForm.max_active_per_student = resource.rules.max_active_per_student;
    ruleForm.max_advance_days = resource.rules.max_advance_days;
    ruleForm.max_no_shows = resource.rules.max_no_shows;
    ruleForm.no_show_window_days = resource.rules.no_show_window_days;
    ruleForm.operating_days = [...resource.rules.operating_days];
}

function closeRuleEditor() {
    selectedResource.value = null;
    ruleForm.clearErrors();
}

function toggleDay(day: number) {
    ruleForm.operating_days = ruleForm.operating_days.includes(day)
        ? ruleForm.operating_days.filter((value) => value !== day)
        : [...ruleForm.operating_days, day].sort();
}

function saveRule() {
    if (!selectedResource.value) {
        return;
    }

    const resource = selectedResource.value;

    ruleForm.patch(
        `/servicios-estudiante/calendarios-cupos/${resource.resource_type}/${resource.id}/reglas`,
        {
            preserveScroll: true,
            onSuccess: () => {
                notice.value = `Reglas de ${resource.name} actualizadas.`;
                closeRuleEditor();
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Bloqueos de calendario
|--------------------------------------------------------------------------
*/
const showBlockModal = ref(false);
const blockResourceKey = ref('');

const blockForm = useForm({
    resource_type: '' as '' | ResourceType,
    resource_id: '',
    date: '',
    start_time: '',
    end_time: '',
    reason: '',
});

const blockErrors = computed(() => blockForm.errors as Record<string, string>);

function openBlockForm() {
    blockForm.reset();
    blockForm.clearErrors();
    blockResourceKey.value = '';
    blockForm.date = todayKey();
    showBlockModal.value = true;
}

function closeBlockForm() {
    showBlockModal.value = false;
}

function createBlock() {
    const resource = props.resources.find(
        (item) => item.key === blockResourceKey.value,
    );

    blockForm.resource_type = resource?.resource_type ?? '';
    blockForm.resource_id = resource?.id ?? '';

    blockForm.post('/servicios-estudiante/calendarios-cupos/bloqueos', {
        preserveScroll: true,
        onSuccess: () => {
            notice.value = `Bloqueo creado para ${resource?.name}. Las reservas dentro del horario se cancelaron automáticamente.`;
            closeBlockForm();
        },
    });
}

function removeBlock(block: CalendarBlock) {
    if (!window.confirm(`¿Eliminar el bloqueo de ${block.resource_name}?`)) {
        return;
    }

    router.delete(
        `/servicios-estudiante/calendarios-cupos/bloqueos/${block.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                notice.value = 'Bloqueo eliminado.';
            },
            onError: (errors) => {
                window.alert(
                    firstError(errors, 'No fue posible eliminar el bloqueo.'),
                );
            },
        },
    );
}

/*
|--------------------------------------------------------------------------
| Lista de espera
|--------------------------------------------------------------------------
*/
const processingWaitlistId = ref<string | null>(null);

function waitlistAction(item: WaitlistEntry, action: 'promover' | 'cancelar') {
    if (
        action === 'cancelar' &&
        !window.confirm(`¿Cancelar la solicitud ${item.folio}?`)
    ) {
        return;
    }

    processingWaitlistId.value = item.id;

    router.patch(
        `/servicios-estudiante/calendarios-cupos/espera/${item.resource_type}/${item.id}/${action}`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                notice.value =
                    action === 'promover'
                        ? `Solicitud ${item.folio} promovida a confirmada.`
                        : `Solicitud ${item.folio} cancelada.`;
            },
            onError: (errors) => {
                window.alert(
                    firstError(errors, 'No fue posible completar la acción.'),
                );
            },
            onFinish: () => {
                processingWaitlistId.value = null;
            },
        },
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Calendarios, cupos y reglas"
        subtitle="Motor común de disponibilidad de servicios"
    >
        <section class="hero">
            <div>
                <span class="hero-label"> SERVICIOS · MÓDULO 5.10 </span>

                <h2>Disponibilidad y reglas</h2>

                <p>
                    Administra horarios, capacidades, límites, bloqueos,
                    cancelaciones y listas de espera de los servicios del
                    campus. Instalaciones (5.5) y zonas de descanso (5.6) usan
                    estas reglas.
                </p>
            </div>

            <div class="hero-total">
                <span>Recursos activos</span>
                <strong>{{ activeResources }}</strong>
                <small>configurados</small>
            </div>
        </section>

        <div v-if="notice" class="page-notice">
            <span>{{ notice }}</span>
            <button type="button" @click="notice = null">×</button>
        </div>

        <section class="stats-grid">
            <article class="stat-card">
                <span>Recursos activos</span>
                <strong>{{ activeResources }}</strong>
                <small>Con calendario</small>
            </article>

            <article class="stat-card">
                <span>Capacidad total</span>
                <strong>{{ totalCapacity }}</strong>
                <small>Lugares simultáneos</small>
            </article>

            <article class="stat-card">
                <span>Bloqueos</span>
                <strong>{{ blocks.length }}</strong>
                <small>Vigentes o próximos</small>
            </article>

            <article class="stat-card">
                <span>Lista de espera</span>
                <strong>{{ waitingCount }}</strong>
                <small>Solicitudes esperando</small>
            </article>
        </section>

        <section class="tabs">
            <button
                type="button"
                :class="{ active: activeTab === 'calendar' }"
                @click="activeTab = 'calendar'"
            >
                Calendarios
            </button>

            <button
                type="button"
                :class="{ active: activeTab === 'rules' }"
                @click="activeTab = 'rules'"
            >
                Cupos y reglas
            </button>

            <button
                type="button"
                :class="{ active: activeTab === 'waitlist' }"
                @click="activeTab = 'waitlist'"
            >
                Lista de espera
            </button>
        </section>

        <section v-if="activeTab === 'calendar'" class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">CALENDARIO</span>

                    <h3>Bloqueos de disponibilidad</h3>

                    <p>
                        Registra mantenimiento, eventos o periodos en los que un
                        recurso no podrá reservarse. Las reservas que caigan
                        dentro del bloqueo se cancelan automáticamente.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openBlockForm"
                >
                    + Bloquear horario
                </button>
            </div>

            <div class="resource-summary">
                <article v-for="resource in resources" :key="resource.key">
                    <strong>{{ resource.name }}</strong>

                    <span>
                        {{ resource.rules.open_time }} -
                        {{ resource.rules.close_time }}
                    </span>

                    <small>
                        {{ resource.resource_label }} · franja
                        {{ resource.rules.slot_minutes }} min
                    </small>
                </article>
            </div>

            <div v-if="blocks.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Recurso</th>
                            <th>Fecha</th>
                            <th>Horario</th>
                            <th>Motivo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="block in blocks" :key="block.id">
                            <td>
                                <strong class="folio">
                                    {{ block.folio }}
                                </strong>
                            </td>

                            <td>
                                <strong class="resource-name">
                                    {{ block.resource_name }}
                                </strong>
                            </td>

                            <td>{{ formatDate(block.start_at) }}</td>

                            <td>
                                {{ formatTimeOfIso(block.start_at) }} -
                                {{ formatTimeOfIso(block.end_at) }}
                            </td>

                            <td>
                                {{ block.reason }}
                                <small
                                    v-if="block.cancelled_bookings > 0"
                                    class="block-note"
                                >
                                    {{ block.cancelled_bookings }} reserva(s)
                                    cancelada(s)
                                </small>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="danger-action"
                                    @click="removeBlock(block)"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                No existen bloqueos de calendario vigentes.
            </div>
        </section>

        <section v-if="activeTab === 'rules'" class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">CONFIGURACIÓN</span>

                    <h3>Cupos y reglas</h3>

                    <p>Define cómo se utiliza cada recurso.</p>
                </div>
            </div>

            <div class="filters">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar recurso..."
                />

                <select v-model="typeFilter" class="type-filter">
                    <option value="">Todos los servicios</option>
                    <option value="facility">Instalaciones (5.5)</option>
                    <option value="rest_space">Zonas de descanso (5.6)</option>
                </select>
            </div>

            <div class="rules-grid">
                <article
                    v-for="resource in filteredResources"
                    :key="resource.key"
                    class="rule-card"
                >
                    <div class="rule-heading">
                        <div>
                            <span class="resource-type">
                                {{ resource.resource_label }} ·
                                {{ resource.category }}
                            </span>

                            <h4>{{ resource.name }}</h4>

                            <p>{{ resource.location }}</p>
                        </div>

                        <span
                            class="status-badge"
                            :class="{ inactive: !resource.active }"
                        >
                            {{ resource.active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>

                    <div class="rule-values">
                        <div>
                            <span>Capacidad</span>
                            <strong>{{ resource.capacity }}</strong>
                        </div>

                        <div>
                            <span>Horario</span>
                            <strong>
                                {{ resource.rules.open_time }} -
                                {{ resource.rules.close_time }}
                            </strong>
                        </div>

                        <div>
                            <span>Franjas</span>
                            <strong>
                                {{ resource.rules.slot_minutes }} min
                            </strong>
                        </div>

                        <div>
                            <span>Duración</span>
                            <strong>
                                {{
                                    minutesLabel(
                                        resource.rules.min_booking_minutes,
                                    )
                                }}
                                –
                                {{
                                    minutesLabel(
                                        resource.rules.max_booking_minutes,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>Cancelación mínima</span>
                            <strong>
                                {{
                                    minutesLabel(
                                        resource.rules.cancel_before_minutes,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>Tolerancia no-show</span>
                            <strong>
                                {{ resource.rules.no_show_tolerance_minutes }}
                                min
                            </strong>
                        </div>

                        <div>
                            <span>Reservas activas por alumno</span>
                            <strong>
                                {{ resource.rules.max_active_per_student }}
                            </strong>
                        </div>

                        <div>
                            <span>Anticipación máxima</span>
                            <strong>
                                {{ resource.rules.max_advance_days }} día(s)
                            </strong>
                        </div>

                        <div>
                            <span>Penalización</span>
                            <strong>
                                {{ resource.rules.max_no_shows }} no-show /
                                {{ resource.rules.no_show_window_days }} días
                            </strong>
                        </div>

                        <div>
                            <span>Días</span>
                            <strong>
                                {{ daysLabel(resource.rules.operating_days) }}
                            </strong>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="edit-button"
                        @click="openRuleEditor(resource)"
                    >
                        Editar configuración
                    </button>
                </article>
            </div>
        </section>

        <section v-if="activeTab === 'waitlist'" class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">LISTA DE ESPERA</span>

                    <h3>Solicitudes en espera</h3>

                    <p>
                        Cuando se libera un espacio la siguiente solicitud se
                        promueve sola; aquí también puedes promoverla
                        manualmente si ya hay cupo.
                    </p>
                </div>
            </div>

            <div v-if="waitlist.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Recurso</th>
                            <th>Estudiante</th>
                            <th>Fecha</th>
                            <th>Horario</th>
                            <th>Posición</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="item in waitlist" :key="item.id">
                            <td>
                                <strong class="folio">{{ item.folio }}</strong>
                            </td>

                            <td>{{ item.resource_name }}</td>

                            <td>
                                <small class="student-ref">
                                    {{ item.student_id }}
                                </small>
                            </td>

                            <td>{{ formatDate(item.start_at) }}</td>

                            <td>
                                {{ formatTimeOfIso(item.start_at) }} -
                                {{ formatTimeOfIso(item.end_at) }}
                            </td>

                            <td>
                                {{ item.position ? `#${item.position}` : '—' }}
                            </td>

                            <td>
                                <span
                                    class="wait-status"
                                    :class="`wait-${item.status}`"
                                >
                                    {{ waitlistStatusLabel(item.status) }}
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        v-if="item.status === 'waiting'"
                                        type="button"
                                        class="promote-button"
                                        :disabled="
                                            processingWaitlistId === item.id
                                        "
                                        @click="
                                            waitlistAction(item, 'promover')
                                        "
                                    >
                                        Promover
                                    </button>

                                    <button
                                        v-if="item.status === 'waiting'"
                                        type="button"
                                        class="danger-action"
                                        :disabled="
                                            processingWaitlistId === item.id
                                        "
                                        @click="
                                            waitlistAction(item, 'cancelar')
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

            <div v-else class="empty-state">
                No hay solicitudes en lista de espera en los últimos 7 días.
            </div>
        </section>

        <div
            v-if="selectedResource"
            class="modal-backdrop"
            @click.self="closeRuleEditor"
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">CONFIGURACIÓN</span>

                        <h3>{{ selectedResource.name }}</h3>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeRuleEditor"
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>Capacidad simultánea</label>
                            <input
                                v-model.number="ruleForm.capacity"
                                type="number"
                                min="1"
                            />
                        </div>

                        <div class="form-field">
                            <label>Duración de franja (min)</label>
                            <input
                                v-model.number="ruleForm.slot_minutes"
                                type="number"
                                min="5"
                                step="5"
                            />
                        </div>

                        <div class="form-field">
                            <label>Hora de apertura</label>
                            <input v-model="ruleForm.open_time" type="time" />
                        </div>

                        <div class="form-field">
                            <label>Hora de cierre</label>
                            <input v-model="ruleForm.close_time" type="time" />
                        </div>

                        <div class="form-field">
                            <label>Mínimo por reserva (min)</label>
                            <input
                                v-model.number="ruleForm.min_booking_minutes"
                                type="number"
                                min="5"
                            />
                        </div>

                        <div class="form-field">
                            <label>Máximo por reserva (min)</label>
                            <input
                                v-model.number="ruleForm.max_booking_minutes"
                                type="number"
                                min="5"
                            />
                        </div>

                        <div class="form-field">
                            <label>Cancelación mínima (min antes)</label>
                            <input
                                v-model.number="ruleForm.cancel_before_minutes"
                                type="number"
                                min="0"
                            />
                        </div>

                        <div class="form-field">
                            <label>Tolerancia no-show (min)</label>
                            <input
                                v-model.number="
                                    ruleForm.no_show_tolerance_minutes
                                "
                                type="number"
                                min="0"
                            />
                        </div>

                        <div class="form-field">
                            <label>Reservas activas por alumno</label>
                            <input
                                v-model.number="ruleForm.max_active_per_student"
                                type="number"
                                min="1"
                            />
                        </div>

                        <div class="form-field">
                            <label>Anticipación máxima (días)</label>
                            <input
                                v-model.number="ruleForm.max_advance_days"
                                type="number"
                                min="0"
                            />
                        </div>

                        <div class="form-field">
                            <label>No-shows permitidos</label>
                            <input
                                v-model.number="ruleForm.max_no_shows"
                                type="number"
                                min="0"
                            />
                        </div>

                        <div class="form-field">
                            <label>Ventana de penalización (días)</label>
                            <input
                                v-model.number="ruleForm.no_show_window_days"
                                type="number"
                                min="1"
                            />
                        </div>

                        <div class="form-field full">
                            <label>Días de operación</label>

                            <div class="day-toggles">
                                <button
                                    v-for="day in [1, 2, 3, 4, 5, 6, 7]"
                                    :key="day"
                                    type="button"
                                    :class="{
                                        active: ruleForm.operating_days.includes(
                                            day,
                                        ),
                                    }"
                                    @click="toggleDay(day)"
                                >
                                    {{ WEEKDAY_LABELS[day] }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="Object.keys(ruleErrors).length > 0"
                        class="form-error"
                    >
                        {{ Object.values(ruleErrors)[0] }}
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="closeRuleEditor"
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="ruleForm.processing"
                            @click="saveRule"
                        >
                            Guardar cambios
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="showBlockModal"
            class="modal-backdrop"
            @click.self="closeBlockForm"
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">BLOQUEO</span>

                        <h3>Bloquear horario</h3>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeBlockForm"
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field full">
                            <label>Recurso</label>

                            <select v-model="blockResourceKey">
                                <option value="">Selecciona</option>

                                <option
                                    v-for="resource in resources"
                                    :key="resource.key"
                                    :value="resource.key"
                                >
                                    {{ resource.resource_label }} ·
                                    {{ resource.name }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field full">
                            <label>Fecha</label>
                            <input
                                v-model="blockForm.date"
                                type="date"
                                :min="todayKey()"
                            />
                        </div>

                        <div class="form-field">
                            <label>Inicio</label>
                            <input v-model="blockForm.start_time" type="time" />
                        </div>

                        <div class="form-field">
                            <label>Fin</label>
                            <input v-model="blockForm.end_time" type="time" />
                        </div>

                        <div class="form-field full">
                            <label>Motivo</label>
                            <textarea
                                v-model="blockForm.reason"
                                rows="3"
                                placeholder="Ej. Mantenimiento preventivo..."
                            />
                        </div>
                    </div>

                    <p
                        v-if="Object.keys(blockErrors).length > 0"
                        class="form-error"
                    >
                        {{ Object.values(blockErrors)[0] }}
                    </p>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="closeBlockForm"
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="blockForm.processing"
                            @click="createBlock"
                        >
                            Crear bloqueo
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
    min-width: 170px;
    padding: 16px 19px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-total span {
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
    color: #97a3b5;
    font-size: 9px;
}

.tabs {
    margin-top: 18px;
    padding: 6px;
    display: flex;
    gap: 5px;
    border: 1px solid #dfe5ee;
    border-radius: 10px;
    background: white;
}

.tabs button {
    min-height: 36px;
    padding: 0 14px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #697a91;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.tabs button.active {
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

.panel-header p {
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

.resource-summary {
    padding: 18px 21px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 11px;
}

.resource-summary article {
    padding: 13px;
    border: 1px solid #e1e6ee;
    border-radius: 8px;
    background: #fafcff;
}

.resource-summary strong {
    display: block;
    color: #405069;
    font-size: 10px;
}

.resource-summary span,
.resource-summary small {
    display: block;
    margin-top: 5px;
    color: #7d8ca1;
    font-size: 8px;
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
}

td {
    padding: 14px;
    border-top: 1px solid #e9edf3;
    color: #5c6980;
    font-size: 10px;
}

.resource-name,
.folio {
    color: #285aa6;
}

.actions {
    display: flex;
    gap: 5px;
}

.danger-action,
.promote-button {
    min-height: 29px;
    padding: 0 9px;
    border-radius: 6px;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.danger-action {
    border: 1px solid #e6c9cd;
    background: #fbebed;
    color: #9d4650;
}

.promote-button {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
}

.filters {
    padding: 14px 21px;
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.filters input {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    font: inherit;
    font-size: 10px;
}

.rules-grid {
    padding: 18px 21px 21px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
    gap: 13px;
}

.rule-card {
    padding: 16px;
    border: 1px solid #e0e6ee;
    border-radius: 9px;
}

.rule-heading {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.resource-type {
    color: #315fa6;
    font-size: 8px;
    font-weight: 800;
}

.rule-heading h4 {
    margin: 4px 0 0;
    color: #34435a;
    font-size: 12px;
}

.rule-heading p {
    margin: 4px 0 0;
    color: #8b98aa;
    font-size: 8px;
}

.status-badge {
    height: fit-content;
    padding: 5px 8px;
    border-radius: 999px;
    background: #e4f6ec;
    color: #217a4e;
    font-size: 8px;
    font-weight: 800;
}

.rule-values {
    margin-top: 15px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 7px;
}

.rule-values div {
    padding: 9px;
    border-radius: 6px;
    background: #f6f8fb;
}

.rule-values span {
    display: block;
    color: #8b98aa;
    font-size: 8px;
}

.rule-values strong {
    display: block;
    margin-top: 4px;
    color: #465870;
    font-size: 9px;
}

.edit-button {
    width: 100%;
    min-height: 34px;
    margin-top: 13px;
    border: 1px solid #c8d8ee;
    border-radius: 6px;
    background: #edf3fc;
    color: #2c5c9f;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.wait-status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.wait-waiting {
    background: #fff3d7;
    color: #946510;
}

.wait-promoted {
    background: #e4f6ec;
    color: #217a4e;
}

.wait-cancelled {
    background: #f4e7e8;
    color: #9c4c55;
}

.empty-state {
    padding: 45px;
    color: #8d99aa;
    text-align: center;
    font-size: 10px;
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

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    background: white;
    font: inherit;
    font-size: 11px;
}

.modal-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid #e5e9ef;
}

@media (max-width: 850px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header,
    .modal-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid,
    .rule-values {
        grid-template-columns: 1fr;
    }

    .form-field.full {
        grid-column: auto;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .tabs {
        flex-direction: column;
    }
}
</style>
<style scoped>
/* Estilos agregados al conectar el panel con el backend (5.10) */
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

.filters {
    display: flex;
    gap: 10px;
}

.type-filter {
    min-width: 190px;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    background: #fff;
    font: inherit;
    font-size: 10px;
}

.status-badge.inactive {
    background: #f4e7e8;
    color: #9c4c55;
}

.block-note,
.student-ref {
    display: block;
    margin-top: 3px;
    color: #8c99aa;
    font-size: 8px;
}

.wait-expired {
    background: #eef1f5;
    color: #5b6778;
}

.actions button:disabled {
    opacity: 0.55;
    cursor: wait;
}

.day-toggles {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.day-toggles button {
    padding: 7px 10px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    background: #fff;
    color: #5b6778;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.day-toggles button.active {
    border-color: #2d57ac;
    background: #2d57ac;
    color: #fff;
}

.form-error {
    margin-top: 10px;
    color: #9d4650;
    font-size: 9px;
    font-weight: 700;
}
</style>
