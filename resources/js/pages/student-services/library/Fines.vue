<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type FineStatus = 'pending' | 'paid' | 'waived' | 'cancelled';

type FineType = 'late' | 'damage' | 'loss' | 'other';

interface LibraryFine {
    id: string;
    folio: string;
    student_id: string;
    loan_id: string;
    book_title: string;
    copy_code: string | null;
    type: FineType;
    amount_cents: number;
    reason: string;
    status: FineStatus;
    generated_at: string | null;
    payment_reference_id: string | null;
    paid_at: string | null;
    notes: string | null;
}

interface LoanOption {
    id: string;
    student_id: string;
    book_title: string;
    copy_code: string;
    status: 'active' | 'overdue' | 'returned';
    borrowed_at: string | null;
    due_at: string | null;
}

const props = defineProps<{
    fines: LibraryFine[];
    loans: LoanOption[];
}>();

const search = ref('');
const statusFilter = ref<'all' | FineStatus>('all');
const typeFilter = ref<'all' | FineType>('all');
const showForm = ref(false);
const processingFineId = ref<string | null>(null);

const form = useForm({
    loan_id: '',
    type: 'late' as FineType,
    amount: 0,
    reason: '',
    notes: '',
});

const formError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return Object.values(errors)[0] ?? '';
});

const selectedLoan = computed(
    () => props.loans.find((loan) => loan.id === form.loan_id) ?? null,
);

const pendingCount = computed(
    () => props.fines.filter((fine) => fine.status === 'pending').length,
);

const paidCount = computed(
    () => props.fines.filter((fine) => fine.status === 'paid').length,
);

const closedCount = computed(
    () =>
        props.fines.filter((fine) =>
            ['waived', 'cancelled'].includes(fine.status),
        ).length,
);

const pendingAmount = computed(() =>
    props.fines
        .filter((fine) => fine.status === 'pending')
        .reduce((total, fine) => total + fine.amount_cents, 0),
);

const filteredFines = computed(() => {
    const value = search.value.trim().toLowerCase();

    return props.fines.filter((fine) => {
        const matchesSearch =
            value === '' ||
            [
                fine.folio,
                fine.student_id,
                fine.loan_id,
                fine.book_title,
                fine.copy_code ?? '',
            ].some((field) => field.toLowerCase().includes(value));

        const matchesStatus =
            statusFilter.value === 'all' || fine.status === statusFilter.value;

        const matchesType =
            typeFilter.value === 'all' || fine.type === typeFilter.value;

        return matchesSearch && matchesStatus && matchesType;
    });
});

function loanFolio(loanId: string): string {
    return `PRE-${loanId.slice(-6).toUpperCase()}`;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function formatMoney(cents: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(cents / 100);
}

function statusLabel(status: FineStatus): string {
    const labels: Record<FineStatus, string> = {
        pending: 'Pendiente',
        paid: 'Pagada',
        waived: 'Condonada',
        cancelled: 'Cancelada',
    };

    return labels[status];
}

function typeLabel(type: FineType): string {
    const labels: Record<FineType, string> = {
        late: 'Atraso',
        damage: 'Daño',
        loss: 'Pérdida',
        other: 'Otro',
    };

    return labels[type];
}

function loanStatusLabel(status: LoanOption['status']): string {
    return (
        {
            active: 'activo',
            overdue: 'vencido',
            returned: 'devuelto',
        } as Record<string, string>
    )[status];
}

function statusClass(status: FineStatus): string {
    return {
        pending: 'status-pending',
        paid: 'status-paid',
        waived: 'status-waived',
        cancelled: 'status-cancelled',
    }[status];
}

function openCreateForm() {
    form.reset();
    form.clearErrors();
    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeForm() {
    showForm.value = false;
    form.reset();
    form.clearErrors();
}

function createFine() {
    if (!form.loan_id) {
        window.alert('Selecciona el préstamo al que corresponde la multa.');

        return;
    }

    if (Number(form.amount) <= 0) {
        window.alert('El monto de la multa debe ser mayor a cero.');

        return;
    }

    form.transform((data) => ({
        loan_id: data.loan_id,
        type: data.type,
        amount_cents: Math.round(Number(data.amount) * 100),
        reason: data.reason.trim(),
        notes: data.notes.trim() || null,
    })).post('/servicios-estudiante/biblioteca/multas', {
        preserveScroll: true,
        onSuccess: () => {
            closeForm();
            window.alert('Multa registrada correctamente.');
        },
    });
}

function runAction(
    fine: LibraryFine,
    action: 'pagar' | 'condonar' | 'cancelar',
    payload: Record<string, string | null>,
    successMessage: string,
) {
    processingFineId.value = fine.id;

    router.patch(
        `/servicios-estudiante/biblioteca/multas/${fine.id}/${action}`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                window.alert(successMessage);
            },
            onError: (errors) => {
                window.alert(
                    errors.fine ??
                        Object.values(errors)[0] ??
                        'No fue posible completar la operación.',
                );
            },
            onFinish: () => {
                processingFineId.value = null;
            },
        },
    );
}

