<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref } from 'vue';

interface AccessResult {
    granted: boolean;
    locker_code?: string;
    student_id?: string;
    folio?: string;
    ends_at?: string;
    message: string;
}

const props = defineProps<{
    result: AccessResult | null;
}>();

const BASE_URL =
    '/servicios-estudiante/lockers';

const form = useForm({
    code: '',
});

const inputRef =
    ref<HTMLInputElement | null>(
        null,
    );

function focusInput() {
    nextTick(() => {
        inputRef.value?.focus();
    });
}

function submitCode() {
    form.post(
        `${BASE_URL}/acceso/validar`,
        {
            preserveScroll: true,

            onSuccess: () => {
                form.reset('code');
                focusInput();
            },

            onError: () => {
                focusInput();
            },
        },
    );
}

function formatDate(
    value?: string,
): string {
    if (!value) {
        return '—';
    }

    const parts =
        value
            .slice(0, 10)
            .split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

onMounted(() => {
    /*
     * Si llegamos desde la pantalla
     * de asignaciones mediante el
     * botón "Acceso", recibimos el
     * código del locker en la URL.
     */
    const params =
        new URLSearchParams(
            window.location.search,
        );

    const locker =
        params.get('locker');

    if (locker) {
        form.code = locker;
    }

    focusInput();
});
</script>

<template>
    <StudentServicesLayout
        title="Validación de acceso"
        subtitle="Validación QR/NFC de lockers asignados"
    >
        <section class="hero">
            <div>
                <span class="hero-label">
                    LOCKERS · MÓDULO 5.4
                </span>

                <h2>
                    Validar acceso
                </h2>

                <p>
                    Escanea el código QR del
                    locker o escribe
                    manualmente su código
                    para comprobar que
                    cuenta con una
                    asignación activa.
                </p>
            </div>

            <div class="hero-device">
                <span>
                    SISTEMA DE ACCESO
                </span>

                <strong>
                    QR / NFC
                </strong>

                <small>
                    Validación en tiempo real
                </small>
            </div>
        </section>

        <section
            class="module-navigation"
        >
            <Link
                :href="BASE_URL"
                class="module-link"
            >
                Catálogo
            </Link>

            <Link
                :href="`${BASE_URL}/periodos`"
                class="module-link"
            >
                Periodos y costos
            </Link>

            <Link
                :href="`${BASE_URL}/solicitudes`"
                class="module-link"
            >
                Solicitudes
            </Link>

            <Link
                :href="`${BASE_URL}/asignaciones`"
                class="module-link"
            >
                Asignaciones
            </Link>

            <Link
                :href="`${BASE_URL}/acceso`"
                class="module-link active"
            >
                Validar acceso
            </Link>
        </section>

        <section
            class="content-panel"
        >
            <div
                class="panel-header"
            >
                <div>
                    <span
                        class="panel-label"
                    >
                        CONTROL DE ACCESO
                    </span>

                    <h3>
                        Escanear locker
                    </h3>

                    <p>
                        El sistema buscará el
                        código en MongoDB y
                        comprobará que exista
                        una asignación activa.
                    </p>
                </div>

                <div
                    class="scanner-status"
                >
                    <span
                        class="scanner-dot"
                    />

                    Lector listo
                </div>
            </div>

            <div
                class="scanner-container"
            >
                <div
                    class="scanner-visual"
                >
                    <div
                        class="qr-frame"
                    >
                        <div
                            class="qr-pattern"
                        >
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
                        Escanea el código
                    </strong>

                    <p>
                        Puedes utilizar un
                        lector QR/NFC o
                        escribir el código
                        manualmente.
                    </p>
                </div>

                <form
                    class="scan-form"
                    @submit.prevent="
                        submitCode
                    "
                >
                    <div
                        class="form-field"
                    >
                        <label
                            for="locker-code"
                        >
                            Código del locker
                        </label>

                        <input
                            id="locker-code"
                            ref="inputRef"
                            v-model="
                                form.code
                            "
                            type="text"
                            autocomplete="off"
                            placeholder="Ej. QR-LKR-A-PB-001"
                        />

                        <small
                            class="help-text"
                        >
                            También puedes
                            escribir directamente
                            LKR-A-PB-001.
                        </small>

                        <small
                            v-if="
                                form.errors
                                    .code
                            "
                            class="field-error"
                        >
                            {{
                                form.errors
                                    .code
                            }}
                        </small>
                    </div>

                    <button
                        type="submit"
                        class="primary-button"
                        :disabled="
                            form.processing
                        "
                    >
                        {{
                            form.processing
                                ? 'Validando...'
                                : 'Validar acceso'
                        }}
                    </button>
                </form>
            </div>
        </section>

        <section
            v-if="
                props.result !== null
            "
            class="result-panel"
            :class="{
                granted:
                    props.result
                        .granted,
                denied:
                    !props.result
                        .granted,
            }"
        >
            <div
                class="result-icon"
            >
                {{
                    props.result.granted
                        ? '✓'
                        : '×'
                }}
            </div>

            <div
                class="result-content"
            >
                <span
                    class="result-label"
                >
                    {{
                        props.result.granted
                            ? 'ACCESO CONCEDIDO'
                            : 'ACCESO DENEGADO'
                    }}
                </span>

                <h3>
                    {{
                        props.result.message
                    }}
                </h3>

                <p
                    v-if="
                        props.result.granted
                    "
                >
                    Se encontró una
                    asignación activa para
                    este locker.
                </p>

                <p v-else>
                    El código no cumple las
                    condiciones necesarias
                    para permitir el acceso.
                </p>
            </div>
        </section>

        <section
            v-if="
                props.result !== null &&
                (
                    props.result
                        .locker_code ||
                    props.result
                        .student_id ||
                    props.result
                        .folio
                )
            "
            class="content-panel details-panel"
        >
            <div
                class="panel-header"
            >
                <div>
                    <span
                        class="panel-label"
                    >
                        RESULTADO
                    </span>

                    <h3>
                        Datos de validación
                    </h3>

                    <p>
                        Información encontrada
                        en la asignación del
                        locker.
                    </p>
                </div>
            </div>

            <div
                class="details-grid"
            >
                <article
                    v-if="
                        props.result
                            .locker_code
                    "
                    class="detail-card"
                >
                    <span>
                        Locker
                    </span>

                    <strong>
                        {{
                            props.result
                                .locker_code
                        }}
                    </strong>
                </article>

                <article
                    v-if="
                        props.result
                            .student_id
                    "
                    class="detail-card"
                >
                    <span>
                        Estudiante
                    </span>

                    <strong>
                        {{
                            props.result
                                .student_id
                        }}
                    </strong>
                </article>

                <article
                    v-if="
                        props.result
                            .folio
                    "
                    class="detail-card"
                >
                    <span>
                        Folio de asignación
                    </span>

                    <strong>
                        {{
                            props.result
                                .folio
                        }}
                    </strong>
                </article>

                <article
                    v-if="
                        props.result
                            .ends_at
                    "
                    class="detail-card"
                >
                    <span>
                        Vigencia
                    </span>

                    <strong>
                        {{
                            formatDate(
                                props.result
                                    .ends_at,
                            )
                        }}
                    </strong>
                </article>
            </div>
        </section>

        <section
            class="information-panel"
        >
            <div
                class="information-number"
            >
                01
            </div>

            <div>
                <strong>
                    ¿Cómo funciona?
                </strong>

                <p>
                    El código se compara con
                    los campos
                    <code>code</code> y
                    <code>qr_code</code> de
                    la colección
                    <code>lockers</code>.
                    Para conceder el acceso,
                    el locker debe estar
                    ocupado y tener una
                    asignación con estado
                    activo en
                    <code>locker_assignments</code>.
                </p>
            </div>
        </section>
    </StudentServicesLayout>
