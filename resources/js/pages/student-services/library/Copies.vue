<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Copy {
    id: string;
    book_id: string;
    book_title: string;
    book_isbn: string | null;
    code: string;
    barcode: string | null;
    location: string | null;
    status: string;
    notes: string | null;
}

interface Book {
    id: string;
    title: string;
    isbn: string | null;
}

const props = defineProps<{
    copies: Copy[];
    books: Book[];
}>();

const showForm = ref(false);
const editingCopyId = ref<string | null>(null);
const editingCopyStatus = ref<string | null>(null);

const form = useForm({
    book_id: '',
    code: '',
    barcode: '',
    location: '',
    notes: '',
});

const availableCount = computed(() => {
    return props.copies.filter((copy) => copy.status === 'available').length;
});

const loanedCount = computed(() => {
    return props.copies.filter((copy) => copy.status === 'loaned').length;
});

const reservedCount = computed(() => {
    return props.copies.filter((copy) => copy.status === 'reserved').length;
});

const maintenanceCount = computed(() => {
    return props.copies.filter((copy) => copy.status === 'maintenance').length;
});

const lostCount = computed(() => {
    return props.copies.filter((copy) => copy.status === 'lost').length;
});

const statusLabel = (status: string) => {
    const labels: Record<string, string> = {
        available: 'Disponible',
        loaned: 'Prestado',
        reserved: 'Reservado',
        maintenance: 'Mantenimiento',
        lost: 'Extraviado',
    };

    return labels[status] ?? status;
};

const openCreateForm = () => {
    editingCopyId.value = null;
    editingCopyStatus.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = true;
};

const openEditForm = (copy: Copy) => {
    editingCopyId.value = copy.id;
    editingCopyStatus.value = copy.status;

    form.clearErrors();

    form.book_id = copy.book_id;
    form.code = copy.code;
    form.barcode = copy.barcode ?? '';
    form.location = copy.location ?? '';
    form.notes = copy.notes ?? '';

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
};

const closeForm = () => {
    editingCopyId.value = null;
    editingCopyStatus.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = false;
};

const submitCopy = () => {
    if (editingCopyId.value === null) {
        form.post('/servicios-estudiante/biblioteca/ejemplares', {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        });

        return;
    }

    form.patch(
        `/servicios-estudiante/biblioteca/ejemplares/${editingCopyId.value}`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        },
    );
};

const sendToMaintenance = (copy: Copy) => {
    const confirmed = window.confirm(
        `¿Enviar el ejemplar ${copy.code} a mantenimiento?`,
    );

    if (!confirmed) {
        return;
    }

    router.patch(
        `/servicios-estudiante/biblioteca/ejemplares/${copy.id}/mantenimiento`,
        {},
        {
            preserveScroll: true,

            onError: (errors) => {
                if (errors.status) {
                    window.alert(errors.status);
                }
            },
        },
    );
};

const markAsLost = (copy: Copy) => {
    const confirmed = window.confirm(
        `¿Marcar el ejemplar ${copy.code} como extraviado?`,
    );

    if (!confirmed) {
        return;
    }

    router.patch(
        `/servicios-estudiante/biblioteca/ejemplares/${copy.id}/extraviado`,
        {},
        {
            preserveScroll: true,

            onError: (errors) => {
                if (errors.status) {
                    window.alert(errors.status);
                }
            },
        },
    );
};

const restoreAvailable = (copy: Copy) => {
    const confirmed = window.confirm(
        `¿Marcar nuevamente como disponible el ejemplar ${copy.code}?`,
    );

    if (!confirmed) {
        return;
    }

    router.patch(
        `/servicios-estudiante/biblioteca/ejemplares/${copy.id}/disponible`,
        {},
        {
            preserveScroll: true,

            onError: (errors) => {
                if (errors.status) {
                    window.alert(errors.status);
                }
            },
        },
    );
};
</script>

