<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { computed, ref } from 'vue';

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
    | 'entry'
    | 'pickup'
    | 'return'
    | 'delivery'
    | 'checkin';

interface ServiceEvent {
    id: number;
    folio: string;
    credential: string;
    student: string;
    service: ServiceType;
    action: ActionType;
    reference: string;
    mode: ScanMode;
    granted: boolean;
    message: string;
    createdAt: string;
}

const events = ref<ServiceEvent[]>([
    {
        id: 1,
        folio: 'EVT-2026-001',
        credential: 'QR-EST-0001',
        student: 'EST-0001',
        service: 'library',
        action: 'loan',
        reference: 'LIB-EJ-001',
        mode: 'qr',
        granted: true,
        message: 'Préstamo autorizado.',
        createdAt: '28/09/2026 09:15',
    },
    {
        id: 2,
        folio: 'EVT-2026-002',
        credential: 'NFC-EST-0002',
        student: 'EST-0002',
        service: 'locker',
        action: 'entry',
        reference: 'LKR-A-PB-001',
        mode: 'nfc',
        granted: true,
        message: 'Acceso al locker autorizado.',
        createdAt: '28/09/2026 09:42',
    },
    {
        id: 3,
        folio: 'EVT-2026-003',
        credential: 'QR-EST-0099',
        student: 'EST-0099',
        service: 'rental',
        action: 'pickup',
        reference: 'REN-2026-010',
        mode: 'qr',
        granted: false,
        message: 'No existe una renta activa para esta referencia.',
        createdAt: '28/09/2026 10:05',
    },
]);

const scanMode = ref<ScanMode>('qr');
const credential = ref('');
const service = ref<ServiceType>('library');
const action = ref<ActionType>('loan');
const reference = ref('');

const search = ref('');
const result = ref<ServiceEvent | null>(null);

const successfulEvents = computed(() =>
    events.value.filter(
        (event) => event.granted,
    ).length,
);

const deniedEvents = computed(() =>
    events.value.filter(
        (event) => !event.granted,
    ).length,
);

const todayEvents = computed(
    () => events.value.length,
);

const filteredEvents = computed(() => {
    const term =
        search.value
            .trim()
            .toLowerCase();

    if (!term) {
        return events.value;
    }

    return events.value.filter(
        (event) =>
            [
                event.folio,
                event.credential,
                event.student,
                event.reference,
                serviceLabel(
                    event.service,
                ),
                actionLabel(
                    event.action,
                ),
            ].some((value) =>
                value
                    .toLowerCase()
                    .includes(term),
            ),
    );
});

function serviceLabel(
    value: ServiceType,
): string {
    const labels: Record<
        ServiceType,
        string
    > = {
        library: 'Biblioteca',
        locker: 'Lockers',
        facility:
            'Instalaciones',
        rest: 'Zonas de descanso',
        rental:
            'Renta de equipos',
        service:
            'Servicios e impresiones',
    };

    return labels[value];
}

function actionLabel(
    value: ActionType,
): string {
    const labels: Record<
        ActionType,
        string
    > = {
        loan: 'Préstamo',
        entry: 'Entrada',
        pickup: 'Recoger',
        return: 'Devolución',
        delivery: 'Entrega',
        checkin: 'Check-in',
    };

    return labels[value];
}

function modeLabel(
    value: ScanMode,
): string {
    const labels: Record<
        ScanMode,
        string
    > = {
        qr: 'QR',
        nfc: 'NFC',
        manual: 'Manual',
    };

    return labels[value];
}

function updateActionOptions() {
    switch (service.value) {
        case 'library':
            action.value = 'loan';
            break;

        case 'locker':
        case 'facility':
        case 'rest':
            action.value = 'entry';
            break;

        case 'rental':
            action.value = 'pickup';
            break;

        case 'service':
            action.value = 'delivery';
            break;
    }
}

