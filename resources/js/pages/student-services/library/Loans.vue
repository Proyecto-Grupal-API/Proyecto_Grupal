<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type LoanStatus =
    | 'active'
    | 'overdue'
    | 'returned';

interface Loan {
    id: string;
    copy_id: string;
    copy_code: string;
    book_title: string;
    student_id: string;
    borrowed_at: string;
    due_at: string;
    returned_at: string | null;
    status: LoanStatus;
    renewal_count: number;
    notes: string | null;
}

interface AvailableCopy {
    id: string;
    code: string;
    book_id: string | null;
    book_title: string;
    status: string;
}

const props = defineProps<{
    loans: Loan[];
    availableCopies: AvailableCopy[];
}>();

const search = ref('');

const statusFilter =
    ref<'all' | LoanStatus>('all');

const showForm = ref(false);

const processingActionId =
    ref<string | null>(null);

const form = useForm({
    copy_id: '',
    student_id: '',
    loan_days: 7,
    notes: '',
});

const formErrors = computed(
    () =>
        form.errors as Record<
            string,
            string
        >,
);

const selectedCopy = computed(() => {
    return (
        props.availableCopies.find(
            (copy) =>
                copy.id ===
                form.copy_id,
        ) ?? null
    );
});

const activeCount = computed(() => {
    return props.loans.filter(
        (loan) =>
            loan.status === 'active',
    ).length;
});

const overdueCount = computed(() => {
    return props.loans.filter(
        (loan) =>
            loan.status === 'overdue',
    ).length;
});

const returnedCount = computed(() => {
    return props.loans.filter(
        (loan) =>
            loan.status === 'returned',
    ).length;
});

const renewedCount = computed(() => {
    return props.loans.filter(
        (loan) =>
            loan.renewal_count > 0,
    ).length;
});

const loanFolio = (
    loan: Loan,
): string => {
    const suffix = loan.id
        .slice(-6)
        .toUpperCase();

    return `PRE-${suffix}`;
};

const filteredLoans = computed(() => {
    const value = search.value
        .trim()
        .toLowerCase();

    return props.loans.filter(
        (loan) => {
            const matchesSearch =
                value === '' ||
                loanFolio(loan)
                    .toLowerCase()
                    .includes(value) ||
                loan.copy_code
                    .toLowerCase()
                    .includes(value) ||
                loan.book_title
                    .toLowerCase()
                    .includes(value) ||
                loan.student_id
                    .toLowerCase()
                    .includes(value);

            const matchesStatus =
                statusFilter.value ===
                'all' ||
                loan.status ===
                statusFilter.value;

            return (
                matchesSearch &&
                matchesStatus
            );
        },
    );
});

const statusLabel = (
    status: LoanStatus,
): string => {
    const labels: Record<
        LoanStatus,
        string
    > = {
        active: 'Activo',
        overdue: 'Vencido',
        returned: 'Devuelto',
    };

    return labels[status];
};

const statusClass = (
    status: LoanStatus,
): string => {
    return {
        active: 'status-active',
        overdue: 'status-overdue',
        returned: 'status-returned',
    }[status];
};

const formatDate = (
    value: string | null,
): string => {
    if (!value) {
        return '—';
    }

    const date = value.slice(0, 10);

    const [year, month, day] =
        date.split('-');

    return `${day}/${month}/${year}`;
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

const createLoan = () => {
    if (!form.copy_id) {
        window.alert(
            'Selecciona un ejemplar.',
        );

        return;
    }

    if (!form.student_id.trim()) {
        window.alert(
            'Ingresa el ID del estudiante.',
        );

        return;
    }

    form.student_id =
        form.student_id
            .trim()
            .toUpperCase();

    form.notes =
        form.notes.trim();

    form.post(
        '/servicios-estudiante/biblioteca/prestamos',
        {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();

                window.alert(
                    'Préstamo registrado correctamente.',
                );
            },
        },
    );
};