<template>
    <StudentServicesLayout
        title="Ejemplares"
        subtitle="Control de copias físicas de la biblioteca"
    >
        <section class="summary">
            <div>
                <span class="section-label"> BIBLIOTECA </span>

                <h2>Ejemplares físicos</h2>

                <p>
                    Administra las copias físicas registradas en el catálogo de
                    biblioteca.
                </p>
            </div>

            <div class="total-box">
                <span> Total de ejemplares </span>

                <strong>
                    {{ copies.length }}
                </strong>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>Disponibles</span>
                <strong>{{ availableCount }}</strong>
                <small>Listos para préstamo</small>
            </article>

            <article class="stat-card">
                <span>Prestados</span>
                <strong>{{ loanedCount }}</strong>
                <small>En préstamo actualmente</small>
            </article>

            <article class="stat-card">
                <span>Reservados</span>
                <strong>{{ reservedCount }}</strong>
                <small>Apartados para estudiantes</small>
            </article>

            <article class="stat-card">
                <span>Mantenimiento</span>
                <strong>{{ maintenanceCount }}</strong>
                <small>No disponibles temporalmente</small>
            </article>

            <article class="stat-card">
                <span>Extraviados</span>
                <strong>{{ lostCount }}</strong>
                <small>Fuera de disponibilidad</small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <h3>Ejemplares registrados</h3>

                    <p>
                        Cada registro representa una copia física de un libro.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Agregar ejemplar
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="form-label">
                            {{
                                editingCopyId === null
                                    ? 'NUEVO EJEMPLAR'
                                    : 'EDITAR EJEMPLAR'
                            }}
                        </span>

                        <h3>
                            {{
                                editingCopyId === null
                                    ? 'Registrar copia física'
                                    : 'Editar copia física'
                            }}
                        </h3>

                        <p>
                            {{
                                editingCopyId === null
                                    ? 'Registra los datos del nuevo ejemplar.'
                                    : 'Modifica los datos administrativos del ejemplar.'
                            }}
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

                <form class="copy-form" @submit.prevent="submitCopy">
                    <div class="form-grid">
                        <div class="form-field form-field-full">
                            <label for="book_id">
                                Libro
                                <span>*</span>
                            </label>

                            <select
                                id="book_id"
                                v-model="form.book_id"
                                :disabled="
                                    editingCopyStatus === 'loaned' ||
                                    editingCopyStatus === 'reserved'
                                "
                            >
                                <option value="">Selecciona un libro</option>

                                <option
                                    v-for="book in books"
                                    :key="book.id"
                                    :value="book.id"
                                >
                                    {{ book.title }}
                                    {{ book.isbn ? ` - ${book.isbn}` : '' }}
                                </option>
                            </select>

                            <small
                                v-if="
                                    editingCopyStatus === 'loaned' ||
                                    editingCopyStatus === 'reserved'
                                "
                                class="field-help"
                            >
                                No se puede cambiar el libro de un ejemplar
                                prestado o reservado.
                            </small>

                            <small
                                v-if="form.errors.book_id"
                                class="field-error"
                            >
                                {{ form.errors.book_id }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="code">
                                Código del ejemplar
                                <span>*</span>
                            </label>

                            <input
                                id="code"
                                v-model="form.code"
                                type="text"
                                placeholder="LIB-0002"
                            />

                            <small v-if="form.errors.code" class="field-error">
                                {{ form.errors.code }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="barcode"> Código de barras </label>

                            <input
                                id="barcode"
                                v-model="form.barcode"
                                type="text"
                                placeholder="750000000002"
                            />

                            <small
                                v-if="form.errors.barcode"
                                class="field-error"
                            >
                                {{ form.errors.barcode }}
                            </small>
                        </div>

                        <div class="form-field form-field-full">
                            <label for="location">
                                Ubicación
                                <span>*</span>
                            </label>

                            <input
                                id="location"
                                v-model="form.location"
                                type="text"
                                placeholder="Estante A-02"
                            />

                            <small
                                v-if="form.errors.location"
                                class="field-error"
                            >
                                {{ form.errors.location }}
                            </small>
                        </div>

                        <div class="form-field form-field-full">
                            <label for="notes"> Notas </label>

                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Observaciones del ejemplar..."
                            />

                            <small v-if="form.errors.notes" class="field-error">
                                {{ form.errors.notes }}
                            </small>
                        </div>
                    </div>

                    <div v-if="editingCopyId === null" class="information-box">
                        Los nuevos ejemplares se registran automáticamente como
                        <strong>Disponibles</strong>.
                    </div>

                    <div class="form-actions">
                        <button
                            type="button"
                            class="secondary-button"
                            :disabled="form.processing"
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
                                    ? 'Guardando...'
                                    : editingCopyId === null
                                      ? 'Guardar ejemplar'
                                      : 'Guardar cambios'
                            }}
                        </button>
                    </div>
                </form>
            </section>

            <div v-if="copies.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Libro</th>
                            <th>ISBN</th>
                            <th>Código de barras</th>
                            <th>Ubicación</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="copy in copies" :key="copy.id">
                            <td>
                                <strong class="copy-code">
                                    {{ copy.code }}
                                </strong>
                            </td>

                            <td>
                                <div class="book-info">
                                    <strong>
                                        {{ copy.book_title }}
                                    </strong>

                                    <small v-if="copy.notes">
                                        {{ copy.notes }}
                                    </small>
                                </div>
                            </td>

                            <td>
                                {{ copy.book_isbn ?? 'No registrado' }}
                            </td>

                            <td>
                                {{ copy.barcode ?? 'No registrado' }}
                            </td>

                            <td>
                                {{ copy.location ?? 'Sin ubicación' }}
                            </td>

                            <td>
                                <span
                                    class="status"
                                    :class="`status-${copy.status}`"
                                >
                                    {{ statusLabel(copy.status) }}
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-button edit"
                                        @click="openEditForm(copy)"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        v-if="copy.status === 'available'"
                                        type="button"
                                        class="action-button maintenance"
                                        @click="sendToMaintenance(copy)"
                                    >
                                        Mantenimiento
                                    </button>

                                    <button
                                        v-if="copy.status === 'maintenance'"
                                        type="button"
                                        class="action-button available"
                                        @click="restoreAvailable(copy)"
                                    >
                                        Disponible
                                    </button>

                                    <button
                                        v-if="
                                            copy.status === 'available' ||
                                            copy.status === 'maintenance'
                                        "
                                        type="button"
                                        class="action-button lost"
                                        @click="markAsLost(copy)"
                                    >
                                        Extraviado
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                <h3>No hay ejemplares registrados</h3>

                <p>
                    Utiliza "Agregar ejemplar" para registrar la primera copia
                    física.
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
    border: 1px solid #dfe5ee;
    border-radius: 12px;
    background: white;
}