function validateAccess() {
    if (
        !credential.value.trim() ||
        !reference.value.trim()
    ) {
        window.alert(
            'Captura la credencial y la referencia del servicio.',
        );

        return;
    }

    /*
     * Simulación visual.
     * El backend real posteriormente
     * consultará identidad, credenciales
     * y el módulo correspondiente.
     */

    const normalizedCredential =
        credential.value
            .trim()
            .toUpperCase();

    const normalizedReference =
        reference.value
            .trim()
            .toUpperCase();

    const denied =
        normalizedCredential.includes(
            'INVALID',
        ) ||
        normalizedReference.includes(
            'INVALID',
        ) ||
        normalizedReference.includes(
            'NO-EXISTE',
        );

    const eventId =
        events.value.length + 1;

    const newEvent: ServiceEvent = {
        id: eventId,

        folio:
            'EVT-2026-' +
            String(
                eventId + 3,
            ).padStart(
                3,
                '0',
            ),

        credential:
        normalizedCredential,

        student:
            normalizedCredential
                .replace('QR-', '')
                .replace('NFC-', ''),

        service:
        service.value,

        action:
        action.value,

        reference:
        normalizedReference,

        mode:
        scanMode.value,

        granted: !denied,

        message: denied
            ? 'La operación no pudo ser validada.'
            : successMessage(
                service.value,
                action.value,
            ),

        createdAt:
            new Date().toLocaleString(
                'es-MX',
            ),
    };

    events.value.unshift(
        newEvent,
    );

    result.value =
        newEvent;

    credential.value = '';
    reference.value = '';
}

function successMessage(
    selectedService: ServiceType,
    selectedAction: ActionType,
): string {
    if (
        selectedService ===
        'library' &&
        selectedAction === 'loan'
    ) {
        return 'Préstamo autorizado.';
    }

    if (
        selectedService ===
        'library' &&
        selectedAction === 'return'
    ) {
        return 'Devolución registrada.';
    }

    if (
        selectedService ===
        'locker'
    ) {
        return 'Uso del locker autorizado.';
    }

    if (
        selectedService ===
        'facility' ||
        selectedService ===
        'rest'
    ) {
        return 'Entrada autorizada.';
    }

    if (
        selectedService ===
        'rental' &&
        selectedAction === 'pickup'
    ) {
        return 'Entrega del equipo autorizada.';
    }

    if (
        selectedService ===
        'rental' &&
        selectedAction === 'return'
    ) {
        return 'Devolución del equipo registrada.';
    }

    if (
        selectedService ===
        'service'
    ) {
        return 'Entrega del servicio autorizada.';
    }

    return 'Operación validada correctamente.';
}

function clearResult() {
    result.value = null;
}
</script>

