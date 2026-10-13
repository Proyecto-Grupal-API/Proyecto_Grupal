<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Book {
    id: string;
    isbn: string | null;
    title: string;
    authors: string[];
    publisher: string | null;
    edition: string | null;
    publication_year: number | null;
    category: string | null;
    description: string | null;
    cover: string | null;
    status: string;
}

defineProps<{
    books: Book[];
}>();

const showForm = ref(false);
const editingBookId = ref<string | null>(null);

const form = useForm({
    isbn: '',
    title: '',
    authors: '',
    publisher: '',
    edition: '',
    publication_year: '',
    category: '',
    description: '',
});

const openCreateForm = () => {
    editingBookId.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = true;
};

const openEditForm = (book: Book) => {
    editingBookId.value = book.id;

    form.clearErrors();

    form.isbn = book.isbn ?? '';
    form.title = book.title;
    form.authors = book.authors.join(', ');
    form.publisher = book.publisher ?? '';
    form.edition = book.edition ?? '';
    form.publication_year = book.publication_year
        ? String(book.publication_year)
        : '';
    form.category = book.category ?? '';
    form.description = book.description ?? '';

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
};

const closeForm = () => {
    editingBookId.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = false;
};

const submitBook = () => {
    if (editingBookId.value === null) {
        form.post('/servicios-estudiante/biblioteca/libros', {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        });

        return;
    }

    form.patch(
        `/servicios-estudiante/biblioteca/libros/${editingBookId.value}`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        },
    );
};