const renewLoan = (
    loan: Loan,
) => {
    if (
        loan.status !== 'active'
    ) {
        window.alert(
            'Solo se pueden renovar préstamos activos.',
        );

        return;
    }

    if (
        loan.renewal_count >= 2
    ) {
        window.alert(
            'Este préstamo alcanzó el máximo de 2 renovaciones.',
        );

        return;
    }

    const confirmed =
        window.confirm(
            `¿Renovar el préstamo ${loanFolio(
                loan,
            )} por 7 días adicionales?`,
        );

    if (!confirmed) {
        return;
    }

    processingActionId.value =
        loan.id;

    router.patch(
        `/servicios-estudiante/biblioteca/prestamos/${loan.id}/renovar`,
        {
            additional_days: 7,
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert(
                    'Préstamo renovado correctamente.',
                );
            },

            onError: (errors) => {
                window.alert(
                    errors.loan ??
                    'No fue posible renovar el préstamo.',
                );
            },

            onFinish: () => {
                processingActionId.value =
                    null;
            },
        },
    );
};

const returnLoan = (
    loan: Loan,
) => {
    if (
        loan.status !== 'active' &&
        loan.status !== 'overdue'
    ) {
        return;
    }

    const confirmed =
        window.confirm(
            `¿Registrar la devolución del ejemplar ${loan.copy_code}?`,
        );

    if (!confirmed) {
        return;
    }

    processingActionId.value =
        loan.id;

    router.patch(
        `/servicios-estudiante/biblioteca/prestamos/${loan.id}/devolver`,
        {
            notes: loan.notes,
        },
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert(
                    'Devolución registrada correctamente.',
                );
            },

            onError: (errors) => {
                window.alert(
                    errors.loan ??
                    'No fue posible registrar la devolución.',
                );
            },

            onFinish: () => {
                processingActionId.value =
                    null;
            },
        },
    );
};

const viewDetails = (
    loan: Loan,
) => {
    const text = [
        `Folio: ${loanFolio(loan)}`,
        `Libro: ${loan.book_title}`,
        `Ejemplar: ${loan.copy_code}`,
        `Estudiante: ${loan.student_id}`,
        `Préstamo: ${formatDate(
            loan.borrowed_at,
        )}`,
        `Vencimiento: ${formatDate(
            loan.due_at,
        )}`,
        `Devolución: ${formatDate(
            loan.returned_at,
        )}`,
        `Estado: ${statusLabel(
            loan.status,
        )}`,
        `Renovaciones: ${loan.renewal_count}/2`,
        `Notas: ${
            loan.notes ??
            'Sin notas'
        }`,
    ].join('\n');

    window.alert(text);
};
</script>