<template>
    <StudentServicesLayout
        title="Validación de servicios"
        subtitle="Control QR/NFC para uso y entrega de servicios"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    SERVICIOS · MÓDULO 5.11
                </span>

                <h2>
                    Validación de acceso y uso
                </h2>

                <p>
                    Punto central para validar
                    identidad y operaciones de
                    préstamo, entrada,
                    entrega, recolección y
                    devolución mediante
                    QR/NFC.
                </p>
            </div>

            <div class="hero-reader">
                <span>
                    LECTOR
                </span>

                <strong>
                    QR / NFC
                </strong>

                <small>
                    Modo de prueba
                </small>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <span>
                    Eventos
                </span>

                <strong>
                    {{ todayEvents }}
                </strong>

                <small>
                    Registrados
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Autorizados
                </span>

                <strong>
                    {{
                        successfulEvents
                    }}
                </strong>

                <small>
                    Operaciones válidas
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Denegados
                </span>

                <strong>
                    {{ deniedEvents }}
                </strong>

                <small>
                    Operaciones rechazadas
                </small>
            </article>

            <article class="stat-card">
                <span>
                    Servicios
                </span>

                <strong>
                    6
                </strong>

                <small>
                    Integrables
                </small>
            </article>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        VALIDACIÓN
                    </span>

                    <h3>
                        Registrar operación
                    </h3>

                    <p>
                        Selecciona el servicio,
                        operación y captura la
                        credencial del
                        estudiante.
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

                    <strong>
                        Identificar estudiante
                    </strong>

                    <p>
                        En la integración real
                        esta sección consumirá
                        las credenciales del
                        Equipo 1.
                    </p>

                    <div class="scan-modes">
                        <button
                            type="button"
                            :class="{
                                active:
                                    scanMode ===
                                    'qr',
                            }"
                            @click="
                                scanMode = 'qr'
                            "
                        >
                            QR
                        </button>

                        <button
                            type="button"
                            :class="{
                                active:
                                    scanMode ===
                                    'nfc',
                            }"
                            @click="
                                scanMode = 'nfc'
                            "
                        >
                            NFC
                        </button>

                        <button
                            type="button"
                            :class="{
                                active:
                                    scanMode ===
                                    'manual',
                            }"
                            @click="
                                scanMode =
                                    'manual'
                            "
                        >
                            Manual
                        </button>
                    </div>
                </div>

                <div class="validation-form">
                    <div class="form-grid">
                        <div class="form-field full">
                            <label>
                                Credencial del
                                estudiante
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    credential
                                "
                                type="text"
                                placeholder="Ej. QR-EST-0001"
                            />

                            <small>
                                Método:
                                {{
                                    modeLabel(
                                        scanMode,
                                    )
                                }}
                            </small>
                        </div>

                        <div class="form-field">
                            <label>
                                Servicio
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    service
                                "
                                @change="
                                    updateActionOptions
                                "
                            >
                                <option
                                    value="library"
                                >
                                    Biblioteca
                                </option>

                                <option
                                    value="locker"
                                >
                                    Lockers
                                </option>

                                <option
                                    value="facility"
                                >
                                    Instalaciones
                                </option>

                                <option
                                    value="rest"
                                >
                                    Zonas de
                                    descanso
                                </option>

                                <option
                                    value="rental"
                                >
                                    Renta de
                                    equipos
                                </option>

                                <option
                                    value="service"
                                >
                                    Servicios e
                                    impresiones
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>
                                Operación
                                <span>*</span>
                            </label>

                            <select
                                v-model="
                                    action
                                "
                            >
                                <option
                                    v-if="
                                        service ===
                                        'library'
                                    "
                                    value="loan"
                                >
                                    Préstamo
                                </option>

                                <option
                                    v-if="
                                        service ===
                                        'library'
                                    "
                                    value="return"
                                >
                                    Devolución
                                </option>

                                <option
                                    v-if="
                                        [
                                            'locker',
                                            'facility',
                                            'rest',
                                        ].includes(
                                            service,
                                        )
                                    "
                                    value="entry"
                                >
                                    Entrada
                                </option>

                                <option
                                    v-if="
                                        [
                                            'facility',
                                            'rest',
                                        ].includes(
                                            service,
                                        )
                                    "
                                    value="checkin"
                                >
                                    Check-in
                                </option>

                                <option
                                    v-if="
                                        service ===
                                        'rental'
                                    "
                                    value="pickup"
                                >
                                    Recoger
                                    equipo
                                </option>

                                <option
                                    v-if="
                                        service ===
                                        'rental'
                                    "
                                    value="return"
                                >
                                    Devolver
                                    equipo
                                </option>

                                <option
                                    v-if="
                                        service ===
                                        'service'
                                    "
                                    value="delivery"
                                >
                                    Entrega
                                </option>
                            </select>
                        </div>

                        <div class="form-field full">
                            <label>
                                Referencia del
                                servicio
                                <span>*</span>
                            </label>

                            <input
                                v-model="
                                    reference
                                "
                                type="text"
                                placeholder="Ej. LKR-A-PB-001, LIB-EJ-001, REN-2026-010..."
                            />

                            <small>
                                Puede ser folio,
                                código de recurso,
                                préstamo,
                                reservación o
                                renta.
                            </small>
                        </div>
                    </div>

                    <div class="information-box">
                        Esta pantalla todavía
                        utiliza validación
                        simulada. Cuando
                        construyamos el backend,
                        Equipo 1 resolverá la
                        identidad y Equipo 5
                        verificará si el alumno
                        puede realizar la
                        operación solicitada.
                    </div>

                    <button
                        type="button"
                        class="validate-button"
                        @click="
                            validateAccess
                        "
                    >
                        Validar operación
                    </button>
                </div>
            </div>
        </section>

        <section
            v-if="result"
            class="result-panel"
            :class="{
                granted:
                    result.granted,
                denied:
                    !result.granted,
            }"
        >
            <div class="result-icon">
                {{
                    result.granted
                        ? '✓'
                        : '×'
                }}
            </div>

            <div class="result-info">
                <span>
                    {{
                        result.granted
                            ? 'OPERACIÓN AUTORIZADA'
                            : 'OPERACIÓN DENEGADA'
                    }}
                </span>

                <h3>
                    {{ result.message }}
                </h3>

                <p>
                    {{
                        serviceLabel(
                            result.service,
                        )
                    }}
                    ·
                    {{
                        actionLabel(
                            result.action,
                        )
                    }}
                    ·
                    {{
                        result.reference
                    }}
                </p>
            </div>

            <button
                type="button"
                class="clear-button"
                @click="clearResult"
            >
                Cerrar
            </button>
        </section>

        <section class="content-panel">
            <div class="panel-header">
                <div>
                    <span class="panel-label">
                        EVENTOS
                    </span>

                    <h3>
                        Registro de uso
                    </h3>

                    <p>
                        Historial simulado de
                        validaciones realizadas.
                    </p>
                </div>
            </div>

            <div class="filters">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar por folio, estudiante, servicio o referencia..."
                />
            </div>

            <div
                v-if="
                    filteredEvents.length
                "
                class="table-container"
            >
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
                    <tr
                        v-for="
                                event in
                                filteredEvents
                            "
                        :key="
                                event.id
                            "
                    >
                        <td>
                            <strong class="folio">
                                {{
                                    event.folio
                                }}
                            </strong>
                        </td>

                        <td>
                            <strong class="student">
                                {{
                                    event.student
                                }}
                            </strong>

                            <small>
                                {{
                                    event.credential
                                }}
                            </small>
                        </td>

                        <td>
                            {{
                                serviceLabel(
                                    event.service,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                actionLabel(
                                    event.action,
                                )
                            }}
                        </td>

                        <td>
                                <span class="reference">
                                    {{
                                        event.reference
                                    }}
                                </span>
                        </td>

                        <td>
                                <span class="mode">
                                    {{
                                        modeLabel(
                                            event.mode,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                                <span
                                    class="result-badge"
                                    :class="{
                                        success:
                                            event.granted,
                                        error:
                                            !event.granted,
                                    }"
                                >
                                    {{
                                        event.granted
                                            ? 'Autorizado'
                                            : 'Denegado'
                                    }}
                                </span>
                        </td>

                        <td>
                            {{
                                event.createdAt
                            }}
                        </td>
                    </tr>
                    </tbody>
                </table>
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
    background: rgba(255,255,255,.1);
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
    grid-template-columns: repeat(4,1fr);
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
        minmax(230px,.7fr)
        minmax(360px,1.3fr);
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
    grid-template-columns: repeat(3,1fr);
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
    grid-template-columns: repeat(3,1fr);
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
    grid-template-columns: repeat(2,1fr);
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
    letter-spacing: .08em;
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
        grid-template-columns: repeat(2,1fr);
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