function markAsPaid(fine: LibraryFine) {
    const paymentReference = window.prompt(
        `Referencia de pago para ${fine.folio} (${formatMoney(fine.amount_cents)}):`,
        `PAY-${Date.now()}`,
    );

    if (!paymentReference?.trim()) {
        return;
    }

    runAction(
        fine,
        'pagar',
        { payment_reference_id: paymentReference.trim() },
        'Pago registrado correctamente.',
    );
}

function waiveFine(fine: LibraryFine) {
    const notes = window.prompt(
        `Motivo de la condonación de ${fine.folio}:`,
        '',
    );

    if (notes === null) {
        return;
    }

    runAction(
        fine,
        'condonar',
        { notes: notes.trim() || null },
        'Multa condonada correctamente.',
    );
}

function cancelFine(fine: LibraryFine) {
    if (
        !window.confirm(
            `¿Cancelar la multa ${fine.folio}? Úsalo solo si se registró por error.`,
        )
    ) {
        return;
    }

    runAction(
        fine,
        'cancelar',
        { notes: null },
        'Multa cancelada correctamente.',
    );
}

function viewDetails(fine: LibraryFine) {
    const content = [
        `Folio: ${fine.folio}`,
        `Estudiante: ${fine.student_id}`,
        `Préstamo: ${loanFolio(fine.loan_id)}`,
        `Libro: ${fine.book_title}`,
        `Ejemplar: ${fine.copy_code ?? '—'}`,
        `Tipo: ${typeLabel(fine.type)}`,
        `Motivo: ${fine.reason}`,
        `Monto: ${formatMoney(fine.amount_cents)}`,
        `Estado: ${statusLabel(fine.status)}`,
        `Generada: ${formatDate(fine.generated_at)}`,
        `Pagada: ${formatDate(fine.paid_at)}`,
        `Referencia: ${fine.payment_reference_id ?? 'Sin referencia'}`,
        `Notas: ${fine.notes ?? 'Sin notas'}`,
    ].join('\n');

    window.alert(content);
}
</script>

