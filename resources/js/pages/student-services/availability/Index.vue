<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { computed, ref } from 'vue';

type ResourceStatus =
    | 'active'
    | 'inactive';

type WaitlistStatus =
    | 'waiting'
    | 'promoted'
    | 'cancelled';

interface ResourceRule {
    id: number;
    name: string;
    type: string;
    location: string;
    capacity: number;
    openTime: string;
    closeTime: string;
    slotMinutes: number;
    maxBookingMinutes: number;
    cancelBeforeMinutes: number;
    noShowToleranceMinutes: number;
    status: ResourceStatus;
}

interface CalendarBlock {
    id: number;
    resourceId: number;
    resourceName: string;
    date: string;
    startTime: string;
    endTime: string;
    reason: string;
}

interface WaitlistEntry {
    id: number;
    folio: string;
    resourceId: number;
    resourceName: string;
    date: string;
    startTime: string;
    endTime: string;
    position: number;
    status: WaitlistStatus;
}

const resources = ref<ResourceRule[]>([
    {
        id: 1,
        name: 'Sala de estudio A',
        type: 'Sala de estudio',
        location: 'Biblioteca · Piso 1',
        capacity: 8,
        openTime: '07:30',
        closeTime: '18:30',
        slotMinutes: 30,
        maxBookingMinutes: 120,
        cancelBeforeMinutes: 60,
        noShowToleranceMinutes: 15,
        status: 'active',
    },
    {
        id: 2,
        name: 'Laboratorio de cómputo',
        type: 'Laboratorio',
        location: 'Edificio A · Piso 2',
        capacity: 25,
        openTime: '08:00',
        closeTime: '18:00',
        slotMinutes: 60,
        maxBookingMinutes: 180,
        cancelBeforeMinutes: 120,
        noShowToleranceMinutes: 15,
        status: 'active',
    },
    {
        id: 3,
        name: 'Auditorio principal',
        type: 'Auditorio',
        location: 'Edificio B',
        capacity: 120,
        openTime: '08:00',
        closeTime: '20:00',
        slotMinutes: 60,
        maxBookingMinutes: 240,
        cancelBeforeMinutes: 1440,
        noShowToleranceMinutes: 30,
        status: 'active',
    },
    {
        id: 4,
        name: 'Cancha multiusos',
        type: 'Instalación deportiva',
        location: 'Zona deportiva',
        capacity: 20,
        openTime: '07:00',
        closeTime: '19:00',
        slotMinutes: 60,
        maxBookingMinutes: 120,
        cancelBeforeMinutes: 120,
        noShowToleranceMinutes: 15,
        status: 'active',
    },
]);

const blocks = ref<CalendarBlock[]>([
    {
        id: 1,
        resourceId: 1,
        resourceName: 'Sala de estudio A',
        date: '2026-10-02',
        startTime: '12:00',
        endTime: '14:00',
        reason: 'Mantenimiento preventivo',
    },
    {
        id: 2,
        resourceId: 3,
        resourceName: 'Auditorio principal',
        date: '2026-10-04',
        startTime: '08:00',
        endTime: '13:00',
        reason: 'Evento institucional',
    },
]);

const waitlist = ref<WaitlistEntry[]>([
    {
        id: 1,
        folio: 'ESP-2026-001',
        resourceId: 1,
        resourceName: 'Sala de estudio A',
        date: '2026-10-01',
        startTime: '10:00',
        endTime: '11:00',
        position: 1,
        status: 'waiting',
    },
    {
        id: 2,
        folio: 'ESP-2026-002',
        resourceId: 1,
        resourceName: 'Sala de estudio A',
        date: '2026-10-01',
        startTime: '10:00',
        endTime: '11:00',
        position: 2,
        status: 'waiting',
    },
    {
        id: 3,
        folio: 'ESP-2026-003',
        resourceId: 3,
        resourceName: 'Auditorio principal',
        date: '2026-10-05',
        startTime: '16:00',
        endTime: '18:00',
        position: 1,
        status: 'promoted',
    },
]);

