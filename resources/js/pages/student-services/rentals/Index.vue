<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type EquipmentStatus = 'available' | 'rented' | 'maintenance';

type RentalStatus = 'active' | 'overdue';

type ReturnCondition = 'good' | 'damaged' | 'maintenance' | 'lost';

interface Equipment {
    id: string;
    inventory_item_id: string;
    name: string;
    category: string;
    location: string;
    status: EquipmentStatus;
    description: string;
    deposit: string;
}

type DepositStatus = 'none' | 'held' | 'released' | 'charged';

interface Rental {
    id: string;
    asset_id: string;
    inventory_item_id: string;
    student_id: string;
    equipment_name: string;
    requested_at: string;
    due_at: string;
    returned_at: string | null;
    status: RentalStatus;
    notes: string | null;
    deposit: string;
    deposit_status: DepositStatus;
    picked_up: boolean;
}

const props = defineProps<{
    equipment: Equipment[];
    rentals: Rental[];
}>();

const search = ref('');
const selectedCategory = ref('Todas');

const selectedEquipment = ref<Equipment | null>(null);

const selectedRental = ref<Rental | null>(null);

const showRentalModal = ref(false);
const showReturnModal = ref(false);

const processingRentalId = ref<string | null>(null);

const rentalForm = useForm({
    asset_id: '',
    due_at: '',
    notes: '',
});

const returnForm = useForm({
    condition: 'good' as ReturnCondition,
    notes: '',
});

const hasDeposit = (amount: string) => Number(amount) > 0;

const categories = computed(() => {
    const values = props.equipment.map((item) => item.category);

    return ['Todas', ...Array.from(new Set(values))];
});

const filteredEquipment = computed(() => {
    const value = search.value.trim().toLowerCase();

    return props.equipment.filter((item) => {
        const matchesSearch =
            !value ||
            item.name.toLowerCase().includes(value) ||
            item.inventory_item_id.toLowerCase().includes(value) ||
            item.category.toLowerCase().includes(value) ||
            item.location.toLowerCase().includes(value);

        const matchesCategory =
            selectedCategory.value === 'Todas' ||
            item.category === selectedCategory.value;

        return matchesSearch && matchesCategory;
    });
});

const availableCount = computed(() => {
    return props.equipment.filter((item) => item.status === 'available').length;
});

const rentedCount = computed(() => {
    return props.equipment.filter((item) => item.status === 'rented').length;
});

const maintenanceCount = computed(() => {
    return props.equipment.filter((item) => item.status === 'maintenance')
        .length;
});

const activeRentalsCount = computed(() => {
    return props.rentals.length;
});

const getStatusLabel = (status: EquipmentStatus): string => {
    switch (status) {
        case 'available':
            return 'Disponible';

        case 'rented':
            return 'Rentado';

        case 'maintenance':
            return 'Mantenimiento';

        default:
            return status;
    }
};

const getRentalStatusLabel = (status: RentalStatus): string => {
    switch (status) {
        case 'active':
            return 'Activa';

        case 'overdue':
            return 'Vencida';

        default:
            return status;
    }
};

const formatDate = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
};

