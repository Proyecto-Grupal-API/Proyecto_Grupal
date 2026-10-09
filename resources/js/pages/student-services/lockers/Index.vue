<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Locker {
    id: string;
    code: string;
    qr_code: string;
    building: string;
    zone: string;
    size: 'small' | 'medium' | 'large';
    status: 'available' | 'reserved' | 'occupied' | 'maintenance';
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

interface Summary {
    total: number;
    available: number;
    reserved: number;
    occupied: number;
    maintenance: number;
}

const props = defineProps<{
    lockers: Locker[];
    periods: Period[];
    summary: Summary;
}>();

const BASE_URL = '/servicios-estudiante/lockers';

const search = ref('');
const buildingFilter = ref('');
const sizeFilter = ref('');
const statusFilter = ref('');

const showForm = ref(false);

const editingLocker = ref<Locker | null>(null);

const form = useForm({
    code: '',
    building: '',
    zone: '',
    size: '',
    notes: '',
});

const buildings = computed(() => {
    return [...new Set(props.lockers.map((locker) => locker.building))].sort();
});

const zones = computed(() => {
    return [...new Set(props.lockers.map((locker) => locker.zone))].sort();
});

const isLocked = computed(() => {
    return (
        editingLocker.value !== null &&
        ['occupied', 'reserved'].includes(editingLocker.value.status)
    );
});

const statusError = computed(
    () => (form.errors as Record<string, string | undefined>).status,
);

const filteredLockers = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.lockers.filter((locker) => {
        if (buildingFilter.value && locker.building !== buildingFilter.value) {
            return false;
        }

        if (sizeFilter.value && locker.size !== sizeFilter.value) {
            return false;
        }

        if (statusFilter.value && locker.status !== statusFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [
            locker.code,
            locker.building,
            locker.zone,
            locker.qr_code,
            locker.notes ?? '',
        ].some((value) => value.toLowerCase().includes(term));
    });
});

function statusLabel(status: Locker['status']): string {
    const labels = {
        available: 'Disponible',
        reserved: 'Reservado',
        occupied: 'Ocupado',
        maintenance: 'Mantenimiento',
    };

    return labels[status];
}

function sizeLabel(size: Locker['size']): string {
    const labels = {
        small: 'Chico',
        medium: 'Mediano',
        large: 'Grande',
    };

    return labels[size];
}