const activeTab = ref<
    'calendar' | 'rules' | 'waitlist'
>('calendar');

const search = ref('');

const showRuleModal = ref(false);
const selectedResource =
    ref<ResourceRule | null>(null);

const editCapacity = ref(1);
const editOpenTime = ref('');
const editCloseTime = ref('');
const editSlotMinutes = ref(30);
const editMaxBookingMinutes = ref(60);
const editCancelBeforeMinutes = ref(60);
const editNoShowTolerance = ref(15);

const showBlockModal = ref(false);
const blockResourceId = ref('');
const blockDate = ref('');
const blockStart = ref('');
const blockEnd = ref('');
const blockReason = ref('');

const activeResources = computed(
    () =>
        resources.value.filter(
            (resource) =>
                resource.status ===
                'active',
        ).length,
);

const totalCapacity = computed(
    () =>
        resources.value.reduce(
            (total, resource) =>
                total +
                resource.capacity,
            0,
        ),
);

const activeBlocks = computed(
    () => blocks.value.length,
);

const waitingCount = computed(
    () =>
        waitlist.value.filter(
            (item) =>
                item.status ===
                'waiting',
        ).length,
);

const filteredResources =
    computed(() => {
        const term =
            search.value
                .trim()
                .toLowerCase();

        if (!term) {
            return resources.value;
        }

        return resources.value.filter(
            (resource) =>
                [
                    resource.name,
                    resource.type,
                    resource.location,
                ].some((value) =>
                    value
                        .toLowerCase()
                        .includes(term),
                ),
        );
    });