<template>
    <StudentServicesLayout
        title="Préstamos de biblioteca"
        subtitle="Control de préstamos, devoluciones y renovaciones"
    >
        <section class="summary">
            <div>
                <span class="section-label">
                    BIBLIOTECA · MÓDULO 5.1
                </span>

                <h2>
                    Gestión de préstamos
                </h2>

                <p>
                    Consulta los préstamos de
                    ejemplares, registra devoluciones
                    y controla renovaciones.
                </p>
            </div>

            <div class="total-box">
                <span>
                    Total registrados
                </span>

                <strong>
                    {{ loans.length }}
                </strong>

                <small>
                    préstamos
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Activos
                </span>

                <strong>
                    {{ activeCount }}
                </strong>

                <small>
                    Préstamos vigentes
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Vencidos
                </span>

                <strong>
                    {{ overdueCount }}
                </strong>

                <small>
                    Requieren atención
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Devueltos
                </span>

                <strong>
                    {{ returnedCount }}
                </strong>

                <small>
                    Historial completado
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Renovados
                </span>

                <strong>
                    {{ renewedCount }}
                </strong>

                <small>
                    Con al menos una renovación
                </small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Préstamos registrados
                    </h3>

                    <p>
                        Datos almacenados en MongoDB.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Nuevo préstamo
                </button>
            </div>

            <section
                v-if="showForm"
                class="form-panel"
            >
                <div class="form-header">
                    <div>
                        <span class="form-label">
                            NUEVO PRÉSTAMO
                        </span>

                        <h3>
                            Registrar préstamo
                        </h3>

                        <p>
                            Selecciona un ejemplar
                            disponible e indica el
                            estudiante.
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
                    class="loan-form"
                    @submit.prevent="createLoan"
                >
                    <div class="form-grid">
                        <div
                            class="form-field form-field-full"
                        >
                            <label for="copy_id">
                                Ejemplar disponible
                                <span>*</span>
                            </label>

                            <select
                                id="copy_id"
                                v-model="form.copy_id"
                            >
                                <option value="">
                                    Selecciona un ejemplar
                                </option>

                                <option
                                    v-for="copy in availableCopies"
                                    :key="copy.id"
                                    :value="copy.id"
                                >
                                    {{ copy.book_title }}
                                    ·
                                    {{ copy.code }}
                                </option>
                            </select>

                            <small
                                v-if="form.errors.copy_id"
                                class="form-error"
                            >
                                {{
                                    form.errors.copy_id
                                }}
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
                                v-if="
                                    form.errors.student_id
                                "
                                class="form-error"
                            >
                                {{
                                    form.errors
                                        .student_id
                                }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="loan_days">
                                Duración
                            </label>

                            <select
                                id="loan_days"
                                v-model="form.loan_days"
                            >
                                <option :value="3">
                                    3 días
                                </option>

                                <option :value="7">
                                    7 días
                                </option>

                                <option :value="14">
                                    14 días
                                </option>
                            </select>
                        </div>

                        <div
                            class="form-field form-field-full"
                        >
                            <label>
                                Libro seleccionado
                            </label>

                            <input
                                type="text"
                                :value="
                                    selectedCopy
                                        ?.book_title ??
                                    ''
                                "
                                placeholder="Selecciona un ejemplar"
                                disabled
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Código del ejemplar
                            </label>

                            <input
                                type="text"
                                :value="
                                    selectedCopy
                                        ?.code ??
                                    ''
                                "
                                disabled
                            />
                        </div>

                        <div class="form-field">
                            <label>
                                Renovaciones
                            </label>

                            <input
                                type="text"
                                value="Máximo 2"
                                disabled
                            />
                        </div>

                        <div
                            class="form-field form-field-full"
                        >
                            <label for="notes">
                                Notas
                            </label>

                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Observaciones opcionales..."
                            ></textarea>
                        </div>
                    </div>

                    <div
                        v-if="availableCopies.length === 0"
                        class="warning-box"
                    >
                        Actualmente no hay
                        ejemplares disponibles para
                        realizar un nuevo préstamo.
                    </div>

                    <div
                        v-if="formErrors.loan"
                        class="error-box"
                    >
                        {{ formErrors.loan }}
                    </div>

                    <div class="information-box">
                        <strong>
                            MongoDB:
                        </strong>

                        al registrar el préstamo se
                        crea el documento en
                        <code>loans</code> y el
                        ejemplar cambia de
                        <code>available</code> a
                        <code>loaned</code>.
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
                            :disabled="
                                form.processing ||
                                availableCopies.length ===
                                    0
                            "
                        >
                            {{
                                form.processing
                                    ? 'Registrando...'
                                    : 'Registrar préstamo'
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
                        placeholder="Buscar por folio, libro, ejemplar o estudiante..."
                    />
                </div>

                <select
                    v-model="statusFilter"
                >
                    <option value="all">
                        Todos los estados
                    </option>

                    <option value="active">
                        Activos
                    </option>

                    <option value="overdue">
                        Vencidos
                    </option>

                    <option value="returned">
                        Devueltos
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredLoans.length >
                    0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>
                            Libro / ejemplar
                        </th>
                        <th>Estudiante</th>
                        <th>Préstamo</th>
                        <th>Vencimiento</th>
                        <th>Renovaciones</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                loan in
                                filteredLoans
                            "
                        :key="loan.id"
                    >
                        <td>
                            <strong
                                class="folio"
                            >
                                {{
                                    loanFolio(
                                        loan,
                                    )
                                }}
                            </strong>
                        </td>

                        <td>
                            <div
                                class="book-info"
                            >
                                <strong>
                                    {{
                                        loan.book_title
                                    }}
                                </strong>

                                <small>
                                    {{
                                        loan.copy_code
                                    }}
                                </small>
                            </div>
                        </td>

                        <td>
                                <span
                                    class="student-id"
                                >
                                    {{
                                        loan.student_id
                                    }}
                                </span>
                        </td>

                        <td>
                            {{
                                formatDate(
                                    loan.borrowed_at,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                formatDate(
                                    loan.due_at,
                                )
                            }}
                        </td>

                        <td>
                                <span
                                    class="renewal-count"
                                >
                                    {{
                                        loan.renewal_count
                                    }}/2
                                </span>
                        </td>

                        <td>
                                <span
                                    class="status"
                                    :class="
                                        statusClass(
                                            loan.status,
                                        )
                                    "
                                >
                                    {{
                                        statusLabel(
                                            loan.status,
                                        )
                                    }}
                                </span>
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
                                                loan,
                                            )
                                        "
                                >
                                    Ver
                                </button>

                                <button
                                    v-if="
                                            loan.status ===
                                            'active'
                                        "
                                    type="button"
                                    class="action-button renew"
                                    :disabled="
                                            processingActionId ===
                                            loan.id
                                        "
                                    @click="
                                            renewLoan(
                                                loan,
                                            )
                                        "
                                >
                                    Renovar
                                </button>

                                <button
                                    v-if="
                                            loan.status ===
                                                'active' ||
                                            loan.status ===
                                                'overdue'
                                        "
                                    type="button"
                                    class="action-button return"
                                    :disabled="
                                            processingActionId ===
                                            loan.id
                                        "
                                    @click="
                                            returnLoan(
                                                loan,
                                            )
                                        "
                                >
                                    Devolver
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
                    No se encontraron préstamos
                </h3>

                <p>
                    Cambia los filtros o
                    registra un nuevo préstamo.
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
    min-width: 150px;
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
    font-size: 28px;
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
    border:
        1px solid #2c63b7;
    background: #2c63b7;
    color: white;
}

.primary-button:disabled {
    cursor: default;
    opacity: 0.55;
}

.secondary-button {
    border:
        1px solid #d6dee9;
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
    border:
        1px solid #d8e0ea;
    border-radius: 7px;
    background: white;
    color: #657389;
    font-size: 20px;
    cursor: pointer;
}

.loan-form {
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
    border:
        1px solid #d4dde8;
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
.warning-box,
.error-box {
    margin-top: 16px;
    padding: 11px 13px;
    border-radius: 7px;
    font-size: 10px;
    line-height: 1.5;
}

.information-box {
    border:
        1px solid #d5e1f1;
    background: #eef4fc;
    color: #657690;
}

.information-box strong {
    color: #315a9f;
}

.information-box code {
    color: #244c88;
}

.warning-box {
    border:
        1px solid #ead7aa;
    background: #fff8e6;
    color: #86631b;
}

.error-box {
    border:
        1px solid #e8c9cc;
    background: #fcedee;
    color: #9d434c;
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
    padding: 15px 21px;
    display: flex;
    align-items: center;
    justify-content: space-between;
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
    border:
        1px solid #d7dfe9;
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
    min-width: 180px;
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

.renewal-count {
    display: inline-flex;
    padding: 4px 7px;
    border-radius: 6px;
    background: #eef2f7;
    color: #5d6c81;
    font-size: 9px;
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

.status-active {
    background: #e4f6ec;
    color: #217a4e;
}

.status-overdue {
    background: #fbe7e7;
    color: #aa3c3c;
}

.status-returned {
    background: #eaf0fc;
    color: #315fa6;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 190px;
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
    border:
        1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.renew {
    border:
        1px solid #ead7aa;
    background: #fff8e6;
    color: #936814;
}

.action-button.return {
    border:
        1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
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
        grid-template-columns:
            repeat(2, 1fr);
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
        flex-direction:
            column-reverse;
    }
}
</style>
