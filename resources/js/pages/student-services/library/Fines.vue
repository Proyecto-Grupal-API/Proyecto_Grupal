<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { computed, ref } from 'vue';

type FineStatus =
    | 'pending'
    | 'paid'
    | 'cancelled';

type FineReason =
    | 'overdue'
    | 'damage'
    | 'lost'
    | 'other';

interface LibraryFine {
    id: string;
    folio: string;
    student_id: string;
    loan_id: string;
    book_title: string;
    copy_code: string;
    reason: FineReason;
    description: string;
    amount_cents: number;
    status: FineStatus;
    issued_at: string;
    paid_at: string | null;
    payment_reference_id: string | null;
    cancelled_at: string | null;
}

const fines = ref<LibraryFine[]>([
    {
        id: 'FINE-MOCK-001',
        folio: 'MUL-0001',
        student_id: 'EST-0001',
        loan_id: 'LOAN-MOCK-001',
        book_title: 'Clean Code',
        copy_code: 'EJ-001',
        reason: 'overdue',
        description:
            'Devolución realizada después de la fecha límite.',
        amount_cents: 8000,
        status: 'pending',
        issued_at: '2026-09-25',
        paid_at: null,
        payment_reference_id: null,
        cancelled_at: null,
    },
    {
        id: 'FINE-MOCK-002',
        folio: 'MUL-0002',
        student_id: 'EST-0002',
        loan_id: 'LOAN-MOCK-002',
        book_title: 'Design Patterns',
        copy_code: 'EJ-002',
        reason: 'damage',
        description:
            'Daño menor en cubierta del ejemplar.',
        amount_cents: 15000,
        status: 'paid',
        issued_at: '2026-09-21',
        paid_at: '2026-09-23',
        payment_reference_id: 'PAY-TEST-0001',
        cancelled_at: null,
    },
    {
        id: 'FINE-MOCK-003',
        folio: 'MUL-0003',
        student_id: 'EST-0003',
        loan_id: 'LOAN-MOCK-003',
        book_title:
            'Introduction to Algorithms',
        copy_code: 'EJ-003',
        reason: 'lost',
        description:
            'Ejemplar reportado como extraviado.',
        amount_cents: 65000,
        status: 'pending',
        issued_at: '2026-09-20',
        paid_at: null,
        payment_reference_id: null,
        cancelled_at: null,
    },
]);

const search = ref('');

const statusFilter = ref<
    'all' | FineStatus
>('all');

const reasonFilter = ref<
    'all' | FineReason
>('all');

const showForm = ref(false);

const form = ref({
    student_id: '',
    loan_id: '',
    book_title: '',
    copy_code: '',
    reason: 'overdue' as FineReason,
    description: '',
    amount: 0,
});

const pendingCount = computed(() => {
    return fines.value.filter(
        (fine) =>
            fine.status === 'pending',
    ).length;
});

const paidCount = computed(() => {
    return fines.value.filter(
        (fine) =>
            fine.status === 'paid',
    ).length;
});

const cancelledCount = computed(() => {
    return fines.value.filter(
        (fine) =>
            fine.status === 'cancelled',
    ).length;
});

const pendingAmount = computed(() => {
    return fines.value
        .filter(
            (fine) =>
                fine.status === 'pending',
        )
        .reduce(
            (total, fine) =>
                total +
                fine.amount_cents,
            0,
        );
});

const filteredFines = computed(() => {
    const value = search.value
        .trim()
        .toLowerCase();

    return fines.value.filter(
        (fine) => {
            const matchesSearch =
                value === '' ||
                fine.folio
                    .toLowerCase()
                    .includes(value) ||
                fine.student_id
                    .toLowerCase()
                    .includes(value) ||
                fine.loan_id
                    .toLowerCase()
                    .includes(value) ||
                fine.book_title
                    .toLowerCase()
                    .includes(value) ||
                fine.copy_code
                    .toLowerCase()
                    .includes(value);

            const matchesStatus =
                statusFilter.value ===
                'all' ||
                fine.status ===
                statusFilter.value;

            const matchesReason =
                reasonFilter.value ===
                'all' ||
                fine.reason ===
                reasonFilter.value;

            return (
                matchesSearch &&
                matchesStatus &&
                matchesReason
            );
        },
    );
});