const deactivateBook = (book: Book) => {
    const confirmed = window.confirm(
        `¿Deseas desactivar "${book.title}"?\n\n` +
            'El libro no se eliminará de la base de datos.',
    );

    if (!confirmed) {
        return;
    }

    router.patch(
        `/servicios-estudiante/biblioteca/libros/${book.id}/desactivar`,
        {},
        {
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <StudentServicesLayout
        title="Biblioteca"
        subtitle="Catálogo general de libros"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    EQUIPO 5 · SERVICIOS AL ESTUDIANTE
                </span>

                <h2>Biblioteca digital del campus</h2>

                <p>
                    Administra el catálogo de libros, ejemplares, préstamos,
                    reservas y servicios relacionados con la biblioteca
                    universitaria.
                </p>
            </div>

            <div class="hero-stat">
                <span class="hero-stat-label"> Libros activos </span>

                <strong>
                    {{ books.length }}
                </strong>
            </div>
        </section>

        <section class="toolbar">
            <div>
                <h3>Catálogo</h3>

                <p>Libros activos registrados actualmente en el sistema.</p>
            </div>

            <button
                type="button"
                class="primary-button"
                @click="openCreateForm"
            >
                <span>+</span>
                Agregar libro
            </button>
        </section>

        <section v-if="showForm" class="form-panel">
            <div class="form-header">
                <div>
                    <span class="section-label">
                        {{
                            editingBookId === null
                                ? 'NUEVO REGISTRO'
                                : 'EDITAR REGISTRO'
                        }}
                    </span>

                    <h3>
                        {{
                            editingBookId === null
                                ? 'Agregar libro'
                                : 'Editar libro'
                        }}
                    </h3>

                    <p>
                        {{
                            editingBookId === null
                                ? 'Registra la información general del libro.'
                                : 'Modifica la información del libro seleccionado.'
                        }}
                    </p>
                </div>

                <button type="button" class="close-button" @click="closeForm">
                    ×
                </button>
            </div>

            <form class="book-form" @submit.prevent="submitBook">
                <div class="form-grid">
                    <div class="form-field">
                        <label for="title">
                            Título
                            <span>*</span>
                        </label>

                        <input
                            id="title"
                            v-model="form.title"
                            type="text"
                            placeholder="Ej. Clean Code"
                        />

                        <small v-if="form.errors.title" class="field-error">
                            {{ form.errors.title }}
                        </small>
                    </div>

                    <div class="form-field">
                        <label for="isbn"> ISBN </label>

                        <input
                            id="isbn"
                            v-model="form.isbn"
                            type="text"
                            placeholder="9780132350884"
                        />

                        <small v-if="form.errors.isbn" class="field-error">
                            {{ form.errors.isbn }}
                        </small>
                    </div>

                    <div class="form-field form-field-full">
                        <label for="authors">
                            Autor o autores
                            <span>*</span>
                        </label>

                        <input
                            id="authors"
                            v-model="form.authors"
                            type="text"
                            placeholder="Robert C. Martin, Martin Fowler"
                        />

                        <small class="field-help">
                            Si hay varios autores, sepáralos con comas.
                        </small>

                        <small v-if="form.errors.authors" class="field-error">
                            {{ form.errors.authors }}
                        </small>
                    </div>

                    <div class="form-field">
                        <label for="publisher"> Editorial </label>

                        <input
                            id="publisher"
                            v-model="form.publisher"
                            type="text"
                            placeholder="Prentice Hall"
                        />

                        <small v-if="form.errors.publisher" class="field-error">
                            {{ form.errors.publisher }}
                        </small>
                    </div>

                    <div class="form-field">
                        <label for="edition"> Edición </label>

                        <input
                            id="edition"
                            v-model="form.edition"
                            type="text"
                            placeholder="1"
                        />

                        <small v-if="form.errors.edition" class="field-error">
                            {{ form.errors.edition }}
                        </small>
                    </div>

                    <div class="form-field">
                        <label for="publication_year">
                            Año de publicación
                        </label>

                        <input
                            id="publication_year"
                            v-model="form.publication_year"
                            type="number"
                            min="1000"
                            max="2100"
                            placeholder="2008"
                        />

                        <small
                            v-if="form.errors.publication_year"
                            class="field-error"
                        >
                            {{ form.errors.publication_year }}
                        </small>
                    </div>

                    <div class="form-field">
                        <label for="category"> Categoría </label>

                        <input
                            id="category"
                            v-model="form.category"
                            type="text"
                            placeholder="Programación"
                        />

                        <small v-if="form.errors.category" class="field-error">
                            {{ form.errors.category }}
                        </small>
                    </div>

                    <div class="form-field form-field-full">
                        <label for="description"> Descripción </label>

                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="4"
                            placeholder="Descripción general del libro..."
                        />

                        <small
                            v-if="form.errors.description"
                            class="field-error"
                        >
                            {{ form.errors.description }}
                        </small>
                    </div>
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
                                : editingBookId === null
                                  ? 'Guardar libro'
                                  : 'Guardar cambios'
                        }}
                    </button>
                </div>
            </form>
        </section>

        <section v-if="books.length > 0" class="books-grid">
            <article v-for="book in books" :key="book.id" class="book-card">
                <div class="book-cover">
                    <span>
                        {{ book.title.charAt(0).toUpperCase() }}
                    </span>
                </div>

                <div class="book-content">
                    <div class="book-top">
                        <span class="book-category">
                            {{ book.category ?? 'Sin categoría' }}
                        </span>

                        <span class="book-status"> Activo </span>
                    </div>

                    <h4>
                        {{ book.title }}
                    </h4>

                    <p class="authors">
                        {{
                            book.authors.length
                                ? book.authors.join(', ')
                                : 'Autor no registrado'
                        }}
                    </p>

                    <div class="book-data">
                        <div>
                            <span>ISBN</span>

                            <strong>
                                {{ book.isbn ?? 'No registrado' }}
                            </strong>
                        </div>

                        <div>
                            <span>Editorial</span>

                            <strong>
                                {{ book.publisher ?? 'No registrada' }}
                            </strong>
                        </div>

                        <div>
                            <span>Año</span>

                            <strong>
                                {{ book.publication_year ?? 'N/A' }}
                            </strong>
                        </div>

                        <div>
                            <span>Edición</span>

                            <strong>
                                {{ book.edition ?? 'N/A' }}
                            </strong>
                        </div>
                    </div>

                    <p v-if="book.description" class="description">
                        {{ book.description }}
                    </p>

                    <div class="book-actions">
                        <button
                            type="button"
                            class="edit-button"
                            @click="openEditForm(book)"
                        >
                            Editar
                        </button>

                        <button
                            type="button"
                            class="deactivate-button"
                            @click="deactivateBook(book)"
                        >
                            Desactivar
                        </button>
                    </div>
                </div>
            </article>
        </section>

        <section v-else class="empty-state">
            <div class="empty-icon">▤</div>

            <h3>No hay libros activos</h3>

            <p>
                Utiliza el botón "Agregar libro" para crear un registro en el
                catálogo.
            </p>
        </section>
    </StudentServicesLayout>
</template>

<style scoped>
.hero {
    min-height: 180px;
    padding: 30px 34px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;
    border-radius: 22px;
    background: linear-gradient(115deg, #00358b 0%, #0754a5 100%);
    color: white;
    box-shadow: 0 18px 40px rgba(0, 53, 139, 0.16);
}

.hero-label,
.section-label {
    display: block;
    margin-bottom: 10px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.15em;
}

.hero-label {
    color: #b9d7ff;
}

.section-label {
    color: #00358b;
}

.hero h2 {
    margin: 0;
    font-size: 30px;
    line-height: 1.18;
    font-weight: 800;
}

.hero p {
    max-width: 650px;
    margin: 12px 0 0;
    color: #d9e9ff;
    font-size: 14px;
    line-height: 1.6;
}

.hero-stat {
    min-width: 190px;
    padding: 22px 24px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-stat-label {
    display: block;
    margin-bottom: 6px;
    color: #d4e4fb;
    font-size: 12px;
}

.hero-stat strong {
    font-size: 34px;
}

.toolbar {
    margin-top: 26px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid #e0e6ef;
    border-radius: 16px;
    background: white;
}

.toolbar h3,
.form-header h3 {
    margin: 0;
    color: #172238;
    font-size: 18px;
    font-weight: 800;
}

.toolbar p,
.form-header p {
    margin: 5px 0 0;
    color: #7d889c;
    font-size: 13px;
}

.primary-button,
.secondary-button,
.edit-button,
.deactivate-button {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}

.primary-button {
    border: 1px solid #00358b;
    background: #00358b;
    color: white;
}

.primary-button:hover:not(:disabled) {
    background: #002b70;
}

.secondary-button {
    border: 1px solid #d4dce8;
    background: white;
    color: #4b5870;
}

.primary-button:disabled,
.secondary-button:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.form-panel {
    margin-top: 22px;
    overflow: hidden;
    border: 1px solid #dce4ef;
    border-radius: 17px;
    background: white;
    box-shadow: 0 10px 30px rgba(24, 43, 74, 0.06);
}

.form-header {
    padding: 22px 24px;
    display: flex;
    justify-content: space-between;
    gap: 20px;
    border-bottom: 1px solid #e4e9f1;
    background: #f8faff;
}

.close-button {
    width: 36px;
    height: 36px;
    border: 1px solid #dbe2ec;
    border-radius: 9px;
    background: white;
    color: #59657a;
    font-size: 22px;
    cursor: pointer;
}

.book-form {
    padding: 24px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
}

.form-field {
    min-width: 0;
}

.form-field-full {
    grid-column: 1 / -1;
}

.form-field label {
    display: block;
    margin-bottom: 7px;
    color: #39465c;
    font-size: 11px;
    font-weight: 800;
}

.form-field label span {
    color: #c93c3c;
}

.form-field input,
.form-field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #d7dfe9;
    border-radius: 9px;
    outline: none;
    background: white;
    color: #172238;
    font: inherit;
    font-size: 13px;
}

.form-field input:focus,
.form-field textarea:focus {
    border-color: #00358b;
    box-shadow: 0 0 0 3px rgba(0, 53, 139, 0.08);
}

.form-field textarea {
    resize: vertical;
}

.field-help {
    display: block;
    margin-top: 6px;
    color: #909bae;
    font-size: 10px;
}

.field-error {
    display: block;
    margin-top: 6px;
    color: #c0392b;
    font-size: 10px;
    font-weight: 700;
}

.form-actions {
    margin-top: 24px;
    padding-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    border-top: 1px solid #e6ebf2;
}

.books-grid {
    margin-top: 22px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 20px;
}

.book-card {
    overflow: hidden;
    display: flex;
    min-height: 280px;
    border: 1px solid #e0e6ef;
    border-radius: 17px;
    background: white;
    box-shadow: 0 7px 22px rgba(21, 42, 75, 0.05);
}

.book-cover {
    width: 96px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    background: #eaf2ff;
}

.book-cover span {
    width: 55px;
    height: 72px;
    display: grid;
    place-items: center;
    border-radius: 7px 13px 13px 7px;
    background: #00358b;
    color: white;
    font-size: 25px;
    font-weight: 900;
}

.book-content {
    min-width: 0;
    width: 100%;
    padding: 20px;
}

.book-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.book-category {
    color: #557095;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.book-status {
    padding: 4px 8px;
    border-radius: 999px;
    background: #e8f7ef;
    color: #198754;
    font-size: 10px;
    font-weight: 800;
}

.book-content h4 {
    margin: 13px 0 0;
    color: #162033;
    font-size: 18px;
    font-weight: 800;
}

.authors {
    margin: 5px 0 0;
    color: #6d7890;
    font-size: 12px;
}

.book-data {
    margin-top: 19px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 18px;
}

.book-data span {
    display: block;
    margin-bottom: 3px;
    color: #99a3b4;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
}

.book-data strong {
    display: block;
    overflow: hidden;
    color: #3e4a5f;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.description {
    margin: 17px 0 0;
    color: #778399;
    font-size: 11px;
    line-height: 1.55;
}

.book-actions {
    margin-top: 20px;
    padding-top: 15px;
    display: flex;
    gap: 8px;
    border-top: 1px solid #e9edf3;
}

.edit-button {
    border: 1px solid #c7d8f3;
    background: #eef5ff;
    color: #00358b;
}

.edit-button:hover {
    background: #ddeaff;
}

.deactivate-button {
    border: 1px solid #f0c8c8;
    background: #fff5f5;
    color: #b33a3a;
}

.deactivate-button:hover {
    background: #ffe9e9;
}

.empty-state {
    margin-top: 22px;
    padding: 65px 30px;
    text-align: center;
    border: 1px dashed #cad4e1;
    border-radius: 18px;
    background: white;
}

.empty-icon {
    width: 55px;
    height: 55px;
    margin: 0 auto 15px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #eaf2ff;
    color: #00358b;
    font-size: 24px;
}

.empty-state h3 {
    margin: 0;
    color: #253148;
}

.empty-state p {
    margin: 7px 0 0;
    color: #8994a6;
    font-size: 13px;
}

@media (max-width: 850px) {
    .hero {
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

@media (max-width: 600px) {
    .toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .book-card {
        display: block;
    }

    .book-cover {
        width: 100%;
        height: 115px;
    }

    .form-actions,
    .book-actions {
        flex-direction: column;
    }

    .form-actions button,
    .book-actions button {
        width: 100%;
    }
}
</style>
