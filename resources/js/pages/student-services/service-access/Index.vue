<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

type ScanMode = 'qr' | 'nfc' | 'manual';

type ServiceType =
    | 'library'
    | 'locker'
    | 'facility'
    | 'rest'
    | 'rental'
    | 'service';

type ActionType =
    | 'loan'
    | 'return'
    | 'entry'
    | 'checkin'
    | 'checkout'
    | 'pickup'
    | 'delivery';

interface ServiceEvent {
    id: string;
    folio: string;
    student_id: string | null;
    student_name: string | null;
    credential: string;
    service: ServiceType;
    action: ActionType;
    reference: string | null;
    mode: ScanMode;
    granted: boolean;
    message: string;
    created_at: string;
}

const props = defineProps<{
    events: ServiceEvent[];
    stats: { today: number; granted: number; denied: number };
    operations: Record<ServiceType, ActionType[]>;
    result: ServiceEvent | null;
}>();

const search = ref('');
const credentialInput = ref<HTMLInputElement | null>(null);
const dismissedResultId = ref<string | null>(null);

const form = useForm({
    credential: '',
    method: 'qr' as ScanMode,
    service: 'library' as ServiceType,
    action: 'loan' as ActionType,
    reference: '',
    condition: 'good',
});

const visibleResult = computed(() =>
    props.result && props.result.id !== dismissedResultId.value
        ? props.result
        : null,
);

const availableActions = computed(() => props.operations[form.service] ?? []);

watch(
    () => form.service,
    () => {
        form.action = availableActions.value[0];
        form.reference = '';
    },
);

const referenceRequired = computed(
    () => !['facility', 'rest'].includes(form.service),
);

const referenceHelp = computed(() => {
    const help: Record<ServiceType, string> = {
        library: 'Código o código de barras del ejemplar (ej. EJ-001).',
        locker: 'Código o QR del locker (ej. LKR-A-PB-001).',
        facility:
            'Folio de la reserva (RES-...). Opcional: vacío usa la reserva actual del alumno.',
        rest: 'Folio de la reservación (ZD-...). Opcional: vacío usa la reservación actual del alumno.',
        rental: 'Código de inventario del equipo (ej. INV-E4-001) o id de la renta.',
        service: 'Folio de la solicitud (ej. SER-2026-...).',
    };

    return help[form.service];
});

const filteredEvents = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.events;
    }

    return props.events.filter((event) =>
        [
            event.folio,
            event.credential,
            event.student_id ?? '',
            event.student_name ?? '',
            event.reference ?? '',
            serviceLabel(event.service),
            actionLabel(event.action),
        ].some((value) => value.toLowerCase().includes(term)),
    );
});

function serviceLabel(value: ServiceType): string {
    const labels: Record<ServiceType, string> = {
        library: 'Biblioteca',
        locker: 'Lockers',
        facility: 'Instalaciones',
        rest: 'Zonas de descanso',
        rental: 'Renta de equipos',
        service: 'Servicios e impresiones',
    };

    return labels[value];
}

function actionLabel(value: ActionType): string {
    const labels: Record<ActionType, string> = {
        loan: 'Préstamo',
        return: 'Devolución',
        entry: 'Acceso',
        checkin: 'Entrada (check-in)',
        checkout: 'Salida (check-out)',
        pickup: 'Recoger equipo',
        delivery: 'Entrega',
    };

    return labels[value];
}

function modeLabel(value: ScanMode): string {
    const labels: Record<ScanMode, string> = {
        qr: 'QR',
        nfc: 'NFC',
        manual: 'Manual',
    };

    return labels[value];
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

const formError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return Object.values(errors)[0] ?? '';
});

function validateAccess() {
    if (!form.credential.trim()) {
        credentialInput.value?.focus();

        return;
    }

    if (referenceRequired.value && !form.reference.trim()) {
        window.alert('Captura la referencia del servicio.');

        return;
    }

    form.post('/servicios-estudiante/validacion-servicios/validar', {
        preserveScroll: true,
        onSuccess: () => {
            dismissedResultId.value = null;
            form.credential = '';
            form.reference = '';

            nextTick(() => credentialInput.value?.focus());
        },
    });
}

function clearResult() {
    dismissedResultId.value = props.result?.id ?? null;
}
</script>