</template>

<style scoped>
.hero {
    padding: 24px 27px;
    display: flex;
    align-items: center;
    justify-content:
        space-between;
    gap: 24px;
    border-radius: 12px;
    background: #2f5eb6;
    color: white;
}

.hero-label,
.panel-label,
.result-label {
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

.hero-device {
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

.hero-device span {
    display: block;
    color: #d8e4f8;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.08em;
}

.hero-device strong {
    display: block;
    margin-top: 5px;
    font-size: 22px;
}

.hero-device small {
    display: block;
    margin-top: 3px;
    color: #bfd2ef;
    font-size: 8px;
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

.panel-header {
    padding: 18px 21px;
    display: flex;
    align-items: center;
    justify-content:
        space-between;
    gap: 20px;
    border-bottom:
        1px solid #e5e9ef;
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

.scanner-status {
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

.scanner-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #39a66e;
}

.scanner-container {
    padding: 28px 25px;
    display: grid;
    grid-template-columns:
        minmax(
            220px,
            0.7fr
        )
        minmax(
            300px,
            1.3fr
        );
    align-items: center;
    gap: 35px;
}

.scanner-visual {
    padding: 25px;
    border-radius: 10px;
    background: #f6f9fd;
    text-align: center;
}

.qr-frame {
    width: 115px;
    height: 115px;
    margin: 0 auto 15px;
    display: grid;
    place-items: center;
    border: 2px solid #3970c1;
    border-radius: 12px;
    background: white;
}

.qr-pattern {
    width: 70px;
    height: 70px;
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 5px;
}

.qr-pattern span {
    border-radius: 2px;
    background: #2f5eb6;
}

.qr-pattern span:nth-child(2),
.qr-pattern span:nth-child(4),
.qr-pattern span:nth-child(6),
.qr-pattern span:nth-child(8) {
    background: #dce7f7;
}

.scanner-visual strong {
    display: block;
    color: #34435a;
    font-size: 13px;
}

.scanner-visual p {
    margin: 6px auto 0;
    max-width: 230px;
    color: #8a97aa;
    font-size: 10px;
    line-height: 1.5;
}

.scan-form {
    display: flex;
    align-items: flex-end;
    gap: 10px;
}

.form-field {
    flex: 1;
}

.form-field label {
    display: block;
    margin-bottom: 6px;
    color: #45556c;
    font-size: 10px;
    font-weight: 700;
}

.form-field input {
    width: 100%;
    box-sizing: border-box;
    padding: 15px 14px;
    border: 1px solid #d4dde8;
    border-radius: 8px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.02em;
}

.form-field input:focus {
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

.help-text {
    display: block;
    margin-top: 6px;
    color: #919daf;
    font-size: 8px;
}

.field-error {
    display: block;
    margin-top: 6px;
    color: #b83b3b;
    font-size: 9px;
    font-weight: 700;
}

.primary-button {
    min-height: 48px;
    padding: 0 19px;
    border: 1px solid #2c63b7;
    border-radius: 8px;
    background: #2c63b7;
    color: white;
    font: inherit;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.primary-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.result-panel {
    margin-top: 18px;
    padding: 21px 23px;
    display: flex;
    align-items: center;
    gap: 17px;
    border: 1px solid;
    border-radius: 11px;
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
    width: 50px;
    height: 50px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 50%;
    color: white;
    font-size: 24px;
    font-weight: 900;
}

.granted .result-icon {
    background: #2c945f;
}

.denied .result-icon {
    background: #b44d58;
}

.result-label {
    margin: 0;
}

.granted .result-label {
    color: #28794f;
}

.denied .result-label {
    color: #9a4650;
}

.result-content h3 {
    margin: 4px 0 0;
    color: #314057;
    font-size: 15px;
}

.result-content p {
    margin: 5px 0 0;
    color: #748297;
    font-size: 10px;
}

.details-panel {
    overflow: visible;
}

.details-grid {
    padding: 20px 21px;
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);
    gap: 12px;
}

.detail-card {
    padding: 14px;
    border: 1px solid #e0e6ee;
    border-radius: 8px;
    background: #fafcff;
}

.detail-card span {
    display: block;
    color: #8b98aa;
    font-size: 8px;
    font-weight: 700;
}

.detail-card strong {
    display: block;
    margin-top: 5px;
    color: #315a9f;
    font-size: 11px;
    word-break: break-word;
}

.information-panel {
    margin-top: 18px;
    padding: 17px 19px;
    display: flex;
    align-items: flex-start;
    gap: 13px;
    border: 1px solid #d9e3f0;
    border-radius: 10px;
    background: #f3f7fc;
}

.information-number {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 7px;
    background: #dfeafb;
    color: #315fa6;
    font-size: 9px;
    font-weight: 900;
}

.information-panel strong {
    color: #3f5068;
    font-size: 10px;
}

.information-panel p {
    margin: 4px 0 0;
    color: #748399;
    font-size: 9px;
    line-height: 1.6;
}

.information-panel code {
    padding: 2px 4px;
    border-radius: 3px;
    background: #e4ebf4;
    color: #365d98;
    font-size: 8px;
}

@media (max-width: 900px) {
    .scanner-container {
        grid-template-columns: 1fr;
    }

    .details-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .hero-device {
        width: 100%;
        box-sizing: border-box;
    }

    .scan-form {
        align-items: stretch;
        flex-direction: column;
    }

    .result-panel {
        align-items: flex-start;
    }
}

@media (max-width: 520px) {
    .details-grid {
        grid-template-columns: 1fr;
    }

    .result-panel {
        flex-direction: column;
    }
}
</style>