const getMinimumReturnDate = (): string => {
    const today = new Date();

    const year = today.getFullYear();

    const month = String(today.getMonth() + 1).padStart(2, '0');

    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

const openRental = (item: Equipment) => {
    if (item.status !== 'available') {
        return;
    }

    rentalForm.reset();
    rentalForm.clearErrors();

    selectedEquipment.value = item;

    rentalForm.asset_id = item.id;

    rentalForm.due_at = '';

    showRentalModal.value = true;
};

const closeRental = () => {
    showRentalModal.value = false;
    selectedEquipment.value = null;

    rentalForm.reset();
    rentalForm.clearErrors();
};

const confirmRental = () => {
    if (selectedEquipment.value === null || !rentalForm.due_at) {
        return;
    }

    rentalForm.post('/servicios-estudiante/renta-equipos', {
        preserveScroll: true,

        onSuccess: () => {
            closeRental();

            window.alert('Renta registrada correctamente.');
        },
    });
};

const cancelRental = (rental: Rental) => {
    const confirmed = window.confirm(
        `¿Cancelar la renta de ${rental.equipment_name}?`,
    );

    if (!confirmed) {
        return;
    }

    processingRentalId.value = rental.id;

    router.patch(
        `/servicios-estudiante/renta-equipos/${rental.id}/cancelar`,
        {},
        {
            preserveScroll: true,

            onSuccess: () => {
                window.alert('Renta cancelada correctamente.');
            },

            onError: (errors) => {
                window.alert(
                    errors.rental ?? 'No fue posible cancelar la renta.',
                );
            },

            onFinish: () => {
                processingRentalId.value = null;
            },
        },
    );
};

const openReturnModal = (rental: Rental) => {
    selectedRental.value = rental;

    returnForm.reset();
    returnForm.clearErrors();

    returnForm.condition = 'good';
    returnForm.notes = '';

    showReturnModal.value = true;
};

const closeReturnModal = () => {
    showReturnModal.value = false;
    selectedRental.value = null;

    returnForm.reset();
    returnForm.clearErrors();
};

const confirmReturn = () => {
    if (selectedRental.value === null) {
        return;
    }

    const rentalId = selectedRental.value.id;

    processingRentalId.value = rentalId;

    returnForm.patch(
        `/servicios-estudiante/renta-equipos/${rentalId}/devolver`,
        {
            preserveScroll: true,

            onSuccess: () => {
                closeReturnModal();

                window.alert('Devolución registrada correctamente.');
            },

            onError: () => {
                processingRentalId.value = null;
            },

            onFinish: () => {
                processingRentalId.value = null;
            },
        },
    );
};

/**
 * Errores del servidor que no corresponden a un campo del formulario
 * (por ejemplo, reglas de negocio devueltas con withErrors).
 */
const serverError = (form: { errors: object }, key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <StudentServicesLayout
        title="Renta de equipos"
        subtitle="Consulta y solicita equipos institucionales disponibles"
    >
        <section class="rental-hero">
            <div class="hero-information">
                <span class="section-label"> RENTA DE EQUIPOS </span>

                <h2>Equipos para tus actividades</h2>

                <p>
                    Consulta los equipos institucionales disponibles y realiza
                    una renta para tus actividades académicas.
                </p>
            </div>

            <div class="hero-reference">
                <span> Inventario </span>

                <strong> Equipo 4 </strong>

                <small> Referencia externa </small>
            </div>
        </section>

        <section class="statistics-grid">
            <article class="stat-card">
                <span> Disponibles </span>

                <strong>
                    {{ availableCount }}
                </strong>

                <small> Equipos que pueden solicitarse </small>
            </article>

            <article class="stat-card">
                <span> Rentados </span>

                <strong>
                    {{ rentedCount }}
                </strong>

                <small> Equipos actualmente prestados </small>
            </article>

            <article class="stat-card">
                <span> Mantenimiento </span>

                <strong>
                    {{ maintenanceCount }}
                </strong>

                <small> Temporalmente no disponibles </small>
            </article>

            <article class="stat-card">
                <span> Mis rentas activas </span>

                <strong>
                    {{ activeRentalsCount }}
                </strong>

                <small> Rentas activas o vencidas </small>
            </article>
        </section>

        <section class="rental-content">
            <div class="equipment-panel">
                <div class="panel-header">
                    <div>
                        <h3>Catálogo de equipos</h3>

                        <p>Información almacenada actualmente en MongoDB.</p>
                    </div>

                    <div class="filters">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Buscar equipo..."
                        />

                        <select v-model="selectedCategory">
                            <option
                                v-for="category in categories"
                                :key="category"
                                :value="category"
                            >
                                {{ category }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="equipment-list">
                    <article
                        v-for="item in filteredEquipment"
                        :key="item.id"
                        class="equipment-card"
                    >
                        <div class="equipment-icon">▦</div>

                        <div class="equipment-information">
                            <div class="equipment-title-row">
                                <div>
                                    <h4>
                                        {{ item.name }}
                                    </h4>

                                    <span class="inventory-code">
                                        {{ item.inventory_item_id }}
                                    </span>
                                </div>

                                <span class="status-badge" :class="item.status">
                                    {{ getStatusLabel(item.status) }}
                                </span>
                            </div>

                            <p class="equipment-description">
                                {{ item.description }}
                            </p>

                            <div class="equipment-meta">
                                <span>
                                    Categoría:
                                    <strong>
                                        {{ item.category }}
                                    </strong>
                                </span>

                                <span>
                                    Ubicación:
                                    <strong>
                                        {{ item.location }}
                                    </strong>
                                </span>

                                <span v-if="hasDeposit(item.deposit)">
                                    Depósito:
                                    <strong> ${{ item.deposit }} </strong>
                                </span>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="rent-button"
                            :disabled="item.status !== 'available'"
                            @click="openRental(item)"
                        >
                            {{
                                item.status === 'available'
                                    ? 'Solicitar renta'
                                    : getStatusLabel(item.status)
                            }}
                        </button>
                    </article>

                    <div
                        v-if="filteredEquipment.length === 0"
                        class="empty-results"
                    >
                        No se encontraron equipos con los filtros seleccionados.
                    </div>
                </div>
            </div>

            <aside class="rentals-panel">
                <div class="panel-header">
                    <div>
                        <h3>Mis rentas</h3>

                        <p>Rentas registradas en MongoDB.</p>
                    </div>
                </div>

                <div v-if="rentals.length === 0" class="empty-rentals">
                    <div class="empty-icon">▦</div>

                    <strong> Sin rentas activas </strong>

                    <span>
                        Selecciona un equipo disponible para realizar una renta.
                    </span>
                </div>

                <div v-else class="my-rentals-list">
                    <article
                        v-for="rental in rentals"
                        :key="rental.id"
                        class="my-rental-card"
                    >
                        <div class="rental-card-header">
                            <strong>
                                {{ rental.equipment_name }}
                            </strong>

                            <span class="active-badge" :class="rental.status">
                                {{ getRentalStatusLabel(rental.status) }}
                            </span>
                        </div>

                        <span class="rental-inventory">
                            {{ rental.inventory_item_id }}
                        </span>

                        <div class="rental-details">
                            <span>
                                Solicitud:
                                {{ formatDate(rental.requested_at) }}
                            </span>

                            <span>
                                Devolución:
                                {{ formatDate(rental.due_at) }}
                            </span>

                            <span v-if="rental.deposit_status === 'held'">
                                Depósito retenido: ${{ rental.deposit }}
                            </span>
                        </div>

                        <div class="rental-actions">
                            <p
                                v-if="rental.deposit_status === 'held'"
                                class="deposit-note"
                            >
                                Entrega el equipo en el mostrador para revisarlo
                                y liberar tu depósito.
                            </p>

                            <button
                                v-else
                                type="button"
                                class="return-rental"
                                :disabled="processingRentalId === rental.id"
                                @click="openReturnModal(rental)"
                            >
                                Registrar devolución
                            </button>

                            <button
                                v-if="!rental.picked_up"
                                type="button"
                                class="cancel-rental"
                                :disabled="processingRentalId === rental.id"
                                @click="cancelRental(rental)"
                            >
                                Cancelar renta
                            </button>
                        </div>
                    </article>
                </div>
            </aside>
        </section>

        <div
            v-if="showRentalModal && selectedEquipment"
            class="modal-backdrop"
            @click.self="closeRental"
        >
            <div class="rental-modal">
                <div class="modal-header">
                    <div>
                        <span class="modal-label"> SOLICITUD DE RENTA </span>

                        <h3>
                            {{ selectedEquipment.name }}
                        </h3>

                        <p>
                            {{ selectedEquipment.inventory_item_id }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeRental"
                    >
                        ×
                    </button>
                </div>

                <div class="equipment-summary">
                    <div>
                        <span> Categoría </span>

                        <strong>
                            {{ selectedEquipment.category }}
                        </strong>
                    </div>

                    <div>
                        <span> Ubicación </span>

                        <strong>
                            {{ selectedEquipment.location }}
                        </strong>
                    </div>

                    <div>
                        <span> Estado </span>

                        <strong> Disponible </strong>
                    </div>
                </div>

                <label class="form-field">
                    <span> Fecha de devolución </span>

                    <input
                        v-model="rentalForm.due_at"
                        type="date"
                        :min="getMinimumReturnDate()"
                    />

                    <small v-if="rentalForm.errors.due_at" class="form-error">
                        {{ rentalForm.errors.due_at }}
                    </small>
                </label>

                <label class="form-field">
                    <span> Notas </span>

                    <textarea
                        v-model="rentalForm.notes"
                        rows="3"
                        placeholder="Observaciones opcionales..."
                    ></textarea>
                </label>

                <div v-if="serverError(rentalForm, 'rental')" class="error-box">
                    {{ serverError(rentalForm, 'rental') }}
                </div>

                <div class="information-note">
                    El equipo será marcado como
                    <strong>Rentado</strong>
                    y la operación quedará almacenada en MongoDB.
                    <template
                        v-if="
                            selectedEquipment &&
                            hasDeposit(selectedEquipment.deposit)
                        "
                    >
                        Se retendrá un depósito de
                        <strong>${{ selectedEquipment.deposit }}</strong
                        >. Al devolverlo, el personal revisa el equipo en el
                        mostrador y libera tu depósito (o descuenta los daños).
                    </template>
                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="secondary-button"
                        @click="closeRental"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="primary-button"
                        :disabled="!rentalForm.due_at || rentalForm.processing"
                        @click="confirmRental"
                    >
                        {{
                            rentalForm.processing
                                ? 'Registrando...'
                                : 'Confirmar renta'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="showReturnModal && selectedRental"
            class="modal-backdrop"
            @click.self="closeReturnModal"
        >
            <div class="rental-modal">
                <div class="modal-header">
                    <div>
                        <span class="modal-label"> DEVOLUCIÓN DE EQUIPO </span>

                        <h3>
                            {{ selectedRental.equipment_name }}
                        </h3>

                        <p>
                            {{ selectedRental.inventory_item_id }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeReturnModal"
                    >
                        ×
                    </button>
                </div>

                <label class="form-field">
                    <span> Condición del equipo </span>

                    <select v-model="returnForm.condition">
                        <option value="good">Buen estado</option>

                        <option value="damaged">Dañado</option>

                        <option value="maintenance">
                            Requiere mantenimiento
                        </option>

                        <option value="lost">Perdido</option>
                    </select>
                </label>

                <label class="form-field">
                    <span> Notas de devolución </span>

                    <textarea
                        v-model="returnForm.notes"
                        rows="3"
                        placeholder="Describe el estado del equipo..."
                    ></textarea>
                </label>

                <div v-if="serverError(returnForm, 'rental')" class="error-box">
                    {{ serverError(returnForm, 'rental') }}
                </div>

                <div class="information-note">
                    Si el equipo se devuelve en buen estado volverá a estar
                    disponible. Si presenta daño, pérdida o requiere revisión,
                    pasará a mantenimiento.
                </div>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="secondary-button"
                        @click="closeReturnModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="primary-button"
                        :disabled="returnForm.processing"
                        @click="confirmReturn"
                    >
                        {{
                            returnForm.processing
                                ? 'Registrando...'
                                : 'Registrar devolución'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </StudentServicesLayout>
</template>

<style scoped>
.rental-hero {
    padding: 26px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-radius: 12px;
    background: #2d57ac;
    color: white;
}

.hero-information {
    max-width: 680px;
}

.section-label {
    display: block;
    margin-bottom: 7px;
    color: #8fb5f0;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.rental-hero h2 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
}

.rental-hero p {
    max-width: 620px;
    margin: 8px 0 0;
    color: #dbe7f8;
    font-size: 12px;
    line-height: 1.6;
}

.hero-reference {
    min-width: 145px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-reference span {
    color: #d8e5fa;
    font-size: 9px;
}

.hero-reference strong {
    margin-top: 4px;
    font-size: 16px;
}

.hero-reference small {
    margin-top: 3px;
    color: #bfd3f1;
    font-size: 8px;
}

.statistics-grid {
    margin-top: 17px;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

.stat-card {
    min-height: 112px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.stat-card span {
    color: #66768c;
    font-size: 11px;
}

.stat-card strong {
    margin-top: 6px;
    color: #25324a;
    font-size: 24px;
}

.stat-card small {
    margin-top: 4px;
    color: #98a4b4;
    font-size: 9px;
}

.rental-content {
    margin-top: 17px;
    display: grid;
    grid-template-columns:
        minmax(0, 1.8fr)
        minmax(300px, 0.75fr);
    gap: 15px;
    align-items: start;
}

.equipment-panel,
.rentals-panel {
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.equipment-panel {
    padding: 20px;
}

.rentals-panel {
    min-height: 390px;
    padding: 20px;
}

.panel-header {
    margin-bottom: 16px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 15px;
}

.panel-header h3 {
    margin: 0;
    color: #2c394f;
    font-size: 15px;
    font-weight: 700;
}

.panel-header p {
    margin: 4px 0 0;
    color: #8b97a9;
    font-size: 10px;
}

.filters {
    display: flex;
    gap: 8px;
}

.filters input,
.filters select {
    height: 36px;
    border: 1px solid #dbe2ec;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #34445b;
    font: inherit;
    font-size: 10px;
}

.filters input {
    width: 190px;
    padding: 0 11px;
}

.filters select {
    min-width: 135px;
    padding: 0 8px;
}

.filters input:focus,
.filters select:focus {
    border-color: #7ea5df;
}

.equipment-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.equipment-card {
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    border: 1px solid #e2e7ef;
    border-radius: 9px;
}

.equipment-icon {
    width: 43px;
    height: 43px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 8px;
    background: #eaf1fb;
    color: #315fa8;
    font-size: 16px;
}

.equipment-information {
    min-width: 0;
    flex: 1;
}

.equipment-title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

.equipment-title-row h4 {
    margin: 0;
    color: #29374d;
    font-size: 12px;
    font-weight: 700;
}

.inventory-code {
    display: block;
    margin-top: 3px;
    color: #97a3b4;
    font-size: 8px;
    font-weight: 600;
}

.status-badge {
    padding: 4px 7px;
    flex-shrink: 0;
    border-radius: 999px;
    font-size: 7px;
    font-weight: 800;
}

.status-badge.available {
    background: #e9f6ef;
    color: #3d805c;
}

.status-badge.rented {
    background: #eaf1fb;
    color: #315fa8;
}

.status-badge.maintenance {
    background: #fff2dc;
    color: #9d6917;
}

.equipment-description {
    margin: 7px 0 0;
    color: #7e8b9d;
    font-size: 9px;
    line-height: 1.5;
}

.equipment-meta {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    color: #8e9aac;
    font-size: 8px;
}

.equipment-meta strong {
    color: #536177;
    font-weight: 700;
}

.rent-button {
    min-width: 110px;
    min-height: 35px;
    padding: 0 12px;
    flex-shrink: 0;
    border: 0;
    border-radius: 7px;
    background: #2d57ac;
    color: white;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.rent-button:hover:not(:disabled) {
    background: #244a94;
}

.rent-button:disabled {
    cursor: default;
    background: #edf0f4;
    color: #949eac;
}

.empty-results {
    padding: 35px 20px;
    text-align: center;
    color: #8c99aa;
    font-size: 10px;
}

.empty-rentals {
    min-height: 275px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.empty-icon {
    width: 44px;
    height: 44px;
    margin-bottom: 11px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #edf3fb;
    color: #315fa8;
}

.empty-rentals strong {
    color: #3a475b;
    font-size: 11px;
}

.empty-rentals span {
    max-width: 210px;
    margin-top: 5px;
    color: #97a2b2;
    font-size: 9px;
    line-height: 1.5;
}

.my-rentals-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.my-rental-card {
    padding: 13px;
    border: 1px solid #e1e7ef;
    border-radius: 8px;
}

.rental-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.rental-card-header strong {
    color: #334159;
    font-size: 10px;
}

.active-badge {
    padding: 3px 6px;
    border-radius: 999px;
    background: #e9f6ef;
    color: #3d805c;
    font-size: 7px;
    font-weight: 800;
}

.active-badge.overdue {
    background: #fbe9eb;
    color: #9b444d;
}

.rental-inventory {
    display: block;
    margin-top: 4px;
    color: #97a3b4;
    font-size: 8px;
}

.rental-details {
    margin-top: 9px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    color: #8490a1;
    font-size: 8px;
}

.deposit-note {
    margin: 0;
    font-size: 11px;
    line-height: 1.4;
    color: #64748b;
}

.rental-actions {
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
}

.return-rental,
.cancel-rental {
    padding: 0;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.return-rental {
    color: #315fa8;
}

.cancel-rental {
    color: #9d4850;
}

.return-rental:disabled,
.cancel-rental:disabled {
    cursor: default;
    opacity: 0.5;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    padding: 20px;
    display: grid;
    place-items: center;
    background: rgba(20, 34, 56, 0.42);
}

.rental-modal {
    width: min(520px, 100%);
    padding: 23px;
    border-radius: 12px;
    background: white;
    box-shadow: 0 20px 55px rgba(26, 45, 78, 0.18);
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
}

.modal-label {
    display: block;
    margin-bottom: 6px;
    color: #315a9f;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.13em;
}

.modal-header h3 {
    margin: 0;
    color: #29374d;
    font-size: 17px;
}

.modal-header p {
    margin: 5px 0 0;
    color: #8b97a9;
    font-size: 9px;
}

.close-button {
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 7px;
    background: #f0f3f7;
    color: #5c697b;
    font-size: 18px;
    cursor: pointer;
}

.equipment-summary {
    margin-top: 18px;
    padding: 13px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    border-radius: 8px;
    background: #f5f7fa;
}

.equipment-summary div {
    display: flex;
    flex-direction: column;
}

.equipment-summary span {
    color: #8996a7;
    font-size: 8px;
}

.equipment-summary strong {
    margin-top: 3px;
    color: #39475d;
    font-size: 9px;
}

.form-field {
    margin-top: 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-field > span {
    color: #506078;
    font-size: 9px;
    font-weight: 700;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px;
    border: 1px solid #dce3ec;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #34445b;
    font: inherit;
    font-size: 10px;
}

.form-field input,
.form-field select {
    min-height: 38px;
}

.form-field textarea {
    resize: vertical;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    border-color: #7ea5df;
}

.form-error {
    color: #a7444c;
    font-size: 8px;
}

.error-box {
    margin-top: 14px;
    padding: 10px 12px;
    border: 1px solid #e7c7cb;
    border-radius: 7px;
    background: #fcedee;
    color: #9a414a;
    font-size: 9px;
}

.information-note {
    margin-top: 16px;
    padding: 11px 12px;
    border-radius: 7px;
    background: #edf3fb;
    color: #526d96;
    font-size: 8px;
    line-height: 1.55;
}

.modal-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
}

.secondary-button,
.primary-button {
    min-height: 36px;
    padding: 0 15px;
    border-radius: 7px;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.secondary-button {
    border: 1px solid #d8e0ea;
    background: white;
    color: #607087;
}

.primary-button {
    border: 0;
    background: #2d57ac;
    color: white;
}

.primary-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

@media (max-width: 1100px) {
    .statistics-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .rental-content {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 750px) {
    .rental-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-reference {
        width: 100%;
    }

    .panel-header {
        align-items: stretch;
        flex-direction: column;
    }

    .filters {
        flex-direction: column;
    }

    .filters input,
    .filters select {
        width: 100%;
    }
}

@media (max-width: 600px) {
    .statistics-grid {
        grid-template-columns: 1fr;
    }

    .equipment-card {
        align-items: stretch;
        flex-direction: column;
    }

    .rent-button {
        width: 100%;
    }

    .equipment-summary {
        grid-template-columns: 1fr;
    }
}
</style>
