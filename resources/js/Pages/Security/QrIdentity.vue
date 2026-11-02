<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import QRCode from 'qrcode';
import { BrowserQRCodeReader } from '@zxing/library';

const props = defineProps({
    ttlSeconds: { type: Number, default: 30 },
    identificationPayload: { type: String, default: '' },
    recentValidations: { type: Array, default: () => [] },
    historyPageSize: { type: Number, default: 15 },
    canValidate: { type: Boolean, default: false },
});

const dynamicCanvas = ref(null);
const idCanvas = ref(null);
const code = ref(null);
const shortCode = ref(null);
const secondsRemaining = ref(0);
const loading = ref(false);
const validations = ref(props.recentValidations);
const historyOffset = ref(props.recentValidations.length);
const historyHasMore = ref(props.recentValidations.length >= props.historyPageSize);
const historyLoading = ref(false);

// --- Simulador de validación externa ---
const simCode = ref('');
const simContext = ref('biblioteca-central');
const simLevel = ref('basic');
const simResult = ref(null);
const simLoading = ref(false);

let tickTimer = null;
let refreshTimer = null;

async function drawQr(canvasEl, payload) {
    if (!canvasEl) return;
    await QRCode.toCanvas(canvasEl, payload, {
        width: 220,
        margin: 1,
        color: { dark: '#00338D', light: '#FFFFFF' },
    });
}

async function generate() {
    loading.value = true;
    try {
        const { data } = await window.axios.post(route('identity.qr.generate'));
        code.value = data.code;
        shortCode.value = data.short_code;
        secondsRemaining.value = data.seconds_remaining;
        await drawQr(dynamicCanvas.value, data.qr_payload);
        scheduleAutoRefresh();
    } finally {
        loading.value = false;
    }
}

function scheduleAutoRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        generate();
        reloadHistory();
    }, secondsRemaining.value * 1000);
}

function startTicking() {
    clearInterval(tickTimer);
    tickTimer = setInterval(() => {
        if (secondsRemaining.value > 0) {
            secondsRemaining.value -= 1;
        }
    }, 1000);
}

async function reloadHistory() {
    const { data } = await window.axios.get(route('identity.qr.history'));
    validations.value = data.items;
    historyOffset.value = data.next_offset;
    historyHasMore.value = data.has_more;
}

async function loadMoreHistory() {
    historyLoading.value = true;
    try {
        const { data } = await window.axios.get(route('identity.qr.history'), {
            params: { offset: historyOffset.value },
        });
        validations.value = [...validations.value, ...data.items];
        historyOffset.value = data.next_offset;
        historyHasMore.value = data.has_more;
    } finally {
        historyLoading.value = false;
    }
}

async function runSimulation() {
    if (!props.canValidate || !simCode.value) return;
    simLoading.value = true;
    simResult.value = null;
    try {
        const { data } = await window.axios.post(route('identity.qr.simulate'), {
            code: simCode.value,
            context: simContext.value,
            level: simLevel.value,
        });
        simResult.value = data;
        await reloadHistory();
    } finally {
        simLoading.value = false;
    }
}

function useCurrentCodeInSimulator() {
    simCode.value = code.value ?? '';
}

function useShortCodeInSimulator() {
    simCode.value = shortCode.value ?? '';
}

// --- Escáner real con cámara (reemplaza depender de la app de cámara
// del sistema, que en iOS no reconoce QR de texto plano como este) ---
const scannerVideo = ref(null);
const scanning = ref(false);
const scanError = ref('');
const scanSuccessFlash = ref(false);
let codeReader = null;

async function startScan() {
    scanError.value = '';

    if (!window.isSecureContext) {
        scanError.value = 'La cámara solo funciona en HTTPS (o localhost). Este origen no es seguro.';
        return;
    }

    scanning.value = true;

    try {
        codeReader = new BrowserQRCodeReader();
        const devices = await BrowserQRCodeReader.listVideoInputDevices();

        if (devices.length === 0) {
            throw new Error('sin-camaras');
        }

        // Preferir la cámara trasera cuando el dispositivo la identifica.
        const rearCamera = devices.find((d) => /back|rear|trasera|environment/i.test(d.label));
        const deviceId = (rearCamera ?? devices[devices.length - 1]).deviceId;

        await codeReader.decodeFromVideoDevice(deviceId, scannerVideo.value, (result, err) => {
            if (result) {
                simCode.value = result.getText();
                stopScan();
                scanSuccessFlash.value = true;
                setTimeout(() => (scanSuccessFlash.value = false), 1500);
                runSimulation();
            }
            // Los "err" intermedios (NotFoundException por cada frame sin
            // QR detectado todavía) son normales y se ignoran.
        });
    } catch (e) {
        scanning.value = false;
        scanError.value = e?.name === 'NotAllowedError'
            ? 'Permiso de cámara denegado. Habilítalo en los ajustes del navegador.'
            : 'No se pudo acceder a la cámara en este dispositivo.';
    }
}