.section-label,
.form-label {
    display: block;
    margin-bottom: 7px;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.summary h2 {
    margin: 0;
    color: #25324a;
    font-size: 23px;
}

.summary p {
    margin: 7px 0 0;
    color: #8290a5;
    font-size: 12px;
}

.total-box {
    min-width: 150px;
    padding: 16px 19px;
    border-radius: 10px;
    background: #edf3fc;
}

.total-box span {
    display: block;
    color: #6980a0;
    font-size: 10px;
}

.total-box strong {
    display: block;
    margin-top: 5px;
    color: #284f99;
    font-size: 28px;
}

.stats-grid {
    margin-top: 18px;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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
    opacity: 0.6;
    cursor: not-allowed;
}

.form-panel {
    border-bottom: 1px solid #e5e9ef;
    background: #fafcff;
}

.form-header {
    border-bottom: 1px solid #e5e9ef;
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

.copy-form {
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

.form-field select:disabled {
    background: #f1f3f6;
    color: #768297;
}

.form-field textarea {
    resize: vertical;
}

.field-error {
    display: block;
    margin-top: 5px;
    color: #b83b3b;
    font-size: 9px;
    font-weight: 700;
}

.field-help {
    display: block;
    margin-top: 5px;
    color: #8794a7;
    font-size: 9px;
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
    color: #24804f;
}

.form-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid #e5e9ef;
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

.copy-code {
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
    max-width: 210px;
    color: #99a4b4;
    font-size: 9px;
}

.status {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
    white-space: nowrap;
}

.status-available {
    background: #e4f6ec;
    color: #217a4e;
}

.status-loaned {
    background: #eaf0fc;
    color: #315fa6;
}

.status-reserved {
    background: #fff4d9;
    color: #906800;
}

.status-maintenance {
    background: #ffecdf;
    color: #a1501d;
}

.status-lost {
    background: #fbe7e7;
    color: #aa3c3c;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 160px;
}

.action-button {
    min-height: 29px;
    padding: 0 8px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.action-button.edit {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.maintenance {
    border: 1px solid #ebd2b9;
    background: #fff5e9;
    color: #96561d;
}

.action-button.available {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
}

.action-button.lost {
    border: 1px solid #eccaca;
    background: #fff0f0;
    color: #a33e3e;
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

@media (max-width: 1150px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 750px) {
    .summary {
        align-items: flex-start;
        flex-direction: column;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
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