<template>
    <StudentServicesLayout
        title="Multas de biblioteca"
        subtitle="Consulta y administra adeudos de biblioteca"
    >
        <section class="summary">
            <div>
                <span class="section-label">BIBLIOTECA · MÓDULO 5.2</span>

                <h2>Multas y adeudos</h2>

                <p>
                    Controla multas por atraso, daños o pérdida de ejemplares y
                    consulta su estado de pago. Un estudiante con multas
                    pendientes no puede recibir nuevos préstamos en la
                    validación de servicios (5.11).
                </p>
            </div>

            <div class="total-box">
                <span>Adeudo pendiente</span>
                <strong>{{ formatMoney(pendingAmount) }}</strong>
                <small>multas pendientes</small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>Pendientes</span>
                <strong>{{ pendingCount }}</strong>
                <small>Adeudos activos</small>
            </article>

            <article class="stat-card">
                <span>Pagadas</span>
                <strong>{{ paidCount }}</strong>
                <small>Pagos registrados</small>
            </article>

            <article class="stat-card">
                <span>Condonadas / canceladas</span>
                <strong>{{ closedCount }}</strong>
                <small>Adeudos anulados</small>
            </article>

            <article class="stat-card">
                <span>Total registros</span>
                <strong>{{ fines.length }}</strong>
                <small>Historial de multas</small>
            </article>
        </section>

        <section class="integration-notice">
            <div>
                <strong>Integración de pagos</strong>

                <p>
                    Por ahora el pago se registra con una referencia capturada;
                    cuando el Equipo 2 publique su servicio de cobro (wallet),
                    la referencia vendrá de ahí.
                </p>
            </div>

            <span>Equipo 2</span>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h3>Multas registradas</h3>
                    <p>Consulta adeudos, pagos y referencias.</p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Nueva multa
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="form-label">NUEVA MULTA</span>

                        <h3>Registrar adeudo</h3>

                        <p>
                            Selecciona el préstamo; el estudiante y el ejemplar
                            se toman del registro real.
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

                <form class="fine-form" @submit.prevent="createFine">
                    <div class="form-grid">
                        <div class="form-field form-field-full">
                            <label>Préstamo <span>*</span></label>

                            <select v-model="form.loan_id">
                                <option value="" disabled>
                                    Selecciona un préstamo
                                </option>

                                <option
                                    v-for="loan in loans"
                                    :key="loan.id"
                                    :value="loan.id"
                                >
                                    {{ loanFolio(loan.id) }} ·
                                    {{ loan.book_title }} ({{ loan.copy_code }})
                                    · {{ loan.student_id }} ·
                                    {{ loanStatusLabel(loan.status) }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Estudiante</label>
                            <input
                                :value="selectedLoan?.student_id ?? ''"
                                type="text"
                                readonly
                                placeholder="Se toma del préstamo"
                            />
                        </div>

                        <div class="form-field">
                            <label>Vencimiento del préstamo</label>
                            <input
                                :value="
                                    selectedLoan
                                        ? formatDate(selectedLoan.due_at)
                                        : ''
                                "
                                type="text"
                                readonly
                                placeholder="—"
                            />
                        </div>

                        <div class="form-field">
                            <label>Tipo</label>

                            <select v-model="form.type">
                                <option value="late">Atraso</option>
                                <option value="damage">Daño</option>
                                <option value="loss">Pérdida</option>
                                <option value="other">Otro</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Monto (MXN) <span>*</span></label>

                            <input
                                v-model.number="form.amount"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                            />
                        </div>

                        <div class="form-field form-field-full">
                            <label>Motivo <span>*</span></label>

                            <input
                                v-model="form.reason"
                                type="text"
                                maxlength="500"
                                placeholder="Ej. Devolución con 3 días de atraso"
                            />
                        </div>

                        <div class="form-field form-field-full">
                            <label>Notas</label>

                            <textarea
                                v-model="form.notes"
                                rows="3"
                                placeholder="Observaciones internas (opcional)"
                            ></textarea>
                        </div>
                    </div>

                    <div v-if="formError" class="information-box error-box">
                        {{ formError }}
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
                            Registrar multa
                        </button>
                    </div>
                </form>
            </section>

            <div class="filters">
                <div class="search-field">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por folio, estudiante, préstamo, libro o ejemplar..."
                    />
                </div>

                <select v-model="typeFilter">
                    <option value="all">Todos los tipos</option>
                    <option value="late">Atraso</option>
                    <option value="damage">Daño</option>
                    <option value="loss">Pérdida</option>
                    <option value="other">Otro</option>
                </select>

                <select v-model="statusFilter">
                    <option value="all">Todos los estados</option>
                    <option value="pending">Pendientes</option>
                    <option value="paid">Pagadas</option>
                    <option value="waived">Condonadas</option>
                    <option value="cancelled">Canceladas</option>
                </select>
            </div>

            <div v-if="filteredFines.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Estudiante</th>
                            <th>Libro / ejemplar</th>
                            <th>Tipo</th>
                            <th>Monto</th>
                            <th>Generada</th>
                            <th>Estado</th>
                            <th>Pago</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="fine in filteredFines" :key="fine.id">
                            <td>
                                <strong class="folio">{{ fine.folio }}</strong>
                                <small class="loan-id">
                                    {{ loanFolio(fine.loan_id) }}
                                </small>
                            </td>

                            <td>
                                <span class="student-id">
                                    {{ fine.student_id }}
                                </span>
                            </td>

                            <td>
                                <div class="book-info">
                                    <strong>{{ fine.book_title }}</strong>
                                    <small>{{ fine.copy_code ?? '—' }}</small>
                                </div>
                            </td>

                            <td>
                                <span class="reason" :title="fine.reason">
                                    {{ typeLabel(fine.type) }}
                                </span>
                            </td>

                            <td>
                                <strong class="amount">
                                    {{ formatMoney(fine.amount_cents) }}
                                </strong>
                            </td>

                            <td>{{ formatDate(fine.generated_at) }}</td>

                            <td>
                                <span
                                    class="status"
                                    :class="statusClass(fine.status)"
                                >
                                    {{ statusLabel(fine.status) }}
                                </span>
                            </td>

                            <td>
                                <div class="payment-info">
                                    <span>{{
                                        fine.payment_reference_id ?? '—'
                                    }}</span>
                                    <small v-if="fine.paid_at">
                                        {{ formatDate(fine.paid_at) }}
                                    </small>
                                </div>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-button details"
                                        @click="viewDetails(fine)"
                                    >
                                        Ver
                                    </button>

                                    <template v-if="fine.status === 'pending'">
                                        <button
                                            type="button"
                                            class="action-button pay"
                                            :disabled="
                                                processingFineId === fine.id
                                            "
                                            @click="markAsPaid(fine)"
                                        >
                                            Registrar pago
                                        </button>

                                        <button
                                            type="button"
                                            class="action-button details"
                                            :disabled="
                                                processingFineId === fine.id
                                            "
                                            @click="waiveFine(fine)"
                                        >
                                            Condonar
                                        </button>

                                        <button
                                            type="button"
                                            class="action-button cancel"
                                            :disabled="
                                                processingFineId === fine.id
                                            "
                                            @click="cancelFine(fine)"
                                        >
                                            Cancelar
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                <h3>No se encontraron multas</h3>
                <p>Cambia los filtros o registra una nueva multa.</p>
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
    min-width: 180px;
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
    font-size: 24px;
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

.integration-notice {
    margin-top: 18px;
    padding: 14px 17px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid #d5e1f1;
    border-radius: 9px;
    background: #eef4fc;
}

.integration-notice strong {
    color: #2f5898;
    font-size: 11px;
}

.integration-notice p {
    margin: 4px 0 0;
    color: #64758e;
    font-size: 10px;
}

.integration-notice > span {
    padding: 6px 10px;
    border-radius: 6px;
    background: white;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
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

.fine-form {
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

.form-field textarea {
    resize: vertical;
}

.information-box {
    margin-top: 16px;
    padding: 11px 13px;
    border: 1px solid #d5e1f1;
    border-radius: 7px;
    background: #eef4fc;
    color: #657690;
    font-size: 10px;
}

.information-box strong {
    color: #315a9f;
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
    min-width: 160px;
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

.folio {
    display: block;
    color: #285aa6;
}

.loan-id {
    display: block;
    margin-top: 3px;
    color: #9aa5b5;
    font-size: 8px;
}

.student-id {
    color: #42546d;
    font-weight: 700;
}

.book-info,
.payment-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.book-info strong {
    color: #303d53;
}

.book-info small,
.payment-info small {
    color: #99a4b4;
    font-size: 9px;
}

.reason {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 6px;
    background: #f0f3f7;
    color: #596a81;
    font-size: 9px;
    font-weight: 700;
}

.amount {
    color: #34435a;
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
    background: #e4f6ec;
    color: #217a4e;
}

.status-cancelled {
    background: #f3e8e9;
    color: #98505a;
}

.actions {
    min-width: 220px;
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
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

.action-button.details {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.pay {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
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

@media (max-width: 1050px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters {
        align-items: stretch;
        flex-direction: column;
    }

    .filters select {
        width: 100%;
    }
}

@media (max-width: 750px) {
    .summary,
    .integration-notice {
        align-items: flex-start;
        flex-direction: column;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-field-full {
        grid-column: auto;
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
<style scoped>
/* Estilos agregados al conectar el módulo 5.2 con el backend */
.status-waived {
    background: #e8f0fc;
    color: #315fa6;
}

.information-box.error-box {
    border-color: #e6c9cd;
    background: #fbebed;
    color: #9d4650;
    font-weight: 700;
}

.action-button:disabled {
    opacity: 0.55;
    cursor: wait;
}
</style>