function todayString(): string {
    const date = new Date();

    const year = date.getFullYear();
    const month = String(
        date.getMonth() + 1,
    ).padStart(2, '0');
    const day = String(
        date.getDate(),
    ).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function formatDate(
    value: string | null,
): string {
    if (!value) {
        return '—';
    }

    const date =
        value.slice(0, 10);

    const [
        year,
        month,
        day,
    ] = date.split('-');

    return `${day}/${month}/${year}`;
}

function formatMoney(
    cents: number,
): string {
    return new Intl.NumberFormat(
        'es-MX',
        {
            style: 'currency',
            currency: 'MXN',
        },
    ).format(cents / 100);
}

function statusLabel(
    status: FineStatus,
): string {
    const labels: Record<
        FineStatus,
        string
    > = {
        pending: 'Pendiente',
        paid: 'Pagada',
        cancelled: 'Cancelada',
    };

    return labels[status];
}

function reasonLabel(
    reason: FineReason,
): string {
    const labels: Record<
        FineReason,
        string
    > = {
        overdue: 'Atraso',
        damage: 'Daño',
        lost: 'Pérdida',
        other: 'Otro',
    };

    return labels[reason];
}

function statusClass(
    status: FineStatus,
): string {
    return {
        pending: 'status-pending',
        paid: 'status-paid',
        cancelled:
            'status-cancelled',
    }[status];
}

function generateFolio(): string {
    return `MUL-${String(
        fines.value.length + 1,
    ).padStart(4, '0')}`;
}

function openCreateForm() {
    form.value = {
        student_id: '',
        loan_id: '',
        book_title: '',
        copy_code: '',
        reason: 'overdue',
        description: '',
        amount: 0,
    };

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeForm() {
    showForm.value = false;

    form.value = {
        student_id: '',
        loan_id: '',
        book_title: '',
        copy_code: '',
        reason: 'overdue',
        description: '',
        amount: 0,
    };
}

function createFine() {
    if (
        !form.value.student_id.trim() ||
        !form.value.loan_id.trim() ||
        !form.value.book_title.trim() ||
        !form.value.copy_code.trim()
    ) {
        window.alert(
            'Completa los datos obligatorios de la multa.',
        );

        return;
    }

    if (
        Number(form.value.amount) <= 0
    ) {
        window.alert(
            'El monto de la multa debe ser mayor a cero.',
        );

        return;
    }

    fines.value.unshift({
        id: crypto.randomUUID(),
        folio: generateFolio(),
        student_id:
            form.value.student_id
                .trim()
                .toUpperCase(),
        loan_id:
            form.value.loan_id
                .trim()
                .toUpperCase(),
        book_title:
            form.value.book_title
                .trim(),
        copy_code:
            form.value.copy_code
                .trim()
                .toUpperCase(),
        reason:
        form.value.reason,
        description:
            form.value.description
                .trim() ||
            'Sin descripción adicional.',
        amount_cents:
            Math.round(
                Number(
                    form.value.amount,
                ) * 100,
            ),
        status: 'pending',
        issued_at: todayString(),
        paid_at: null,
        payment_reference_id:
            null,
        cancelled_at: null,
    });

    closeForm();
}

function markAsPaid(
    fine: LibraryFine,
) {
    if (
        fine.status !== 'pending'
    ) {
        return;
    }

    const paymentReference =
        window.prompt(
            'Referencia de pago:',
            `PAY-${Date.now()}`,
        );

    if (
        !paymentReference?.trim()
    ) {
        return;
    }

    const confirmed =
        window.confirm(
            `¿Registrar el pago de ${formatMoney(
                fine.amount_cents,
            )} para ${fine.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    fine.status = 'paid';
    fine.paid_at = todayString();
    fine.payment_reference_id =
        paymentReference.trim();
}

function cancelFine(
    fine: LibraryFine,
) {
    if (
        fine.status !== 'pending'
    ) {
        return;
    }

    const confirmed =
        window.confirm(
            `¿Cancelar la multa ${fine.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    fine.status = 'cancelled';
    fine.cancelled_at =
        todayString();
}

function viewDetails(
    fine: LibraryFine,
) {
    const content = [
        `Folio: ${fine.folio}`,
        `Estudiante: ${fine.student_id}`,
        `Préstamo: ${fine.loan_id}`,
        `Libro: ${fine.book_title}`,
        `Ejemplar: ${fine.copy_code}`,
        `Motivo: ${reasonLabel(
            fine.reason,
        )}`,
        `Descripción: ${fine.description}`,
        `Monto: ${formatMoney(
            fine.amount_cents,
        )}`,
        `Estado: ${statusLabel(
            fine.status,
        )}`,
        `Generada: ${formatDate(
            fine.issued_at,
        )}`,
        `Pagada: ${formatDate(
            fine.paid_at,
        )}`,
        `Referencia: ${
            fine.payment_reference_id ??
            'Sin referencia'
        }`,
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
                <span
                    class="section-label"
                >
                    BIBLIOTECA · MÓDULO 5.2
                </span>

                <h2>
                    Multas y adeudos
                </h2>

                <p>
                    Controla multas por
                    atraso, daños o pérdida
                    de ejemplares y consulta
                    su estado de pago.
                </p>
            </div>

            <div class="total-box">
                <span>
                    Adeudo pendiente
                </span>

                <strong>
                    {{
                        formatMoney(
                            pendingAmount,
                        )
                    }}
                </strong>

                <small>
                    multas pendientes
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
                    Adeudos activos
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
                    Pagos registrados
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
                    Adeudos anulados
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Total registros
                </span>

                <strong>
                    {{ fines.length }}
                </strong>

                <small>
                    Historial de multas
                </small>
            </article>
        </section>

        <section
            class="integration-notice"
        >
            <div>
                <strong>
                    Integración de pagos
                </strong>

                <p>
                    El frontend conserva una
                    referencia de pago, pero
                    posteriormente el cobro
                    real deberá realizarse
                    mediante el servicio del
                    Equipo 2.
                </p>
            </div>

            <span>
                Equipo 2
            </span>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Multas registradas
                    </h3>

                    <p>
                        Consulta adeudos,
                        pagos y referencias.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="
                        openCreateForm
                    "
                >
                    + Nueva multa
                </button>
            </div>

            <section
                v-if="showForm"
                class="form-panel"
            >
                <div class="form-header">
                    <div>
                        <span
                            class="form-label"
                        >
                            NUEVA MULTA
                        </span>

                        <h3>
                            Registrar adeudo
                        </h3>

                        <p>
                            Relaciona la multa
                            con un préstamo y
                            un estudiante.
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
                    class="fine-form"
                    @submit.prevent="
                        createFine
                    "
                >
                    <div
                        class="form-grid"
                    >
                        <div
                            class="form-field"
                        >
                            <label>
                                ID del estudiante
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    form.student_id
                                "
                                type="text"
                                placeholder="Ej. EST-0001"
                            />
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                ID del préstamo
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    form.loan_id
                                "
                                type="text"
                                placeholder="Ej. LOAN-001"
                            />
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                Libro
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    form.book_title
                                "
                                type="text"
                                placeholder="Título del libro"
                            />
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                Ejemplar
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    form.copy_code
                                "
                                type="text"
                                placeholder="Ej. EJ-001"
                            />
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                Motivo
                            </label>

                            <select
                                v-model="
                                    form.reason
                                "
                            >
                                <option
                                    value="overdue"
                                >
                                    Atraso
                                </option>

                                <option
                                    value="damage"
                                >
                                    Daño
                                </option>

                                <option
                                    value="lost"
                                >
                                    Pérdida
                                </option>

                                <option
                                    value="other"
                                >
                                    Otro
                                </option>
                            </select>
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                Monto (MXN)
                                <span>*</span>
                            </label>

                            <input
                                v-model.number="
                                    form.amount
                                "
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                            />
                        </div>

                        <div
                            class="form-field form-field-full"
                        >
                            <label>
                                Descripción
                            </label>

                            <textarea
                                v-model="
                                    form.description
                                "
                                rows="3"
                                placeholder="Describe el motivo de la multa..."
                            ></textarea>
                        </div>
                    </div>

                    <div
                        class="information-box"
                    >
                        <strong>
                            Importante:
                        </strong>

                        este formulario
                        solamente representa
                        el flujo del módulo.
                        Al conectar backend,
                        el préstamo y el
                        estudiante se
                        obtendrán de sus
                        registros reales.
                    </div>

                    <div
                        class="form-actions"
                    >
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeForm
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="primary-button"
                        >
                            Registrar multa
                        </button>
                    </div>
                </form>
            </section>

            <div class="filters">
                <div
                    class="search-field"
                >
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por folio, estudiante, préstamo, libro o ejemplar..."
                    />
                </div>

                <select
                    v-model="
                        reasonFilter
                    "
                >
                    <option value="all">
                        Todos los motivos
                    </option>

                    <option
                        value="overdue"
                    >
                        Atraso
                    </option>

                    <option
                        value="damage"
                    >
                        Daño
                    </option>

                    <option
                        value="lost"
                    >
                        Pérdida
                    </option>

                    <option
                        value="other"
                    >
                        Otro
                    </option>
                </select>

                <select
                    v-model="
                        statusFilter
                    "
                >
                    <option value="all">
                        Todos los estados
                    </option>

                    <option
                        value="pending"
                    >
                        Pendientes
                    </option>

                    <option value="paid">
                        Pagadas
                    </option>

                    <option
                        value="cancelled"
                    >
                        Canceladas
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredFines.length >
                    0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Estudiante</th>
                        <th>
                            Libro /
                            ejemplar
                        </th>
                        <th>Motivo</th>
                        <th>Monto</th>
                        <th>Generada</th>
                        <th>Estado</th>
                        <th>Pago</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                fine in
                                filteredFines
                            "
                        :key="
                                fine.id
                            "
                    >
                        <td>
                            <strong
                                class="folio"
                            >
                                {{
                                    fine.folio
                                }}
                            </strong>

                            <small
                                class="loan-id"
                            >
                                {{
                                    fine.loan_id
                                }}
                            </small>
                        </td>

                        <td>
                                <span
                                    class="student-id"
                                >
                                    {{
                                        fine.student_id
                                    }}
                                </span>
                        </td>

                        <td>
                            <div
                                class="book-info"
                            >
                                <strong>
                                    {{
                                        fine.book_title
                                    }}
                                </strong>

                                <small>
                                    {{
                                        fine.copy_code
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                                <span
                                    class="reason"
                                >
                                    {{
                                        reasonLabel(
                                            fine.reason,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <strong
                                class="amount"
                            >
                                {{
                                    formatMoney(
                                        fine.amount_cents,
                                    )
                                }}
                            </strong>
                        </td>

                        <td>
                            {{
                                formatDate(
                                    fine.issued_at,
                                )
                            }}
                        </td>

                        <td>
                                <span
                                    class="status"
                                    :class="
                                        statusClass(
                                            fine.status,
                                        )
                                    "
                                >
                                    {{
                                        statusLabel(
                                            fine.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <div
                                class="payment-info"
                            >
                                    <span>
                                        {{
                                            fine.payment_reference_id
                                            ?? '—'
                                        }}
                                    </span>

                                <small
                                    v-if="
                                            fine.paid_at
                                        "
                                >
                                    {{
                                        formatDate(
                                            fine.paid_at,
                                        )
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                            <div
                                class="actions"
                            >
                                <button
                                    type="button"
                                    class="action-button details"
                                    @click="
                                            viewDetails(
                                                fine,
                                            )
                                        "
                                >
                                    Ver
                                </button>

                                <button
                                    v-if="
                                            fine.status ===
                                            'pending'
                                        "
                                    type="button"
                                    class="action-button pay"
                                    @click="
                                            markAsPaid(
                                                fine,
                                            )
                                        "
                                >
                                    Registrar pago
                                </button>

                                <button
                                    v-if="
                                            fine.status ===
                                            'pending'
                                        "
                                    type="button"
                                    class="action-button cancel"
                                    @click="
                                            cancelFine(
                                                fine,
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
                    No se encontraron
                    multas
                </h3>

                <p>
                    Cambia los filtros o
                    registra una nueva
                    multa.
                </p>
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
    background:
        rgba(
            255,
            255,
            255,
            0.1
        );
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

.integration-notice {
    margin-top: 18px;
    padding: 14px 17px;
    display: flex;
    align-items: center;
    justify-content:
        space-between;
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
    justify-content:
        space-between;
    gap: 20px;
}

.panel-header {
    border-bottom:
        1px solid #e5e9ef;
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
    border-bottom:
        1px solid #e5e9ef;
    background: #fafcff;
}

.form-header {
    border-bottom:
        1px solid #e5e9ef;
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
    grid-template-columns:
        repeat(2, 1fr);
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
    box-shadow:
        0 0 0 3px
        rgba(
            57,
            112,
            193,
            0.08
        );
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
    justify-content:
        flex-end;
    gap: 9px;
    border-top:
        1px solid #e5e9ef;
}

.filters {
    padding: 15px 21px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom:
        1px solid #e5e9ef;
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
    border-top:
        1px solid #e9edf3;
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
        grid-template-columns:
            repeat(2, 1fr);
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
        flex-direction:
            column-reverse;
    }
}
</style>
