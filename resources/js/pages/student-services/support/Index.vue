<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type TicketPriority = 'low' | 'medium' | 'high' | 'critical';

type TicketStatus =
    | 'open'
    | 'assigned'
    | 'in_progress'
    | 'resolved'
    | 'closed'
    | 'cancelled';

type TicketCategory =
    | 'network'
    | 'equipment'
    | 'facilities'
    | 'software'
    | 'printing'
    | 'other';

interface TicketEvent {
    id: string;
    type: string;
    comment: string;
    user: string;
    createdAt: string | null;
}

interface SupportTicket {
    id: string;
    folio: string;
    category: TicketCategory;
    subject: string;
    description: string;
    priority: TicketPriority;
    location: string;
    status: TicketStatus;
    assignedTo: string | null;
    evidence: string | null;
    openedAt: string | null;
    slaDueAt: string | null;
    resolvedAt: string | null;
    closedAt?: string | null;
    events: TicketEvent[];
}

interface TicketForm {
    category: TicketCategory | '';
    subject: string;
    description: string;
    priority: TicketPriority;
    location: string;
    evidence: File | null;
}

const props = defineProps<{
    tickets: SupportTicket[];
}>();

const search = ref('');
const statusFilter = ref('');
const priorityFilter = ref('');
const categoryFilter = ref('');

const showForm = ref(false);
const showDetails = ref(false);

const selectedTicketId = ref<string | null>(null);

const newComment = ref('');
const commentProcessing = ref(false);

const processingTicketId = ref<string | null>(null);

const evidenceName = ref('');

const ticketForm = useForm<TicketForm>({
    category: '',
    subject: '',
    description: '',
    priority: 'medium',
    location: '',
    evidence: null,
});

const selectedTicket = computed(() => {
    if (selectedTicketId.value === null) {
        return null;
    }

    return (
        props.tickets.find((ticket) => ticket.id === selectedTicketId.value) ??
        null
    );
});

const openCount = computed(
    () =>
        props.tickets.filter((ticket) =>
            ['open', 'assigned'].includes(ticket.status),
        ).length,
);

const progressCount = computed(
    () =>
        props.tickets.filter((ticket) => ticket.status === 'in_progress')
            .length,
);

const resolvedCount = computed(
    () => props.tickets.filter((ticket) => ticket.status === 'resolved').length,
);

const criticalCount = computed(
    () =>
        props.tickets.filter(
            (ticket) =>
                ticket.priority === 'critical' &&
                !['resolved', 'closed', 'cancelled'].includes(ticket.status),
        ).length,
);

const categories: {
    value: TicketCategory;
    label: string;
}[] = [
    {
        value: 'network',
        label: 'Red e internet',
    },
    {
        value: 'equipment',
        label: 'Equipo',
    },
    {
        value: 'facilities',
        label: 'Instalaciones',
    },
    {
        value: 'software',
        label: 'Software',
    },
    {
        value: 'printing',
        label: 'Impresiones',
    },
    {
        value: 'other',
        label: 'Otro',
    },
];

const filteredTickets = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.tickets.filter((ticket) => {
        if (statusFilter.value && ticket.status !== statusFilter.value) {
            return false;
        }

        if (priorityFilter.value && ticket.priority !== priorityFilter.value) {
            return false;
        }

        if (categoryFilter.value && ticket.category !== categoryFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [
            ticket.folio,
            ticket.subject,
            ticket.description,
            ticket.location,
            categoryLabel(ticket.category),
            ticket.assignedTo ?? '',
        ].some((value) => value.toLowerCase().includes(term));
    });
});

function categoryLabel(value: TicketCategory): string {
    const category = categories.find((item) => item.value === value);

    return category?.label ?? value;
}

function priorityLabel(value: TicketPriority): string {
    const labels: Record<TicketPriority, string> = {
        low: 'Baja',
        medium: 'Media',
        high: 'Alta',
        critical: 'Crítica',
    };

    return labels[value];
}

