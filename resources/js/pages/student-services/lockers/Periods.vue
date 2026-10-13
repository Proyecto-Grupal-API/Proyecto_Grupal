<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface LockerPeriod {
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
    status: 'active' | 'closed';
}

const props = defineProps<{
    periods: LockerPeriod[];
}>();

const BASE_URL = '/servicios-estudiante/lockers';

const search = ref('');
const statusFilter = ref('');

const showForm = ref(false);

const editingPeriod = ref<LockerPeriod | null>(null);

const form = useForm({
    code: '',
    name: '',
    starts_at: '',
    ends_at: '',

    prices: {
        small: '',
        medium: '',
        large: '',
    },
});

const activeCount = computed(() => {
    return props.periods.filter((period) => period.status === 'active').length;
});

const closedCount = computed(() => {
    return props.periods.filter((period) => period.status === 'closed').length;
});

const filteredPeriods = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.periods.filter((period) => {
        if (statusFilter.value && period.status !== statusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [period.code, period.name].some((value) =>
            value.toLowerCase().includes(term),
        );
    });
});

const averageSmall = computed(() => {
    return calculateAverage('small');
});

const averageMedium = computed(() => {
    return calculateAverage('medium');
});

const averageLarge = computed(() => {
    return calculateAverage('large');
});

function calculateAverage(size: 'small' | 'medium' | 'large'): number {
    const active = props.periods.filter((period) => period.status === 'active');

    if (active.length === 0) {
        return 0;
    }

    const values = active
        .map((period) => Number(period.prices[size] ?? 0))
        .filter((value) => !Number.isNaN(value));

    if (values.length === 0) {
        return 0;
    }

    return values.reduce((total, value) => total + value, 0) / values.length;
}

