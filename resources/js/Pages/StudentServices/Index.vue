<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    student: { type: Object, required: true },
    consents: { type: Array, required: true },
    preferences: { type: Object, required: true },
});

const preferences = ref({ ...props.preferences });
const saved = ref(false);
const saving = ref(false);
const loading = ref(false);
const error = ref('');
const consents = ref([...props.consents]);

async function request(url, method, payload = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: payload ? JSON.stringify(payload) : null,
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'No fue posible guardar los cambios.');
    return body.data;
}

async function savePreferences() {
    saving.value = true;
    saved.value = false;
    error.value = '';
    try {
        const data = await request(route('student-services.preferences.update'), 'PATCH', preferences.value);
        preferences.value = data.preferences;
        saved.value = true;
    } catch (exception) {
        error.value = exception.message;
        router.reload({ only: ['preferences'] });
    } finally {
        saving.value = false;
    }
}

async function changeConsent(consent) {
    saving.value = true;
    error.value = '';
    try {
        const accepted = consent.status !== 'accepted';
        const data = accepted
            ? await request(route('student-services.consents.accept'), 'POST', { consent_id: consent.id, consent_version: consent.version })
            : await request(route('student-services.consents.revoke', { consentId: consent.id }), 'DELETE', { consent_record_id: consent.acceptance_id });
        consents.value = consents.value.map((item) => item.id === data.id ? data : item);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        saving.value = false;
    }
}

function refreshData() {
    loading.value = true;
    error.value = '';
    router.reload({
        only: ['student', 'consents', 'preferences'],
        onError: () => { error.value = 'No fue posible actualizar los datos.'; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <Head title="Mi condición y privacidad" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#0284C7]">Identidad estudiantil</p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-[#00338D]">Mi condición y privacidad</h2>
                </div>
                <button class="text-sm font-semibold text-[#00338D] transition hover:text-[#0284C7]" type="button" @click="refreshData">
                    Actualizar datos
                </button>
            </div>
        </template>

        <div class="min-h-[calc(100vh-9rem)] bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl space-y-6">
                <section class="overflow-hidden rounded-2xl bg-[#00338D] px-6 py-7 text-white shadow-xl shadow-[#00338D]/10 sm:px-8">
                    <div class="flex flex-col justify-between gap-6 md:flex-row md:items-center">
                        <div>
                            <p class="text-sm text-blue-100">Estado académico actual</p>
                            <div class="mt-3 flex items-center gap-3">
                                <span class="h-3 w-3 rounded-full bg-[#10B981] ring-4 ring-[#10B981]/20"></span>
                                <h3 class="text-3xl font-bold">{{ student.status_label }}</h3>
                            </div>
                            <p class="mt-3 max-w-xl text-sm leading-6 text-blue-100">{{ student.status_reason || 'Sin detalle adicional registrado.' }}</p>
                        </div>
                        <div class="rounded-xl border border-white/20 bg-white/10 px-5 py-4 backdrop-blur-sm">
                            <p class="text-xs uppercase tracking-[0.18em] text-blue-100">Matrícula</p>
                            <p class="mt-1 text-lg font-semibold">{{ student.enrollment }}</p>
                            <p class="mt-2 text-xs text-blue-100">Vigente desde {{ student.effective_from ? new Date(student.effective_from).toLocaleDateString() : 'sin fecha registrada' }}</p>
                        </div>
                    </div>
                </section>

                <div class="grid gap-6 lg:grid-cols-[1.05fr_1fr]">
                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#64748B]">Módulo 1.8</p>
                                <h3 class="mt-2 text-xl font-bold text-[#00338D]">Perfil académico</h3>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">Estado registrado</span>
                        </div>
                        <dl class="mt-7 grid gap-5 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Estudiante</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ student.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Identificador</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ student.student_id }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Programa</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ student.program }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Semestre</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ student.semester }}º semestre</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Campus</dt>
                                <dd class="mt-1 font-semibold text-slate-800">{{ student.campus }}</dd>
                            </div>
                        </dl>
                        <div class="mt-7 border-t border-slate-100 pt-5 text-sm text-slate-500">
                            La condición puede cambiar cuando la institución actualice tu información académica.
                        </div>
                        <div v-if="student.history?.length" class="mt-5 border-t border-slate-100 pt-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Historial de condición</p>
                            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                                <li v-for="item in student.history" :key="`${item.status}-${item.recorded_at}`">{{ item.status }} · {{ item.reason || 'Sin motivo registrado' }}</li>
                            </ul>
                        </div>
                        <p v-else class="mt-5 border-t border-slate-100 pt-5 text-sm text-slate-500">No hay historial académico registrado.</p>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#64748B]">Módulo 1.9</p>
                            <h3 class="mt-2 text-xl font-bold text-[#00338D]">Consentimientos</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">Controla qué usos de información has aceptado.</p>
                        </div>
                        <div class="mt-6 space-y-3">
                            <article v-for="consent in consents" :key="consent.id" class="flex items-start justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/70 p-4">
                                <div>
                                    <h4 class="font-semibold text-slate-800">{{ consent.name }}</h4>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ consent.description }}</p>
                                    <p class="mt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Versión {{ consent.version }}</p>
                                </div>
                                <button :disabled="saving" :class="consent.status === 'accepted' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'" class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold" type="button" @click="changeConsent(consent)">
                                    {{ consent.status === 'accepted' ? 'Revocar' : 'Aceptar' }}
                                </button>
                            </article>
                        </div>
                        <p v-if="!consents.length" class="mt-6 text-sm text-slate-500">No hay consentimientos configurados.</p>
                    </section>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#64748B]">Preferencias de comunicación</p>
                            <h3 class="mt-2 text-xl font-bold text-[#00338D]">Cómo quieres recibir novedades</h3>
                        </div>
                        <p v-if="saved" class="text-sm font-semibold text-[#10B981]">Preferencias actualizadas</p>
                    </div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-3">
                        <label v-for="(enabled, channel) in preferences" :key="channel" class="flex cursor-pointer items-center justify-between rounded-xl border border-slate-200 px-4 py-4 transition hover:border-[#0284C7]">
                            <span class="font-semibold capitalize text-slate-700">{{ channel }}</span>
                            <input v-model="preferences[channel]" :disabled="saving" class="h-5 w-5 rounded border-slate-300 text-[#0284C7] focus:ring-[#0284C7]" type="checkbox" @change="savePreferences" />
                        </label>
                    </div>
                </section>
                <p v-if="error" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>
                <p v-if="loading" class="text-center text-sm text-slate-500">Actualizando datos…</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