function statusLabel(value: TicketStatus): string {
    const labels: Record<TicketStatus, string> = {
        open: 'Abierto',
        assigned: 'Asignado',
        in_progress: 'En proceso',
        resolved: 'Resuelto',
        closed: 'Cerrado',
        cancelled: 'Cancelado',
    };

    return labels[value];
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function openForm() {
    ticketForm.reset();

    ticketForm.clearErrors();

    ticketForm.category = '';
    ticketForm.priority = 'medium';

    evidenceName.value = '';

    showForm.value = true;
}

function closeForm() {
    showForm.value = false;

    evidenceName.value = '';

    ticketForm.reset();

    ticketForm.clearErrors();
}

function handleEvidence(event: Event) {
    const input = event.target as HTMLInputElement;

    const file = input.files?.[0] ?? null;

    ticketForm.evidence = file;

    evidenceName.value = file?.name ?? '';
}

function createTicket() {
    if (ticketForm.category === '') {
        window.alert('Selecciona una categoría.');

        return;
    }

    if (!ticketForm.subject.trim()) {
        window.alert('Escribe el asunto del ticket.');

        return;
    }

    if (!ticketForm.location.trim()) {
        window.alert('Escribe la ubicación de la incidencia.');

        return;
    }

    if (!ticketForm.description.trim()) {
        window.alert('Escribe una descripción del problema.');

        return;
    }

    ticketForm.post('/servicios-estudiante/soporte', {
        preserveScroll: true,
        forceFormData: true,

        onSuccess: () => {
            closeForm();

            window.alert('Ticket registrado correctamente.');
        },

        onError: (errors) => {
            const message =
                errors.ticket ??
                errors.category ??
                errors.subject ??
                errors.location ??
                errors.description ??
                errors.priority ??
                errors.evidence ??
                'No fue posible registrar el ticket.';

            window.alert(String(message));
        },
    });
}

function openDetails(ticket: SupportTicket) {
    selectedTicketId.value = ticket.id;

    newComment.value = '';

    showDetails.value = true;
}

function closeDetails() {
    selectedTicketId.value = null;

    newComment.value = '';

    showDetails.value = false;
}

function addComment() {
    const ticket = selectedTicket.value;

    if (ticket === null || !newComment.value.trim()) {
        return;
    }

    commentProcessing.value = true;

    router.post(
        `/servicios-estudiante/soporte/${ticket.id}/comentarios`,
        {
            message: newComment.value.trim(),
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                newComment.value = '';
            },

            onError: (errors) => {
                window.alert(
                    String(
                        errors.ticket ??
                            errors.message ??
                            'No fue posible agregar el comentario.',
                    ),
                );
            },

            onFinish: () => {
                commentProcessing.value = false;
            },
        },
    );
}