<template>
    <StudentServicesLayout
        title="Validación de servicios"
        subtitle="Control QR/NFC para uso y entrega de servicios"
    >
        <section class="hero">
            <div>
                <span class="hero-label">SERVICIOS · MÓDULO 5.11</span>

                <h2>Validación de acceso y uso</h2>

                <p>
                    Punto central para validar identidad y operaciones de
                    préstamo, entrada, salida, entrega, recolección y devolución
                    mediante QR/NFC. Cada escaneo queda registrado, autorizado o
                    no.
                </p>
            </div>

            <div class="hero-reader">
                <span>LECTOR</span>
                <strong>QR / NFC</strong>
                <small>Conectado al backend</small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>Eventos de hoy</span>
                <strong>{{ stats.today }}</strong>
                <small>Escaneos registrados</small>
            </article>

            <article class="stat-card">
                <span>Autorizados</span>
                <strong>{{ stats.granted }}</strong>
                <small>Operaciones válidas hoy</small>
            </article>

            <article class="stat-card">
                <span>Denegados</span>
                <strong>{{ stats.denied }}</strong>
                <small>Operaciones rechazadas hoy</small>
            </article>

            <article class="stat-card">
                <span>Servicios</span>
                <strong>{{ Object.keys(operations).length }}</strong>
                <small>Integrados</small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">VALIDACIÓN</span>

                    <h3>Registrar operación</h3>

                    <p>
                        Selecciona el servicio, la operación y escanea la
                        credencial del estudiante.
                    </p>
                </div>

                <span class="reader-ready">
                    <span />
                    Lector listo
                </span>
            </div>

            <div class="validation-container">
                <div class="scanner">
                    <div class="scanner-icon">
                        <div class="scanner-grid">
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                            <span />
                        </div>
                    </div>

                    <strong>Identificar estudiante</strong>

                    <p>
                        La credencial se resuelve con el contrato de identidad
                        (Equipo 1). Mientras tanto se acepta el id del usuario
                        (con o sin prefijo QR-/NFC-) o su correo.
                    </p>

                    <div class="scan-modes">
                        <button
                            v-for="mode in [
                                'qr',
                                'nfc',
                                'manual',
                            ] as ScanMode[]"
                            :key="mode"
                            type="button"
                            :class="{ active: form.method === mode }"
                            @click="form.method = mode"
                        >
                            {{ modeLabel(mode) }}
                        </button>
                    </div>
                </div>

                <form class="validation-form" @submit.prevent="validateAccess">
                    <div class="form-grid">
                        <div class="form-field full">
                            <label>
                                Credencial del estudiante
                                <span>*</span>
                            </label>

                            <input
                                ref="credentialInput"
                                v-model="form.credential"
                                type="text"
                                autocomplete="off"
                                placeholder="Escanea o captura la credencial"
                                autofocus
                            />

                            <small>Método: {{ modeLabel(form.method) }}</small>
                        </div>

                        <div class="form-field">
                            <label>Servicio <span>*</span></label>

                            <select v-model="form.service">
                                <option
                                    v-for="(_actions, service) in operations"
                                    :key="service"
                                    :value="service"
                                >
                                    {{ serviceLabel(service) }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Operación <span>*</span></label>

                            <select v-model="form.action">
                                <option
                                    v-for="action in availableActions"
                                    :key="action"
                                    :value="action"
                                >
                                    {{ actionLabel(action) }}
                                </option>
                            </select>
                        </div>

                        <div class="form-field full">
                            <label>
                                Referencia del servicio
                                <span v-if="referenceRequired">*</span>
                            </label>

                            <input
                                v-model="form.reference"
                                type="text"
                                autocomplete="off"
                                placeholder="Folio, código de recurso, ejemplar o equipo"
                            />

                            <small>{{ referenceHelp }}</small>
                        </div>

                        <div
                            v-if="
                                form.service === 'rental' &&
                                form.action === 'return'
                            "
                            class="form-field full"
                        >
                            <label>Condición del equipo al devolver</label>

                            <select v-model="form.condition">
                                <option value="good">Buen estado</option>
                                <option value="damaged">Dañado</option>
                                <option value="maintenance">
                                    Requiere mantenimiento
                                </option>
                                <option value="lost">Extraviado</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="formError" class="information-box error-box">
                        {{ formError }}
                    </div>

                    <div v-else class="information-box">
                        Cada operación se valida contra el módulo real
                        (biblioteca, lockers, reservas, renta o impresiones) y
                        queda en la bitácora de uso, aunque sea denegada.
                    </div>

                    <button
                        type="submit"
                        class="validate-button"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? 'Validando...'
                                : 'Validar operación'
                        }}
                    </button>
                </form>
            </div>
        </section>

        <section
            v-if="visibleResult"
            class="result-panel"
            :class="{
                granted: visibleResult.granted,
                denied: !visibleResult.granted,
            }"
        >
            <div class="result-icon">
                {{ visibleResult.granted ? '✓' : '×' }}
            </div>

            <div class="result-info">
                <span>
                    {{
                        visibleResult.granted
                            ? 'OPERACIÓN AUTORIZADA'
                            : 'OPERACIÓN DENEGADA'
                    }}
                </span>

                <h3>{{ visibleResult.message }}</h3>

                <p>
                    {{ serviceLabel(visibleResult.service) }} ·
                    {{ actionLabel(visibleResult.action) }}
                    <template v-if="visibleResult.reference">
                        · {{ visibleResult.reference }}
                    </template>
                    <template v-if="visibleResult.student_name">
                        · {{ visibleResult.student_name }}
                    </template>
                    · {{ visibleResult.folio }}
                </p>
            </div>

            <button type="button" class="clear-button" @click="clearResult">
                Cerrar
            </button>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">EVENTOS</span>

                    <h3>Registro de uso</h3>

                    <p>Últimas 100 validaciones registradas.</p>
                </div>
            </div>

            <div class="filters">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar por folio, estudiante, servicio o referencia..."
                />
            </div>

            <div v-if="filteredEvents.length" class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Estudiante</th>
                            <th>Servicio</th>
                            <th>Operación</th>
                            <th>Referencia</th>
                            <th>Método</th>
                            <th>Resultado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="event in filteredEvents" :key="event.id">
                            <td>
                                <strong class="folio">{{ event.folio }}</strong>
                            </td>

                            <td>
                                <strong class="student">
                                    {{
                                        event.student_name ?? 'No identificado'
                                    }}
                                </strong>

                                <small>{{ event.credential }}</small>
                            </td>

                            <td>{{ serviceLabel(event.service) }}</td>

                            <td>{{ actionLabel(event.action) }}</td>

                            <td>
                                <span class="reference">
                                    {{ event.reference ?? '—' }}
                                </span>
                            </td>

                            <td>
                                <span class="mode">{{
                                    modeLabel(event.mode)
                                }}</span>
                            </td>

                            <td>
                                <span
                                    class="result-badge"
                                    :class="{
                                        success: event.granted,
                                        error: !event.granted,
                                    }"
                                    :title="event.message"
                                >
                                    {{
                                        event.granted
                                            ? 'Autorizado'
                                            : 'Denegado'
                                    }}
                                </span>
                            </td>

                            <td>{{ formatDateTime(event.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="empty-events">
                Aún no hay validaciones registradas.
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

.hero-reader {
    min-width: 170px;
    padding: 16px 19px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
}

.hero-reader span {
    display: block;
    color: #d8e4f8;
    font-size: 8px;
    font-weight: 800;
}

.hero-reader strong {
    display: block;
    margin-top: 5px;
    font-size: 22px;
}

.hero-reader small {
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

.panel-header {
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

.panel-header h3 {
    margin: 0;
    color: #25324a;
    font-size: 16px;
}

.panel-header p {
    margin: 5px 0 0;
    color: #8794a7;
    font-size: 11px;
}

.reader-ready {
    padding: 7px 10px;
    display: flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    background: #e8f6ee;
    color: #26734c;
    font-size: 9px;
    font-weight: 800;
}

.reader-ready span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #39a66e;
}

.validation-container {
    padding: 25px 21px;
    display: grid;
    grid-template-columns:
        minmax(230px, 0.7fr)
        minmax(360px, 1.3fr);
    gap: 25px;
}

.scanner {
    padding: 25px;
    border-radius: 10px;
    background: #f5f8fc;
    text-align: center;
}

.scanner-icon {
    width: 110px;
    height: 110px;
    margin: 0 auto 15px;
    display: grid;
    place-items: center;
    border: 2px solid #3970c1;
    border-radius: 12px;
    background: white;
}

.scanner-grid {
    width: 66px;
    height: 66px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 5px;
}

.scanner-grid span {
    border-radius: 2px;
    background: #315fa6;
}

.scanner-grid span:nth-child(even) {
    background: #dce7f7;
}

.scanner > strong {
    color: #34435a;
    font-size: 12px;
}

.scanner > p {
    margin: 6px 0 15px;
    color: #8390a3;
    font-size: 9px;
    line-height: 1.5;
}

.scan-modes {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 5px;
}

.scan-modes button {
    min-height: 32px;
    border: 1px solid #d7e0eb;
    border-radius: 6px;
    background: white;
    color: #6f7f94;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.scan-modes button.active {
    border-color: #3970c1;
    background: #e8f0fc;
    color: #315fa6;
}

.validation-form {
    padding: 5px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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

.form-field label span {
    color: #bc4545;
}

.form-field input,
.form-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 11px;
}

.form-field small {
    display: block;
    margin-top: 5px;
    color: #929daf;
    font-size: 8px;
}

.information-box {
    margin-top: 16px;
    padding: 11px 13px;
    border: 1px solid #d5e1f1;
    border-radius: 7px;
    background: #eef4fc;
    color: #657690;
    font-size: 9px;
    line-height: 1.5;
}

.validate-button {
    width: 100%;
    min-height: 42px;
    margin-top: 15px;
    border: 1px solid #2c63b7;
    border-radius: 7px;
    background: #2c63b7;
    color: white;
    font: inherit;
    font-size: 10px;
    font-weight: 800;
    cursor: pointer;
}

.result-panel {
    margin-top: 18px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    border: 1px solid;
    border-radius: 10px;
}

.result-panel.granted {
    border-color: #bfe2cd;
    background: #eaf7f0;
}

.result-panel.denied {
    border-color: #e8c7ca;
    background: #fbedee;
}

.result-icon {
    width: 45px;
    height: 45px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 50%;
    color: white;
    font-size: 22px;
    font-weight: 900;
}

.granted .result-icon {
    background: #2c945f;
}

.denied .result-icon {
    background: #b44d58;
}

.result-info {
    flex: 1;
}

.result-info > span {
    font-size: 8px;
    font-weight: 900;
    letter-spacing: 0.08em;
}

.granted .result-info > span {
    color: #28794f;
}

.denied .result-info > span {
    color: #9a4650;
}

.result-info h3 {
    margin: 4px 0 0;
    color: #314057;
    font-size: 14px;
}

.result-info p {
    margin: 4px 0 0;
    color: #748297;
    font-size: 9px;
}

.clear-button {
    padding: 7px 10px;
    border: 1px solid #d4dde8;
    border-radius: 6px;
    background: white;
    color: #64748a;
    font: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
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
    color: #285aa6;
}

.student {
    display: block;
    color: #42546d;
}

td small {
    display: block;
    margin-top: 3px;
    color: #9aa4b3;
    font-size: 8px;
}

.reference {
    font-family: monospace;
    font-size: 9px;
}

.mode {
    padding: 4px 7px;
    border-radius: 5px;
    background: #edf1f5;
    color: #596a81;
    font-size: 8px;
    font-weight: 800;
}

.result-badge {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
}

.result-badge.success {
    background: #e4f6ec;
    color: #217a4e;
}

.result-badge.error {
    background: #f4e7e8;
    color: #9c4c55;
}

@media (max-width: 900px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .validation-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header,
    .result-panel {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-reader {
        width: 100%;
        box-sizing: border-box;
    }

    .form-grid {
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

    .scan-modes {
        grid-template-columns: 1fr;
    }
}
</style>
<style scoped>
/* Estilos agregados al conectar el módulo 5.11 con el backend */
.information-box.error-box {
    border-color: #e6c9cd;
    background: #fbebed;
    color: #9d4650;
    font-weight: 700;
}

.validate-button:disabled {
    opacity: 0.6;
    cursor: wait;
}

.empty-events {
    padding: 35px 20px;
    text-align: center;
    color: #8c99aa;
    font-size: 10px;
}
</style>