function formatDate(
    value: string,
): string {
    const parts =
        value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function minutesLabel(
    value: number,
): string {
    if (value >= 1440) {
        return `${value / 1440} día`;
    }

    if (value >= 60) {
        const hours =
            value / 60;

        return `${hours} h`;
    }

    return `${value} min`;
}

function waitlistStatusLabel(
    status: WaitlistStatus,
): string {
    const labels: Record<
        WaitlistStatus,
        string
    > = {
        waiting: 'En espera',
        promoted: 'Promovida',
        cancelled: 'Cancelada',
    };

    return labels[status];
}

function openRuleEditor(
    resource: ResourceRule,
) {
    selectedResource.value =
        resource;

    editCapacity.value =
        resource.capacity;

    editOpenTime.value =
        resource.openTime;

    editCloseTime.value =
        resource.closeTime;

    editSlotMinutes.value =
        resource.slotMinutes;

    editMaxBookingMinutes.value =
        resource.maxBookingMinutes;

    editCancelBeforeMinutes.value =
        resource.cancelBeforeMinutes;

    editNoShowTolerance.value =
        resource.noShowToleranceMinutes;

    showRuleModal.value = true;
}

function saveRule() {
    if (
        selectedResource.value === null
    ) {
        return;
    }

    if (
        editCapacity.value < 1 ||
        editSlotMinutes.value < 1 ||
        editMaxBookingMinutes.value < 1
    ) {
        window.alert(
            'Revisa los valores de capacidad y tiempos.',
        );

        return;
    }

    selectedResource.value.capacity =
        editCapacity.value;

    selectedResource.value.openTime =
        editOpenTime.value;

    selectedResource.value.closeTime =
        editCloseTime.value;

    selectedResource.value.slotMinutes =
        editSlotMinutes.value;

    selectedResource.value.maxBookingMinutes =
        editMaxBookingMinutes.value;

    selectedResource.value.cancelBeforeMinutes =
        editCancelBeforeMinutes.value;

    selectedResource.value.noShowToleranceMinutes =
        editNoShowTolerance.value;

    closeRuleEditor();
}

function closeRuleEditor() {
    selectedResource.value = null;
    showRuleModal.value = false;
}

function openBlockForm() {
    blockResourceId.value = '';
    blockDate.value = '';
    blockStart.value = '';
    blockEnd.value = '';
    blockReason.value = '';

    showBlockModal.value = true;
}

function closeBlockForm() {
    showBlockModal.value = false;
}

function createBlock() {
    if (
        !blockResourceId.value ||
        !blockDate.value ||
        !blockStart.value ||
        !blockEnd.value ||
        !blockReason.value.trim()
    ) {
        window.alert(
            'Completa todos los datos del bloqueo.',
        );

        return;
    }

    const resource =
        resources.value.find(
            (item) =>
                item.id ===
                Number(
                    blockResourceId.value,
                ),
        );

    if (!resource) {
        return;
    }

    blocks.value.unshift({
        id: blocks.value.length + 1,
        resourceId: resource.id,
        resourceName: resource.name,
        date: blockDate.value,
        startTime: blockStart.value,
        endTime: blockEnd.value,
        reason:
            blockReason.value.trim(),
    });

    closeBlockForm();
}

function removeBlock(
    block: CalendarBlock,
) {
    if (
        !window.confirm(
            `¿Eliminar el bloqueo de ${block.resourceName}?`,
        )
    ) {
        return;
    }

    blocks.value =
        blocks.value.filter(
            (item) =>
                item.id !== block.id,
        );
}

function promoteWaitlist(
    item: WaitlistEntry,
) {
    if (
        item.status !== 'waiting'
    ) {
        return;
    }

    item.status = 'promoted';
}

function cancelWaitlist(
    item: WaitlistEntry,
) {
    if (
        item.status !== 'waiting'
    ) {
        return;
    }

    item.status = 'cancelled';
}
</script>

<template>
    <StudentServicesLayout
        title="Calendarios, cupos y reglas"
        subtitle="Motor común de disponibilidad de servicios"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    SERVICIOS · MÓDULO 5.10
                </span>

                <h2>
                    Disponibilidad y reglas
                </h2>

                <p>
                    Administra horarios,
                    capacidades, límites,
                    bloqueos, cancelaciones y
                    listas de espera de los
                    servicios del campus.
                </p>
            </div>

            <div class="hero-total">
                <span>
                    Recursos activos
                </span>

                <strong>
                    {{ activeResources }}
                </strong>

                <small>
                    configurados
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Recursos activos
                </span>

                <strong>
                    {{ activeResources }}
                </strong>

                <small>
                    Con calendario
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Capacidad total
                </span>

                <strong>
                    {{ totalCapacity }}
                </strong>

                <small>
                    Lugares disponibles
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Bloqueos
                </span>

                <strong>
                    {{ activeBlocks }}
                </strong>

                <small>
                    Franjas no disponibles
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Lista de espera
                </span>

                <strong>
                    {{ waitingCount }}
                </strong>

                <small>
                    Solicitudes esperando
                </small>
            </article>
        </section>

        <section class="tabs">
            <button
                type="button"
                :class="{
                    active:
                        activeTab ===
                        'calendar',
                }"
                @click="
                    activeTab =
                        'calendar'
                "
            >
                Calendarios
            </button>

            <button
                type="button"
                :class="{
                    active:
                        activeTab ===
                        'rules',
                }"
                @click="
                    activeTab = 'rules'
                "
            >
                Cupos y reglas
            </button>

            <button
                type="button"
                :class="{
                    active:
                        activeTab ===
                        'waitlist',
                }"
                @click="
                    activeTab =
                        'waitlist'
                "
            >
                Lista de espera
            </button>
        </section>

        <section
            v-if="
                activeTab ===
                'calendar'
            "
            class="content-panel"
        >
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        CALENDARIO
                    </span>

                    <h3>
                        Bloqueos de disponibilidad
                    </h3>

                    <p>
                        Registra mantenimiento,
                        eventos o periodos en
                        los que un recurso no
                        podrá reservarse.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="
                        openBlockForm
                    "
                >
                    + Bloquear horario
                </button>
            </div>

            <div class="resource-summary">
                <article
                    v-for="
                        resource in
                        resources
                    "
                    :key="
                        resource.id
                    "
                >
                    <strong>
                        {{
                            resource.name
                        }}
                    </strong>

                    <span>
                        {{
                            resource.openTime
                        }}
                        -
                        {{
                            resource.closeTime
                        }}
                    </span>

                    <small>
                        Franja:
                        {{
                            resource.slotMinutes
                        }}
                        min
                    </small>
                </article>
            </div>

            <div
                v-if="
                    blocks.length > 0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>Recurso</th>
                        <th>Fecha</th>
                        <th>Horario</th>
                        <th>Motivo</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                block in
                                blocks
                            "
                        :key="
                                block.id
                            "
                    >
                        <td>
                            <strong
                                class="resource-name"
                            >
                                {{
                                    block.resourceName
                                }}
                            </strong>
                        </td>

                        <td>
                            {{
                                formatDate(
                                    block.date,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                block.startTime
                            }}
                            -
                            {{
                                block.endTime
                            }}
                        </td>

                        <td>
                            {{
                                block.reason
                            }}
                        </td>

                        <td>
                            <button
                                type="button"
                                class="danger-action"
                                @click="
                                        removeBlock(
                                            block,
                                        )
                                    "
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="empty-state"
            >
                No existen bloqueos de
                calendario.
            </div>
        </section>

        <section
            v-if="
                activeTab ===
                'rules'
            "
            class="content-panel"
        >
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        CONFIGURACIÓN
                    </span>

                    <h3>
                        Cupos y reglas
                    </h3>

                    <p>
                        Define cómo se utiliza
                        cada recurso.
                    </p>
                </div>
            </div>

            <div class="filters">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar recurso..."
                />
            </div>

            <div class="rules-grid">
                <article
                    v-for="
                        resource in
                        filteredResources
                    "
                    :key="
                        resource.id
                    "
                    class="rule-card"
                >
                    <div class="rule-heading">
                        <div>
                            <span
                                class="resource-type"
                            >
                                {{
                                    resource.type
                                }}
                            </span>

                            <h4>
                                {{
                                    resource.name
                                }}
                            </h4>

                            <p>
                                {{
                                    resource.location
                                }}
                            </p>
                        </div>

                        <span
                            class="status-badge"
                        >
                            Activo
                        </span>
                    </div>

                    <div class="rule-values">
                        <div>
                            <span>
                                Capacidad
                            </span>

                            <strong>
                                {{
                                    resource.capacity
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Horario
                            </span>

                            <strong>
                                {{
                                    resource.openTime
                                }}
                                -
                                {{
                                    resource.closeTime
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Franjas
                            </span>

                            <strong>
                                {{
                                    resource.slotMinutes
                                }}
                                min
                            </strong>
                        </div>

                        <div>
                            <span>
                                Máximo por reserva
                            </span>

                            <strong>
                                {{
                                    minutesLabel(
                                        resource.maxBookingMinutes,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Cancelación mínima
                            </span>

                            <strong>
                                {{
                                    minutesLabel(
                                        resource.cancelBeforeMinutes,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Tolerancia no-show
                            </span>

                            <strong>
                                {{
                                    resource.noShowToleranceMinutes
                                }}
                                min
                            </strong>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="edit-button"
                        @click="
                            openRuleEditor(
                                resource,
                            )
                        "
                    >
                        Editar configuración
                    </button>
                </article>
            </div>
        </section>

        <section
            v-if="
                activeTab ===
                'waitlist'
            "
            class="content-panel"
        >
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        LISTA DE ESPERA
                    </span>

                    <h3>
                        Solicitudes en espera
                    </h3>

                    <p>
                        Cuando se libera un
                        espacio, la siguiente
                        solicitud puede ser
                        promovida.
                    </p>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Recurso</th>
                        <th>Fecha</th>
                        <th>Horario</th>
                        <th>Posición</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                item in
                                waitlist
                            "
                        :key="
                                item.id
                            "
                    >
                        <td>
                            <strong
                                class="folio"
                            >
                                {{
                                    item.folio
                                }}
                            </strong>
                        </td>

                        <td>
                            {{
                                item.resourceName
                            }}
                        </td>

                        <td>
                            {{
                                formatDate(
                                    item.date,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                item.startTime
                            }}
                            -
                            {{
                                item.endTime
                            }}
                        </td>

                        <td>
                            #{{ item.position }}
                        </td>

                        <td>
                                <span
                                    class="wait-status"
                                    :class="`wait-${item.status}`"
                                >
                                    {{
                                        waitlistStatusLabel(
                                            item.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <div class="actions">
                                <button
                                    v-if="
                                            item.status ===
                                            'waiting'
                                        "
                                    type="button"
                                    class="promote-button"
                                    @click="
                                            promoteWaitlist(
                                                item,
                                            )
                                        "
                                >
                                    Promover
                                </button>

                                <button
                                    v-if="
                                            item.status ===
                                            'waiting'
                                        "
                                    type="button"
                                    class="danger-action"
                                    @click="
                                            cancelWaitlist(
                                                item,
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
        </section>

        <div
            v-if="
                showRuleModal &&
                selectedResource
            "
            class="modal-backdrop"
            @click.self="
                closeRuleEditor
            "
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">
                            CONFIGURACIÓN
                        </span>

                        <h3>
                            {{
                                selectedResource.name
                            }}
                        </h3>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeRuleEditor
                        "
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>
                                Capacidad
                            </label>

                            <input
                                v-model.number="
                                    editCapacity
                                "
                                type="number"
                                min="1"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Duración de franja
                            </label>

                            <input
                                v-model.number="
                                    editSlotMinutes
                                "
                                type="number"
                                min="15"
                                step="15"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Hora de apertura
                            </label>

                            <input
                                v-model="
                                    editOpenTime
                                "
                                type="time"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Hora de cierre
                            </label>

                            <input
                                v-model="
                                    editCloseTime
                                "
                                type="time"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Máximo por reserva
                            </label>

                            <input
                                v-model.number="
                                    editMaxBookingMinutes
                                "
                                type="number"
                                min="30"
                                step="30"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Cancelación mínima
                            </label>

                            <input
                                v-model.number="
                                    editCancelBeforeMinutes
                                "
                                type="number"
                                min="0"
                            />
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Tolerancia no-show
                            </label>

                            <input
                                v-model.number="
                                    editNoShowTolerance
                                "
                                type="number"
                                min="0"
                            />
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeRuleEditor
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                saveRule
                            "
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
            @click.self="
                closeBlockForm
            "
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label">
                            BLOQUEO
                        </span>

                        <h3>
                            Bloquear horario
                        </h3>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeBlockForm
                        "
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-grid">
                        <div
                            class="form-field full"
                        >
                            <label>
                                Recurso
                            </label>

                            <select
                                v-model="
                                    blockResourceId
                                "
                            >
                                <option value="">
                                    Selecciona
                                </option>

                                <option
                                    v-for="
                                        resource in
                                        resources
                                    "
                                    :key="
                                        resource.id
                                    "
                                    :value="
                                        resource.id
                                    "
                                >
                                    {{
                                        resource.name
                                    }}
                                </option>
                            </select>
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Fecha
                            </label>

                            <input
                                v-model="
                                    blockDate
                                "
                                type="date"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Inicio
                            </label>

                            <input
                                v-model="
                                    blockStart
                                "
                                type="time"
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Fin
                            </label>

                            <input
                                v-model="
                                    blockEnd
                                "
                                type="time"
                            />
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Motivo
                            </label>

                            <textarea
                                v-model="
                                    blockReason
                                "
                                rows="3"
                                placeholder="Ej. Mantenimiento..."
                            />
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeBlockForm
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                createBlock
                            "
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
    letter-spacing: .12em;
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
    background: rgba(255,255,255,.1);
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
    grid-template-columns: repeat(4,1fr);
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
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(190px,1fr)
        );
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
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(270px,1fr)
        );
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
    grid-template-columns:
        repeat(2,1fr);
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
    background: rgba(18,29,47,.5);
}

.modal {
    width: min(560px,100%);
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
    grid-template-columns: repeat(2,1fr);
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
        grid-template-columns:
            repeat(2,1fr);
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