function cancelTicket(ticket: SupportTicket) {
    if (!['open', 'assigned'].includes(ticket.status)) {
        window.alert('Este ticket ya no puede cancelarse.');

        return;
    }

    const confirmed = window.confirm(`¿Cancelar el ticket ${ticket.folio}?`);

    if (!confirmed) {
        return;
    }

    processingTicketId.value = ticket.id;

    router.patch(
        `/servicios-estudiante/soporte/${ticket.id}/cancelar`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                if (selectedTicketId.value === ticket.id) {
                    closeDetails();
                }

                window.alert('Ticket cancelado correctamente.');
            },

            onError: (errors) => {
                window.alert(
                    String(
                        errors.ticket ?? 'No fue posible cancelar el ticket.',
                    ),
                );
            },

            onFinish: () => {
                processingTicketId.value = null;
            },
        },
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Soporte e incidencias"
        subtitle="Tickets de soporte y seguimiento de incidencias"
    >
        <section class="hero">
            <div>
                <span class="hero-label"> SERVICIOS · MÓDULO 5.9 </span>

                <h2>Tickets de soporte</h2>

                <p>
                    Reporta incidencias del campus y consulta su prioridad,
                    responsable, SLA y seguimiento.
                </p>
            </div>

            <div class="hero-total">
                <span> Tickets registrados </span>

                <strong>
                    {{ props.tickets.length }}
                </strong>

                <small> incidencias </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span> Abiertos </span>

                <strong>
                    {{ openCount }}
                </strong>

                <small> Esperan atención </small>
            </article>

            <article class="stat-card">
                <span> En proceso </span>

                <strong>
                    {{ progressCount }}
                </strong>

                <small> Siendo atendidos </small>
            </article>

            <article class="stat-card">
                <span> Resueltos </span>

                <strong>
                    {{ resolvedCount }}
                </strong>

                <small> Pendientes de cierre </small>
            </article>

            <article class="stat-card">
                <span> Críticos </span>

                <strong>
                    {{ criticalCount }}
                </strong>

                <small> Atención prioritaria </small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label"> INCIDENCIAS </span>

                    <h3>Tickets registrados</h3>

                    <p>Consulta y da seguimiento a tus reportes.</p>
                </div>

                <button type="button" class="primary-button" @click="openForm">
                    + Nuevo ticket
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="panel-label"> NUEVA INCIDENCIA </span>

                        <h3>Crear ticket</h3>

                        <p>Describe el problema y proporciona su ubicación.</p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeForm"
                    >
                        ×
                    </button>
                </div>

                <div class="ticket-form">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>
                                Categoría
                                <span> * </span>
                            </label>

                            <select v-model="ticketForm.category">
                                <option value="">Selecciona</option>

                                <option
                                    v-for="item in categories"
                                    :key="item.value"
                                    :value="item.value"
                                >
                                    {{ item.label }}
                                </option>
                            </select>

                            <small
                                v-if="ticketForm.errors.category"
                                class="error-text"
                            >
                                {{ ticketForm.errors.category }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label>
                                Prioridad
                                <span> * </span>
                            </label>

                            <select v-model="ticketForm.priority">
                                <option value="low">Baja</option>

                                <option value="medium">Media</option>

                                <option value="high">Alta</option>

                                <option value="critical">Crítica</option>
                            </select>

                            <small
                                v-if="ticketForm.errors.priority"
                                class="error-text"
                            >
                                {{ ticketForm.errors.priority }}
                            </small>
                        </div>

                        <div class="form-field full">
                            <label>
                                Asunto
                                <span> * </span>
                            </label>

                            <input
                                v-model="ticketForm.subject"
                                type="text"
                                maxlength="150"
                                placeholder="Describe brevemente el problema"
                            />

                            <small
                                v-if="ticketForm.errors.subject"
                                class="error-text"
                            >
                                {{ ticketForm.errors.subject }}
                            </small>
                        </div>

                        <div class="form-field full">
                            <label>
                                Ubicación
                                <span> * </span>
                            </label>

                            <input
                                v-model="ticketForm.location"
                                type="text"
                                maxlength="200"
                                placeholder="Ej. Edificio A · Aula 203"
                            />

                            <small
                                v-if="ticketForm.errors.location"
                                class="error-text"
                            >
                                {{ ticketForm.errors.location }}
                            </small>
                        </div>

                        <div class="form-field full">
                            <label>
                                Descripción
                                <span> * </span>
                            </label>

                            <textarea
                                v-model="ticketForm.description"
                                rows="4"
                                maxlength="2000"
                                placeholder="Explica qué ocurrió..."
                            />

                            <small
                                v-if="ticketForm.errors.description"
                                class="error-text"
                            >
                                {{ ticketForm.errors.description }}
                            </small>
                        </div>

                        <div class="form-field full">
                            <label> Evidencia </label>

                            <input
                                type="file"
                                accept=".png,.jpg,.jpeg,.pdf"
                                @change="handleEvidence"
                            />

                            <small v-if="evidenceName" class="file-name">
                                Archivo:
                                {{ evidenceName }}
                            </small>

                            <small
                                v-if="ticketForm.errors.evidence"
                                class="error-text"
                            >
                                {{ ticketForm.errors.evidence }}
                            </small>
                        </div>
                    </div>

                    <div class="information-box">
                        El SLA se calcula automáticamente en el servidor según
                        la prioridad: crítica 2 horas, alta 6 horas, media 24
                        horas y baja 48 horas.
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            :disabled="ticketForm.processing"
                            @click="closeForm"
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="ticketForm.processing"
                            @click="createTicket"
                        >
                            {{
                                ticketForm.processing
                                    ? 'Registrando...'
                                    : 'Registrar ticket'
                            }}
                        </button>
                    </div>
                </div>
            </section>

            <div class="filters">
                <div class="search-field">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por folio, asunto, ubicación..."
                    />
                </div>

                <select v-model="categoryFilter">
                    <option value="">Todas las categorías</option>

                    <option
                        v-for="item in categories"
                        :key="item.value"
                        :value="item.value"
                    >
                        {{ item.label }}
                    </option>
                </select>

                <select v-model="priorityFilter">
                    <option value="">Todas las prioridades</option>

                    <option value="low">Baja</option>

                    <option value="medium">Media</option>

                    <option value="high">Alta</option>

                    <option value="critical">Crítica</option>
                </select>

                <select v-model="statusFilter">
                    <option value="">Todos los estados</option>

                    <option value="open">Abierto</option>

                    <option value="assigned">Asignado</option>

                    <option value="in_progress">En proceso</option>

                    <option value="resolved">Resuelto</option>

                    <option value="closed">Cerrado</option>

                    <option value="cancelled">Cancelado</option>
                </select>
            </div>

            <div v-if="filteredTickets.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>

                            <th>Asunto</th>

                            <th>Categoría</th>

                            <th>Prioridad</th>

                            <th>Ubicación</th>

                            <th>Responsable</th>

                            <th>SLA</th>

                            <th>Estado</th>

                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="ticket in filteredTickets" :key="ticket.id">
                            <td>
                                <strong class="folio">
                                    {{ ticket.folio }}
                                </strong>

                                <small class="date">
                                    {{ formatDate(ticket.openedAt) }}
                                </small>
                            </td>

                            <td>
                                {{ ticket.subject }}
                            </td>

                            <td>
                                {{ categoryLabel(ticket.category) }}
                            </td>

                            <td>
                                <span
                                    class="priority"
                                    :class="`priority-${ticket.priority}`"
                                >
                                    {{ priorityLabel(ticket.priority) }}
                                </span>
                            </td>

                            <td>
                                {{ ticket.location }}
                            </td>

                            <td>
                                {{ ticket.assignedTo ?? 'Sin asignar' }}
                            </td>

                            <td>
                                {{ formatDate(ticket.slaDueAt) }}
                            </td>

                            <td>
                                <span
                                    class="status"
                                    :class="`status-${ticket.status}`"
                                >
                                    {{ statusLabel(ticket.status) }}
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-button view"
                                        @click="openDetails(ticket)"
                                    >
                                        Ver
                                    </button>

                                    <button
                                        v-if="
                                            ['open', 'assigned'].includes(
                                                ticket.status,
                                            )
                                        "
                                        type="button"
                                        class="action-button cancel"
                                        :disabled="
                                            processingTicketId === ticket.id
                                        "
                                        @click="cancelTicket(ticket)"
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
                <h3>No hay tickets</h3>

                <p>Registra una incidencia o modifica los filtros.</p>
            </div>
        </section>

        <div
            v-if="showDetails && selectedTicket"
            class="modal-backdrop"
            @click.self="closeDetails"
        >
            <section class="modal">
                <div class="modal-header">
                    <div>
                        <span class="panel-label"> SEGUIMIENTO </span>

                        <h3>
                            {{ selectedTicket.folio }}
                        </h3>

                        <p>
                            {{ selectedTicket.subject }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeDetails"
                    >
                        ×
                    </button>
                </div>

                <div class="modal-body">
                    <div class="detail-grid">
                        <div>
                            <span> Categoría </span>

                            <strong>
                                {{ categoryLabel(selectedTicket.category) }}
                            </strong>
                        </div>

                        <div>
                            <span> Prioridad </span>

                            <strong>
                                {{ priorityLabel(selectedTicket.priority) }}
                            </strong>
                        </div>

                        <div>
                            <span> Ubicación </span>

                            <strong>
                                {{ selectedTicket.location }}
                            </strong>
                        </div>

                        <div>
                            <span> Responsable </span>

                            <strong>
                                {{ selectedTicket.assignedTo ?? 'Sin asignar' }}
                            </strong>
                        </div>

                        <div>
                            <span> SLA </span>

                            <strong>
                                {{ formatDate(selectedTicket.slaDueAt) }}
                            </strong>
                        </div>

                        <div>
                            <span> Estado </span>

                            <strong>
                                {{ statusLabel(selectedTicket.status) }}
                            </strong>
                        </div>

                        <div>
                            <span> Evidencia </span>

                            <strong>
                                {{ selectedTicket.evidence ?? 'Sin evidencia' }}
                            </strong>
                        </div>

                        <div>
                            <span> Apertura </span>

                            <strong>
                                {{ formatDate(selectedTicket.openedAt) }}
                            </strong>
                        </div>

                        <div v-if="selectedTicket.resolvedAt">
                            <span> Resuelto </span>

                            <strong>
                                {{ formatDate(selectedTicket.resolvedAt) }}
                            </strong>
                        </div>

                        <div v-if="selectedTicket.closedAt">
                            <span> Cerrado </span>

                            <strong>
                                {{
                                    formatDate(selectedTicket.closedAt ?? null)
                                }}
                            </strong>
                        </div>
                    </div>

                    <div class="description-box">
                        <span> Descripción </span>

                        <p>
                            {{ selectedTicket.description }}
                        </p>
                    </div>

                    <div class="timeline">
                        <span class="timeline-title"> HISTORIAL </span>

                        <article
                            v-for="event in selectedTicket.events"
                            :key="event.id"
                        >
                            <div class="timeline-dot" />

                            <div>
                                <strong>
                                    {{ event.type }}
                                </strong>

                                <p>
                                    {{ event.comment }}
                                </p>

                                <small>
                                    {{ event.user }}
                                    ·
                                    {{ formatDate(event.createdAt) }}
                                </small>
                            </div>
                        </article>

                        <p
                            v-if="selectedTicket.events.length === 0"
                            class="no-events"
                        >
                            Todavía no existen eventos.
                        </p>
                    </div>

                    <div
                        v-if="
                            !['closed', 'cancelled'].includes(
                                selectedTicket.status,
                            )
                        "
                        class="comment-box"
                    >
                        <label> Agregar comentario </label>

                        <textarea
                            v-model="newComment"
                            rows="3"
                            maxlength="2000"
                            placeholder="Escribe un comentario..."
                        />

                        <button
                            type="button"
                            class="primary-button"
                            :disabled="commentProcessing || !newComment.trim()"
                            @click="addComment"
                        >
                            {{
                                commentProcessing
                                    ? 'Guardando...'
                                    : 'Agregar comentario'
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
    min-width: 170px;
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
.form-header,
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

.primary-button:disabled,
.secondary-button:disabled {
    cursor: default;
    opacity: 0.55;
}

.form-panel {
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.ticket-form,
.modal-body {
    padding: 21px;
}

.form-grid,
.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.form-field.full {
    grid-column: 1 / -1;
}

.form-field label,
.comment-box label {
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
.form-field textarea,
.comment-box textarea {
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

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus,
.comment-box textarea:focus {
    border-color: #7ca1d5;
}

.form-field textarea,
.comment-box textarea {
    resize: vertical;
}

.file-name {
    display: block;
    margin-top: 5px;
    color: #315fa6;
    font-size: 9px;
}

.error-text {
    display: block;
    margin-top: 5px;
    color: #b6404d;
    font-size: 9px;
}

.information-box {
    margin-top: 15px;
    padding: 11px 13px;
    border: 1px solid #d5e1f1;
    border-radius: 7px;
    background: #eef4fc;
    color: #657690;
    font-size: 9px;
    line-height: 1.6;
}

.form-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid #e5e9ef;
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

.filters {
    padding: 14px 21px;
    display: grid;
    grid-template-columns:
        minmax(220px, 2fr)
        repeat(3, 170px);
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
    white-space: nowrap;
}

td {
    padding: 14px;
    border-top: 1px solid #e9edf3;
    color: #5c6980;
    font-size: 10px;
}

.folio {
    display: block;
    color: #285aa6;
    white-space: nowrap;
}

.date {
    display: block;
    margin-top: 3px;
    color: #99a4b4;
    font-size: 8px;
    white-space: nowrap;
}

.priority,
.status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
    white-space: nowrap;
}

.priority-low {
    background: #edf1f5;
    color: #647286;
}

.priority-medium {
    background: #e8f0fc;
    color: #315fa6;
}

.priority-high {
    background: #fff3d7;
    color: #946510;
}

.priority-critical {
    background: #f7e4e6;
    color: #a23d49;
}

.status-open {
    background: #fff3d7;
    color: #946510;
}

.status-assigned,
.status-in_progress {
    background: #e8f0fc;
    color: #315fa6;
}

.status-resolved {
    background: #e4f6ec;
    color: #217a4e;
}

.status-closed {
    background: #edf1f5;
    color: #657285;
}

.status-cancelled {
    background: #f4e7e8;
    color: #9c4c55;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 100px;
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

.action-button:disabled {
    cursor: default;
    opacity: 0.5;
}

.action-button.view {
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
    padding: 55px;
    text-align: center;
}

.empty-state h3 {
    margin: 0;
    color: #334056;
}

.empty-state p {
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
    width: min(650px, 100%);
    max-height: 90vh;
    overflow: auto;
    border-radius: 11px;
    background: white;
}

.detail-grid div {
    padding: 12px;
    border-radius: 7px;
    background: #f6f8fb;
}

.detail-grid span,
.description-box span {
    display: block;
    color: #8b98aa;
    font-size: 8px;
}

.detail-grid strong {
    display: block;
    margin-top: 4px;
    color: #405069;
    font-size: 10px;
}

.description-box {
    margin-top: 14px;
    padding: 13px;
    border: 1px solid #e2e7ee;
    border-radius: 7px;
}

.description-box p {
    margin: 5px 0 0;
    color: #66778e;
    font-size: 10px;
    line-height: 1.6;
}

.timeline {
    margin-top: 18px;
}

.timeline-title {
    display: block;
    margin-bottom: 12px;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
}

.timeline article {
    display: flex;
    gap: 10px;
    padding: 0 0 15px;
}

.timeline-dot {
    width: 8px;
    height: 8px;
    margin-top: 4px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #3970c1;
}

.timeline strong {
    color: #405069;
    font-size: 10px;
}

.timeline p {
    margin: 3px 0;
    color: #718096;
    font-size: 9px;
}

.timeline small {
    color: #9aa4b2;
    font-size: 8px;
}

.no-events {
    color: #8d99aa;
    font-size: 9px;
}

.comment-box {
    margin-top: 12px;
    padding-top: 15px;
    border-top: 1px solid #e5e9ef;
}

.comment-box .primary-button {
    margin-top: 8px;
}

@media (max-width: 1100px) {
    .filters {
        grid-template-columns: repeat(2, 1fr);
    }

    .search-field {
        grid-column: 1 / -1;
    }
}

@media (max-width: 800px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .search-field {
        grid-column: auto;
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header,
    .form-header,
    .modal-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid,
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .form-field.full {
        grid-column: auto;
    }

    .hero-total {
        width: 100%;
        box-sizing: border-box;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