function stopScan() {
    codeReader?.reset();
    codeReader = null;
    scanning.value = false;
}

const resultStyles = {
    valid: 'bg-emerald-50 text-emerald-700',
    expired: 'bg-amber-50 text-amber-700',
    consumed: 'bg-amber-50 text-amber-700',
    revoked: 'bg-rose-50 text-rose-700',
    not_found: 'bg-rose-50 text-rose-700',
    invalid_signature: 'bg-rose-50 text-rose-700',
};

const resultLabels = {
    invalid_signature: 'firma inválida',
};

onMounted(async () => {
    await drawQr(idCanvas.value, props.identificationPayload);
    await generate();
    startTicking();
});

onBeforeUnmount(() => {
    clearInterval(tickTimer);
    clearTimeout(refreshTimer);
    stopScan();
});
</script>

<template>
    <Head title="Identidad QR" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">Módulo 1.6</p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">Identidad QR</h2>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="space-y-6">
                        <!-- QR fijo de identificación -->
                        <section class="flex flex-col items-center rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                            <p class="mb-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                QR de identificación estudiantil
                            </p>
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <canvas ref="idCanvas" class="rounded-xl"></canvas>
                            </div>
                            <p class="mt-4 max-w-xs text-xs text-slate-500">
                                Identifica tu credencial estudiantil ante personal autorizado. Se renueva automáticamente
                                cada 24 horas.
                            </p>
                        </section>

                        <!-- QR dinámico -->
                        <section class="flex flex-col items-center rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                            <p class="mb-4 text-xs font-bold uppercase tracking-wide text-slate-400">
                                Tu código dinámico rota automáticamente cada {{ ttlSeconds }} segundos
                            </p>

                            <div class="relative rounded-2xl border border-slate-200 bg-white p-4">
                                <canvas ref="dynamicCanvas" class="rounded-xl" :class="{ 'opacity-30': loading }"></canvas>
                                <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-[#0284C7]">
                                    Generando…
                                </div>
                            </div>

                            <div class="mt-4 w-full max-w-xs">
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        class="h-full bg-[#0284C7] transition-all duration-1000 ease-linear"
                                        :style="{ width: (secondsRemaining / ttlSeconds) * 100 + '%' }"
                                    ></div>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">
                                    Se renueva en <span class="font-bold text-[#00338D]">{{ secondsRemaining }}s</span>
                                </p>
                            </div>

                            <div v-if="shortCode" class="mt-4 rounded-xl bg-[#E0F2FE] px-4 py-2">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-[#0284C7]">Código de respaldo (sin cámara)</p>
                                <p class="font-mono text-lg font-bold tracking-[0.3em] text-[#00338D]">{{ shortCode }}</p>
                            </div>

                            <button
                                @click="generate"
                                class="mt-6 rounded-lg border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:border-[#0284C7] hover:text-[#0284C7] disabled:opacity-50"
                                :disabled="loading"
                            >
                                Regenerar ahora
                            </button>

                            <p class="mt-6 max-w-xs text-xs text-slate-500">
                                Este código es de un solo uso: al ser validado por un servicio (biblioteca, evento, caja),
                                se marca como consumido y deja de ser válido, incluso antes de expirar. Si dos lectores lo
                                escanean al mismo tiempo, solo el primero lo consigue.
                            </p>
                        </section>
                    </div>

                    <!-- Simulador de validación + historial -->
                    <div class="space-y-6">
                        <!-- Escáner con cámara -->
                        <section v-if="canValidate" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="mb-1 text-sm font-bold uppercase tracking-wide text-slate-400">
                                Escanear con cámara
                            </h3>
                            <p class="mb-4 text-xs text-slate-500">
                                Lee el QR directamente dentro de la app (funciona igual en Android e iPhone; no
                                depende de que la app de cámara del sistema reconozca el contenido).
                            </p>

                            <div v-if="scanning" class="relative overflow-hidden rounded-xl bg-black">
                                <video ref="scannerVideo" class="aspect-square w-full object-cover" playsinline muted></video>
                                <div class="pointer-events-none absolute inset-6 rounded-xl border-2 border-white/70"></div>
                                <div v-if="scanSuccessFlash" class="absolute inset-0 flex items-center justify-center bg-emerald-500/70 text-sm font-bold text-white">
                                    ¡Código leído!
                                </div>
                            </div>

                            <button
                                v-if="!scanning"
                                @click="startScan"
                                class="w-full rounded-lg bg-[#00338D] py-2 text-sm font-semibold text-white transition hover:bg-[#0284C7]"
                            >
                                Activar cámara
                            </button>
                            <button
                                v-else
                                @click="stopScan"
                                class="mt-3 w-full rounded-lg border border-slate-200 py-2 text-xs font-semibold text-slate-600 hover:border-[#0284C7] hover:text-[#0284C7]"
                            >
                                Detener cámara
                            </button>

                            <p v-if="scanError" class="mt-3 text-xs text-rose-600">{{ scanError }}</p>
                        </section>

                        <section v-if="canValidate" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="mb-1 text-sm font-bold uppercase tracking-wide text-slate-400">
                                Simulador de validación externa
                            </h3>
                            <p class="mb-4 text-xs text-slate-500">
                                Así consumen otros dominios (biblioteca, eventos, cajas de asociación) el contrato
                                <code class="rounded bg-[#E0F2FE] px-1 text-[#0284C7]">/api/v1/identity/qr-validate</code>.
                                Acepta el código pelado, el código corto de respaldo, el payload completo firmado, o
                                lo que acabas de leer con la cámara arriba.
                            </p>

                            <div class="space-y-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Código escaneado</label>
                                    <div class="flex flex-wrap gap-2">
                                        <input
                                            v-model="simCode"
                                            class="w-full min-w-[10rem] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[#0284C7] focus:ring-[#0284C7]"
                                            placeholder="Pega o escanea un código"
                                        />
                                        <button
                                            @click="useCurrentCodeInSimulator"
                                            type="button"
                                            class="whitespace-nowrap rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:border-[#0284C7] hover:text-[#0284C7]"
                                        >
                                            Usar mi código
                                        </button>
                                        <button
                                            @click="useShortCodeInSimulator"
                                            type="button"
                                            class="whitespace-nowrap rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:border-[#0284C7] hover:text-[#0284C7]"
                                        >
                                            Usar código corto
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Contexto / servicio</label>
                                    <select v-model="simContext" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[#0284C7] focus:ring-[#0284C7]">
                                        <option value="biblioteca-central">Biblioteca central</option>
                                        <option value="evento-bienvenida">Evento de bienvenida</option>
                                        <option value="caja-asociacion">Caja de asociación estudiantil</option>
                                        <option value="acceso-laboratorio">Acceso a laboratorio</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-slate-600">Datos a exponer</label>
                                        <select v-model="simLevel" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-[#0284C7] focus:ring-[#0284C7]">
                                            <option value="basic">Básico (nombre enmascarado)</option>
                                            <option value="full">Completo (nombre completo)</option>
                                        </select>
                                    </div>
                                </div>
                                <button
                                    @click="runSimulation"
                                    class="w-full rounded-lg bg-[#00338D] py-2 text-sm font-semibold text-white transition hover:bg-[#0284C7] disabled:opacity-50"
                                    :disabled="simLoading || !simCode"
                                >
                                    Validar código
                                </button>
                            </div>

                            <div v-if="simResult" class="mt-4 rounded-xl border border-slate-200 p-4">
                                <div class="flex items-center justify-between">
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" :class="resultStyles[simResult.result] ?? 'bg-amber-50 text-amber-700'">
                                        {{ resultLabels[simResult.result] ?? simResult.result }}
                                    </span>
                                    <span class="text-xs text-slate-500">{{ simResult.ok ? 'Identidad resuelta' : 'Rechazado' }}</span>
                                </div>
                                <div v-if="simResult.identity" class="mt-3 text-sm">
                                    <p class="font-bold text-slate-800">{{ simResult.identity.name }}</p>
                                    <p class="text-xs text-slate-500">Matrícula {{ simResult.identity.student?.enrollment_number ?? '—' }}</p>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-slate-400">
                                Últimas validaciones
                            </h3>
                            <ul class="space-y-3">
                                <li v-for="v in validations" :key="v.id" class="flex items-center justify-between border-b border-slate-100 pb-3 text-sm last:border-0 last:pb-0">
                                    <div>
                                        <p class="font-medium text-slate-700">{{ v.context ?? 'Sin contexto' }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ v.created_at }} · {{ v.ip_address ?? 'IP no disponible' }}
                                            <span v-if="v.validator_label"> · {{ v.validator_label }}</span>
                                        </p>
                                    </div>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-bold" :class="resultStyles[v.result] ?? 'bg-amber-50 text-amber-700'">
                                        {{ resultLabels[v.result] ?? v.result }}
                                    </span>
                                </li>
                                <li v-if="validations.length === 0" class="py-4 text-center text-sm text-slate-500">
                                    Aún no hay validaciones registradas.
                                </li>
                            </ul>
                            <button
                                v-if="historyHasMore"
                                @click="loadMoreHistory"
                                class="mt-4 w-full rounded-lg border border-slate-200 py-2 text-xs font-semibold text-slate-600 transition hover:border-[#0284C7] hover:text-[#0284C7] disabled:opacity-50"
                                :disabled="historyLoading"
                            >
                                {{ historyLoading ? 'Cargando…' : 'Cargar más' }}
                            </button>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