function money(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const numberValue = Number(value);

    if (Number.isNaN(numberValue)) {
        return `$${value}`;
    }

    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(numberValue);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parts = value.slice(0, 10).split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function openCreateForm() {
    editingLocker.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function openEditForm(locker: Locker) {
    editingLocker.value = locker;

    form.clearErrors();

    form.code = locker.code;
    form.building = locker.building;
    form.zone = locker.zone;
    form.size = locker.size;
    form.notes = locker.notes ?? '';

    showForm.value = true;

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

function closeForm() {
    editingLocker.value = null;

    form.reset();
    form.clearErrors();

    showForm.value = false;
}

function submitLocker() {
    if (editingLocker.value === null) {
        form.post(BASE_URL, {
            preserveScroll: true,

            onSuccess: () => {
                closeForm();
            },
        });

        return;
    }

    form.patch(`${BASE_URL}/${editingLocker.value.id}`, {
        preserveScroll: true,

        onSuccess: () => {
            closeForm();
        },
    });
}

function runAction(locker: Locker, action: string, message: string) {
    if (!window.confirm(message)) {
        return;
    }

    router.patch(
        `${BASE_URL}/${locker.id}/${action}`,
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

function sendToMaintenance(locker: Locker) {
    runAction(
        locker,
        'mantenimiento',
        `¿Enviar el locker ${locker.code} a mantenimiento?`,
    );
}

function restoreAvailable(locker: Locker) {
    runAction(
        locker,
        'disponible',
        `¿Marcar nuevamente como disponible el locker ${locker.code}?`,
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Lockers"
        subtitle="Catálogo y disponibilidad de lockers del campus"
    >
        <section class="hero">
            <div>
                <span class="hero-label"> SERVICIOS · MÓDULO 5.3 </span>

                <h2>Lockers estudiantiles</h2>

                <p>
                    Consulta y administra los lockers disponibles en el campus,
                    su ubicación, tamaño, estado y periodo de servicio.
                </p>
            </div>

            <div class="hero-total">
                <span> Total de lockers </span>

                <strong>
                    {{ summary.total }}
                </strong>

                <small> registrados en MongoDB </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-top">
                    <span> Disponibles </span>

                    <span class="stat-dot available" />
                </div>

                <strong>
                    {{ summary.available }}
                </strong>

                <small> Listos para asignar </small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span> Reservados </span>

                    <span class="stat-dot reserved" />
                </div>

                <strong>
                    {{ summary.reserved }}
                </strong>

                <small> Apartados temporalmente </small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span> Ocupados </span>

                    <span class="stat-dot occupied" />
                </div>

                <strong>
                    {{ summary.occupied }}
                </strong>

                <small> Con asignación activa </small>
            </article>

            <article class="stat-card">
                <div class="stat-top">
                    <span> Mantenimiento </span>

                    <span class="stat-dot maintenance" />
                </div>

                <strong>
                    {{ summary.maintenance }}
                </strong>

                <small> Fuera de servicio </small>
            </article>
        </section>

        <section class="module-navigation">
            <Link :href="BASE_URL" class="module-link active"> Catálogo </Link>

            <Link :href="`${BASE_URL}/periodos`" class="module-link">
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

        <section v-if="periods.length > 0" class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label"> PERIODOS ACTIVOS </span>

                    <h3>Costos de renta</h3>

                    <p>Precios vigentes según el tamaño del locker.</p>
                </div>

                <Link :href="`${BASE_URL}/periodos`" class="secondary-button">
                    Administrar periodos
                </Link>
            </div>

            <div class="period-grid">
                <article
                    v-for="period in periods"
                    :key="period.id"
                    class="period-card"
                >
                    <div class="period-heading">
                        <div>
                            <strong>
                                {{ period.name }}
                            </strong>

                            <span>
                                {{ period.code }}
                            </span>
                        </div>

                        <span class="active-badge"> Activo </span>
                    </div>

                    <p class="period-dates">
                        {{ formatDate(period.starts_at) }}
                        —
                        {{ formatDate(period.ends_at) }}
                    </p>

                    <div class="prices">
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
                </article>
            </div>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label"> CATÁLOGO </span>

                    <h3>Lockers registrados</h3>

                    <p>Datos obtenidos directamente desde MongoDB.</p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openCreateForm"
                >
                    + Agregar locker
                </button>
            </div>

            <section v-if="showForm" class="form-panel">
                <div class="form-header">
                    <div>
                        <span class="panel-label">
                            {{
                                editingLocker === null
                                    ? 'NUEVO LOCKER'
                                    : 'EDITAR LOCKER'
                            }}
                        </span>

                        <h3>
                            {{
                                editingLocker === null
                                    ? 'Registrar locker'
                                    : `Editar ${editingLocker.code}`
                            }}
                        </h3>

                        <p>
                            {{
                                editingLocker === null
                                    ? 'Agrega un locker físico al catálogo.'
                                    : 'Actualiza los datos del locker seleccionado.'
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

                <form class="locker-form" @submit.prevent="submitLocker">
                    <div class="form-grid">
                        <div class="form-field">
                            <label for="locker-code">
                                Código
                                <span>*</span>
                            </label>

                            <input
                                id="locker-code"
                                v-model="form.code"
                                type="text"
                                placeholder="LKR-A-PB-001"
                                :disabled="isLocked"
                            />

                            <small v-if="form.errors.code" class="field-error">
                                {{ form.errors.code }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="locker-size">
                                Tamaño
                                <span>*</span>
                            </label>

                            <select
                                id="locker-size"
                                v-model="form.size"
                                :disabled="isLocked"
                            >
                                <option value="">Selecciona</option>

                                <option value="small">Chico</option>

                                <option value="medium">Mediano</option>

                                <option value="large">Grande</option>
                            </select>

                            <small v-if="form.errors.size" class="field-error">
                                {{ form.errors.size }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="locker-building">
                                Edificio
                                <span>*</span>
                            </label>

                            <input
                                id="locker-building"
                                v-model="form.building"
                                type="text"
                                list="building-options"
                                placeholder="Edificio A"
                                :disabled="isLocked"
                            />

                            <datalist id="building-options">
                                <option
                                    v-for="building in buildings"
                                    :key="building"
                                    :value="building"
                                />
                            </datalist>

                            <small
                                v-if="form.errors.building"
                                class="field-error"
                            >
                                {{ form.errors.building }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label for="locker-zone">
                                Zona
                                <span>*</span>
                            </label>

                            <input
                                id="locker-zone"
                                v-model="form.zone"
                                type="text"
                                list="zone-options"
                                placeholder="Planta baja"
                                :disabled="isLocked"
                            />

                            <datalist id="zone-options">
                                <option
                                    v-for="zone in zones"
                                    :key="zone"
                                    :value="zone"
                                />
                            </datalist>

                            <small v-if="form.errors.zone" class="field-error">
                                {{ form.errors.zone }}
                            </small>
                        </div>

                        <div class="form-field full">
                            <label for="locker-notes"> Notas </label>

                            <textarea
                                id="locker-notes"
                                v-model="form.notes"
                                rows="3"
                                placeholder="Observaciones opcionales..."
                            />

                            <small v-if="form.errors.notes" class="field-error">
                                {{ form.errors.notes }}
                            </small>
                        </div>
                    </div>

                    <div v-if="statusError" class="error-box">
                        {{ statusError }}
                    </div>

                    <div v-if="isLocked" class="information-box">
                        Este locker está
                        <strong>
                            {{ statusLabel(editingLocker!.status) }} </strong
                        >. Su código, ubicación y tamaño no pueden cambiar
                        mientras tenga una reserva o asignación activa.
                    </div>

                    <div
                        v-else-if="editingLocker === null"
                        class="information-box"
                    >
                        Los lockers nuevos se registran como
                        <strong> Disponibles </strong>
                        y reciben automáticamente un código QR.
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
                                    : editingLocker === null
                                      ? 'Guardar locker'
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
                        placeholder="Buscar por código, edificio, zona o QR..."
                    />
                </div>

                <select v-model="buildingFilter">
                    <option value="">Todos los edificios</option>

                    <option
                        v-for="building in buildings"
                        :key="building"
                        :value="building"
                    >
                        {{ building }}
                    </option>
                </select>

                <select v-model="sizeFilter">
                    <option value="">Todos los tamaños</option>

                    <option value="small">Chico</option>

                    <option value="medium">Mediano</option>

                    <option value="large">Grande</option>
                </select>

                <select v-model="statusFilter">
                    <option value="">Todos los estados</option>

                    <option value="available">Disponible</option>

                    <option value="reserved">Reservado</option>

                    <option value="occupied">Ocupado</option>

                    <option value="maintenance">Mantenimiento</option>
                </select>
            </div>

            <div v-if="filteredLockers.length > 0" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>

                            <th>Ubicación</th>

                            <th>Tamaño</th>

                            <th>Estado</th>

                            <th>QR</th>

                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="locker in filteredLockers" :key="locker.id">
                            <td>
                                <strong class="locker-code">
                                    {{ locker.code }}
                                </strong>

                                <small v-if="locker.notes" class="muted">
                                    {{ locker.notes }}
                                </small>
                            </td>

                            <td>
                                <div class="location">
                                    <strong>
                                        {{ locker.building }}
                                    </strong>

                                    <small>
                                        {{ locker.zone }}
                                    </small>
                                </div>
                            </td>

                            <td>
                                {{ sizeLabel(locker.size) }}
                            </td>

                            <td>
                                <span
                                    class="status"
                                    :class="`status-${locker.status}`"
                                >
                                    {{ statusLabel(locker.status) }}
                                </span>
                            </td>

                            <td>
                                <span class="qr-code">
                                    {{ locker.qr_code }}
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="action-button edit"
                                        @click="openEditForm(locker)"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        v-if="locker.status === 'available'"
                                        type="button"
                                        class="action-button maintenance"
                                        @click="sendToMaintenance(locker)"
                                    >
                                        Mantenimiento
                                    </button>

                                    <button
                                        v-if="locker.status === 'maintenance'"
                                        type="button"
                                        class="action-button available"
                                        @click="restoreAvailable(locker)"
                                    >
                                        Disponible
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-state">
                <h3>No se encontraron lockers</h3>

                <p>Ajusta los filtros o registra un nuevo locker.</p>
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
    min-width: 170px;
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
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
}

.stat-card {
    padding: 17px 18px;
    border: 1px solid #dfe5ee;
    border-radius: 10px;
    background: white;
}

.stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
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

.stat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.stat-dot.available {
    background: #36a26c;
}

.stat-dot.reserved {
    background: #dda82f;
}

.stat-dot.occupied {
    background: #3970c1;
}

.stat-dot.maintenance {
    background: #b75a5a;
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
    text-decoration: none;
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

.period-grid {
    padding: 18px 21px 21px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 13px;
}

.period-card {
    padding: 16px;
    border: 1px solid #e1e6ee;
    border-radius: 9px;
    background: #fafcff;
}

.period-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

.period-heading strong {
    display: block;
    color: #314057;
    font-size: 12px;
}

.period-heading div > span {
    display: block;
    margin-top: 3px;
    color: #8c99aa;
    font-size: 9px;
}

.active-badge {
    padding: 4px 7px;
    border-radius: 999px;
    background: #e4f6ec;
    color: #217a4e;
    font-size: 8px;
    font-weight: 800;
}

.period-dates {
    margin: 12px 0;
    color: #76869b;
    font-size: 9px;
}

.prices {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 7px;
}

.prices div {
    padding: 8px;
    border-radius: 6px;
    background: white;
    text-align: center;
}

.prices span {
    display: block;
    color: #8a97aa;
    font-size: 8px;
}

.prices strong {
    display: block;
    margin-top: 4px;
    color: #315a9f;
    font-size: 10px;
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

.locker-form {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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
    font-size: 12px;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    border-color: #3970c1;
    box-shadow: 0 0 0 3px rgba(57, 112, 193, 0.08);
}

.form-field input:disabled,
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

.information-box strong {
    color: #315a9f;
}

.error-box {
    border: 1px solid #ebc6c6;
    background: #fceded;
    color: #a04444;
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
        minmax(220px, 2fr)
        repeat(3, 1fr);
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

.locker-code {
    display: block;
    color: #285aa6;
}

.muted {
    display: block;
    max-width: 220px;
    margin-top: 3px;
    color: #99a4b4;
    font-size: 8px;
}

.location {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.location strong {
    color: #394960;
    font-size: 10px;
}

.location small {
    color: #8b98aa;
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

.status-reserved {
    background: #fff3d7;
    color: #946510;
}

.status-occupied {
    background: #e8f0fc;
    color: #315fa6;
}

.status-maintenance {
    background: #f7e6e6;
    color: #a74646;
}

.qr-code {
    padding: 4px 7px;
    border-radius: 5px;
    background: #f0f3f7;
    color: #52647d;
    font-size: 9px;
    font-family: monospace;
}

.actions {
    min-width: 185px;
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

.action-button.edit {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.maintenance {
    border: 1px solid #e8d5ae;
    background: #fff7e6;
    color: #926816;
}

.action-button.available {
    border: 1px solid #c3e2d1;
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

@media (max-width: 1050px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filters {
        grid-template-columns: repeat(2, 1fr);
    }

    .search-field {
        grid-column: 1 / -1;
    }
}

@media (max-width: 720px) {
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

    .form-field.full,
    .search-field {
        grid-column: auto;
    }

    .form-actions {
        flex-direction: column-reverse;
    }
}

@media (max-width: 520px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .prices {
        grid-template-columns: 1fr;
    }
}
</style>