function money(value: string | number | null): string {
    const numberValue = Number(value ?? 0);

    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(Number.isNaN(numberValue) ? 0 : numberValue);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const clean = value.slice(0, 10);

    const parts = clean.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function openCreateForm() {
    editingPeriod.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function openEditForm(period: LockerPeriod) {
    if (period.status !== 'active') {
        window.alert('Los periodos cerrados ya no pueden editarse.');

        return;
    }

    editingPeriod.value = period;

    form.clearErrors();

    form.code = period.code;
    form.name = period.name;

    form.starts_at = period.starts_at?.slice(0, 10) ?? '';

    form.ends_at = period.ends_at?.slice(0, 10) ?? '';

    form.prices.small = period.prices.small ?? '';

    form.prices.medium = period.prices.medium ?? '';

    form.prices.large = period.prices.large ?? '';

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeForm() {
    editingPeriod.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = false;
}

function submitPeriod() {
    if (editingPeriod.value === null) {
        form.post(`${BASE_URL}/periodos`, {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        });

        return;
    }

    form.patch(`${BASE_URL}/periodos/${editingPeriod.value.id}`, {
        preserveScroll: true,

        onSuccess: () => {
            closeForm();
        },
    });
}

function closePeriod(period: LockerPeriod) {
    if (period.status !== 'active') {
        return;
    }

    const confirmed = window.confirm(
        `¿Cerrar el periodo "${period.name}"?\n\nDespués de cerrarlo ya no podrá editarse.`,
    );

    if (!confirmed) {
        return;
    }

    router.patch(
        `${BASE_URL}/periodos/${period.id}/cerrar`,
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
}

function statusLabel(status: LockerPeriod['status']): string {
    return status === 'active' ? 'Activo' : 'Cerrado';
}
</script>

<template>
    <StudentServicesLayout
        title="Periodos de lockers"
        subtitle="Periodos, vigencias y costos del servicio"
    >
        <section class="hero">
            <div>
                <span class="hero-label"> LOCKERS · MÓDULO 5.3 </span>

                <h2>Periodos y costos</h2>

                <p>
                    Configura las fechas de servicio y los costos aplicables
                    para lockers chicos, medianos y grandes.
                </p>
            </div>

            <div class="hero-total">
                <span> Periodos registrados </span>

                <strong>
                    {{ periods.length }}
                </strong>

                <small> almacenados en MongoDB </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span> Activos </span>

                <strong>
                    {{ activeCount }}
                </strong>

                <small> Aceptan solicitudes </small>
            </article>

            <article class="stat-card">
                <span> Cerrados </span>

                <strong>
                    {{ closedCount }}
                </strong>

                <small> Periodos finalizados </small>
            </article>

            <article class="stat-card">
                <span> Precio chico </span>

                <strong class="money">
                    {{ money(averageSmall) }}
                </strong>

                <small> Promedio activo </small>
            </article>

            <article class="stat-card">
                <span> Precio mediano </span>

                <strong class="money">
                    {{ money(averageMedium) }}
                </strong>

                <small> Promedio activo </small>
            </article>

            <article class="stat-card">
                <span> Precio grande </span>

                <strong class="money">
                    {{ money(averageLarge) }}
                </strong>

                <small> Promedio activo </small>
            </article>
        </section>

        <section class="module-navigation">
            <Link :href="BASE_URL" class="module-link"> Catálogo </Link>

            <Link :href="`${BASE_URL}/periodos`" class="module-link active">
                Periodos y costos
            </Link>

            <Link :href="`${BASE_URL}/solicitudes`" class="module-link">
                Solicitudes
            </Link>

            <Link :href="`${BASE_URL}/asignaciones`" class="module-link">
                Asignaciones
            </Link>

            <Link :href="`${BASE_URL}/acceso`" class="module-link">
                Validar acceso
            </Link>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label"> CONFIGURACIÓN </span>

                    <h3>Periodos registrados</h3>

                    <p>
                        Administra vigencias y precios del servicio de lockers.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Nuevo periodo
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="panel-label">
                            {{
                                editingPeriod === null
                                    ? 'NUEVO PERIODO'
                                    : 'EDITAR PERIODO'
                            }}
                        </span>

                        <h3>
                            {{
                                editingPeriod === null
                                    ? 'Registrar periodo'
                                    : `Editar ${editingPeriod.code}`
                            }}
                        </h3>

                        <p>
                            Define vigencia y costos para cada tamaño de locker.
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

                <form class="period-form" @submit.prevent="submitPeriod">
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="period-code">
                                Código
                                <span>*</span>
                            </label>

                            <input
                                id="period-code"
                                v-model="form.code"
                                type="text"
                                placeholder="Ej. 2027-B"
                            />

                            <small v-if="form.errors.code" class="field-error">
                                {{ form.errors.code }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="period-name">
                                Nombre
                                <span>*</span>
                            </label>

                            <input
                                id="period-name"
                                v-model="form.name"
                                type="text"
                                placeholder="Ej. Agosto - Diciembre 2027"
                            />

                            <small v-if="form.errors.name" class="field-error">
                                {{ form.errors.name }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="starts-at">
                                Fecha de inicio
                                <span>*</span>
                            </label>

                            <input
                                id="starts-at"
                                v-model="form.starts_at"
                                type="date"
                            />

                            <small
                                v-if="form.errors.starts_at"
                                class="field-error"
                            >
                                {{ form.errors.starts_at }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="ends-at">
                                Fecha de fin
                                <span>*</span>
                            </label>

                            <input
                                id="ends-at"
                                v-model="form.ends_at"
                                type="date"
                            />

                            <small
                                v-if="form.errors.ends_at"
                                class="field-error"
                            >
                                {{ form.errors.ends_at }}
                            </small>
                        </div>
                    </div>

                    <div class="prices-title">
                        <div>
                            <span class="panel-label"> COSTOS </span>

                            <h4>Precio por tamaño</h4>

                            <p>
                                Los importes se almacenan como valores
                                monetarios en MongoDB.
                            </p>
                        </div>
                    </div>

                    <div class="price-grid">
                        <div class="price-field">
                            <div class="price-icon">S</div>

                            <div class="price-content">
                                <label for="price-small"> Locker chico </label>

                                <div class="money-input">
                                    <span> $ </span>

                                    <input
                                        id="price-small"
                                        v-model="form.prices.small"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="150.00"
                                    />
                                </div>

                                <small
                                    v-if="form.errors['prices.small']"
                                    class="field-error"
                                >
                                    {{ form.errors['prices.small'] }}
                                </small>
                            </div>
                        </div>

                        <div class="price-field">
                            <div class="price-icon">M</div>

                            <div class="price-content">
                                <label for="price-medium">
                                    Locker mediano
                                </label>

                                <div class="money-input">
                                    <span> $ </span>

                                    <input
                                        id="price-medium"
                                        v-model="form.prices.medium"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="220.00"
                                    />
                                </div>

                                <small
                                    v-if="form.errors['prices.medium']"
                                    class="field-error"
                                >
                                    {{ form.errors['prices.medium'] }}
                                </small>
                            </div>
                        </div>

                        <div class="price-field">
                            <div class="price-icon">L</div>

                            <div class="price-content">
                                <label for="price-large"> Locker grande </label>

                                <div class="money-input">
                                    <span> $ </span>

                                    <input
                                        id="price-large"
                                        v-model="form.prices.large"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="300.00"
                                    />
                                </div>

                                <small
                                    v-if="form.errors['prices.large']"
                                    class="field-error"
                                >
                                    {{ form.errors['prices.large'] }}
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="information-box">
                        <strong> Importante: </strong>

                        un periodo cerrado permanece en el historial, pero ya no
                        puede editarse ni utilizarse para nuevas solicitudes.
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
                                    : editingPeriod === null
                                      ? 'Guardar periodo'
                                      : 'Guardar cambios'
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
                        placeholder="Buscar por código o nombre..."
                    />
                </div>

                <select v-model="statusFilter">
                    <option value="">Todos los estados</option>

                    <option value="active">Activos</option>

                    <option value="closed">Cerrados</option>
                </select>
            </div>

            <div v-if="filteredPeriods.length > 0" class="period-list">
                <article
                    v-for="period in filteredPeriods"
                    :key="period.id"
                    class="period-item"
                >
                    <div class="period-main">
                        <div class="period-code">
                            {{ period.code }}
                        </div>

                        <div class="period-information">
                            <div class="period-title">
                                <h4>
                                    {{ period.name }}
                                </h4>

                                <span
                                    class="status"
                                    :class="`status-${period.status}`"
                                >
                                    {{ statusLabel(period.status) }}
                                </span>
                            </div>

                            <div class="period-meta">
                                <span>
                                    Inicio:
                                    <strong>
                                        {{ formatDate(period.starts_at) }}
                                    </strong>
                                </span>

                                <span>
                                    Fin:
                                    <strong>
                                        {{ formatDate(period.ends_at) }}
                                    </strong>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="period-prices">
                        <div>
                            <span> Chico </span>

                            <strong>
                                {{ money(period.prices.small) }}
                            </strong>
                        </div>

                        <div>
                            <span> Mediano </span>

                            <strong>
                                {{ money(period.prices.medium) }}
                            </strong>
                        </div>

                        <div>
                            <span> Grande </span>

                            <strong>
                                {{ money(period.prices.large) }}
                            </strong>
                        </div>
                    </div>

                    <div class="period-actions">
                        <button
                            v-if="period.status === 'active'"
                            type="button"
                            class="action-button edit"
                            @click="openEditForm(period)"
                        >
                            Editar
                        </button>

                        <button
                            v-if="period.status === 'active'"
                            type="button"
                            class="action-button close"
                            @click="closePeriod(period)"
                        >
                            Cerrar periodo
                        </button>

                        <span v-else class="closed-message">
                            Periodo finalizado
                        </span>
                    </div>
                </article>
            </div>

            <div v-else class="empty-state">
                <h3>No se encontraron periodos</h3>

                <p>Ajusta los filtros o registra uno nuevo.</p>
            </div>
        </section>
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
    min-width: 175px;
    padding: 16px 19px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-total span {
    display: block;
    color: #d8e4f8;
    font-size: 10px;
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

.stat-card strong.money {
    font-size: 17px;
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
.form-header {
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
    border-bottom: 1px solid #e5e9ef;
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

.period-form {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px 19px;
}

.form-field label,
.price-content label {
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
.money-input input {
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
.money-input input:focus {
    border-color: #3970c1;
    box-shadow: 0 0 0 3px rgba(57, 112, 193, 0.08);
}

.field-error {
    display: block;
    margin-top: 5px;
    color: #b83b3b;
    font-size: 9px;
    font-weight: 700;
}

.prices-title {
    margin-top: 24px;
    padding-top: 19px;
    border-top: 1px solid #e4e9f0;
}

.prices-title h4 {
    margin: 0;
    color: #34435a;
    font-size: 13px;
}

.prices-title p {
    margin: 5px 0 0;
    color: #8a97aa;
    font-size: 10px;
}

.price-grid {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.price-field {
    padding: 14px;
    display: flex;
    align-items: flex-start;
    gap: 11px;
    border: 1px solid #dde4ed;
    border-radius: 9px;
    background: white;
}

.price-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 8px;
    background: #e9f1fc;
    color: #315fa6;
    font-size: 11px;
    font-weight: 900;
}

.price-content {
    flex: 1;
}

.money-input {
    position: relative;
}

.money-input > span {
    position: absolute;
    top: 50%;
    left: 11px;
    transform: translateY(-50%);
    color: #7c8b9f;
    font-size: 11px;
    pointer-events: none;
}

.money-input input {
    padding-left: 26px;
}

.information-box {
    margin-top: 17px;
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
    padding: 14px 21px;
    display: grid;
    grid-template-columns:
        minmax(230px, 1fr)
        190px;
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
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 10px;
}

.period-list {
    padding: 17px 21px 21px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.period-item {
    padding: 16px;
    display: grid;
    grid-template-columns:
        minmax(270px, 1.5fr)
        minmax(260px, 1fr)
        auto;
    align-items: center;
    gap: 20px;
    border: 1px solid #e1e6ee;
    border-radius: 9px;
    background: white;
}

.period-main {
    display: flex;
    align-items: center;
    gap: 13px;
}

.period-code {
    min-width: 72px;
    padding: 9px 10px;
    box-sizing: border-box;
    border-radius: 7px;
    background: #edf3fc;
    color: #315a9f;
    text-align: center;
    font-size: 10px;
    font-weight: 900;
}

.period-information {
    min-width: 0;
}

.period-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.period-title h4 {
    margin: 0;
    color: #34435a;
    font-size: 12px;
}

.period-meta {
    margin-top: 6px;
    display: flex;
    flex-wrap: wrap;
    gap: 13px;
    color: #8996a8;
    font-size: 9px;
}

.period-meta strong {
    color: #5a6b82;
}

.status {
    display: inline-flex;
    padding: 4px 7px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.status-active {
    background: #e4f6ec;
    color: #217a4e;
}

.status-closed {
    background: #eff1f4;
    color: #788596;
}

.period-prices {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 7px;
}

.period-prices div {
    min-width: 74px;
    padding: 8px;
    border-radius: 6px;
    background: #f6f8fb;
    text-align: center;
}

.period-prices span {
    display: block;
    color: #8d99aa;
    font-size: 8px;
}

.period-prices strong {
    display: block;
    margin-top: 4px;
    color: #315a9f;
    font-size: 10px;
}

.period-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 6px;
}

.action-button {
    min-height: 30px;
    padding: 0 9px;
    border-radius: 6px;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.action-button.edit {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.close {
    border: 1px solid #e6c9cd;
    background: #fbebed;
    color: #9d4650;
}

.closed-message {
    color: #99a5b5;
    font-size: 9px;
    font-weight: 700;
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

    .period-item {
        grid-template-columns: 1fr;
    }

    .period-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 800px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .price-grid {
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
    .form-header {
        align-items: stretch;
        flex-direction: column;
    }

    .form-grid,
    .filters {
        grid-template-columns: 1fr;
    }

    .period-prices {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column-reverse;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .period-main {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
