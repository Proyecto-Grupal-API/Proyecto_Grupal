<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { formatInstitutionalMoney } from '@/Support/institutionalMoney.js';

const reference = ref('');
const review = ref(null);
const confirmed = ref(false);
const busy = ref(false);
const error = ref('');
const factorCode = ref('');
const factorConfirmed = ref(false);

function reset() {
    review.value = null;
    confirmed.value = false;
    error.value = '';
}

function failureMessage(failure) {
    return Object.values(failure.response?.data?.errors ?? {}).flat().join(' ')
        || failure.response?.data?.message
        || 'No se confirmó el resultado. Consulta la misma referencia antes de emitir de nuevo.';
}

async function confirmFactor() {
    if (busy.value) return;
    if (!/^[0-9]{6}$/.test(factorCode.value)) {
        error.value = 'Escribe los seis dígitos de tu aplicación de autenticación.';
        return;
    }

    busy.value = true;
    error.value = '';
    try {
        await axios.post(route('security.two-factor.confirm'), { code: factorCode.value });
        factorConfirmed.value = true;
        reset();
    } catch (failure) {
        factorConfirmed.value = false;
        reset();
        error.value = failureMessage(failure);
    } finally {
        factorCode.value = '';
        busy.value = false;
    }
}

async function submit(issue = false) {
    if (busy.value || !factorConfirmed.value || (issue && (!confirmed.value || !review.value?.can_issue))) return;
    const id = reference.value.toLowerCase().replace(/^beca:/, '');
    if (!/^[a-f0-9]{24}$/.test(id)) {
        error.value = 'Introduce la referencia de una solicitud aprobada en Comunidad.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const result = issue
            ? await axios.post(route('financial.bonuses.admin.beca.community.issue', id), {
                budget_version: review.value.budget_version,
                review_token: review.value.review_token,
                confirm: confirmed.value,
            })
            : await axios.get(route('financial.bonuses.admin.beca.community.review', id));
        review.value = result.data.data;
        confirmed.value = false;
    } catch (failure) {
        reset();
        if (failure.response?.status === 403) factorConfirmed.value = false;
        error.value = failureMessage(failure);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-bold text-[#00338D]">Emitir beca de Comunidad</h2>
        <p class="mt-2 text-sm text-slate-600">Usa la referencia de una solicitud aprobada para revisar el bono y el presupuesto asignado a su convocatoria.</p>
        <form v-if="!factorConfirmed" class="mt-4 space-y-3 rounded-xl bg-blue-50 p-4" @submit.prevent="confirmFactor">
            <p class="text-sm text-slate-700">Confirma tu segundo factor para revisar y emitir becas. La confirmación es válida durante cinco minutos en esta sesión.</p>
            <label class="block text-sm text-slate-600">
                Código de tu aplicación de autenticación
                <input v-model.trim="factorCode" :disabled="busy" required inputmode="numeric" autocomplete="one-time-code"
                    pattern="[0-9]{6}" minlength="6" maxlength="6"
                    class="mt-1 block w-full rounded-lg border-slate-300 sm:w-56" />
            </label>
            <button :disabled="busy" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Confirmar segundo factor</button>
        </form>
        <p v-else role="status" class="mt-3 text-sm text-emerald-800">Segundo factor confirmado. Se comprobarán tus permisos al consultar y emitir la beca.</p>
        <form v-if="factorConfirmed" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submit()">
            <label class="text-sm text-slate-600">
                Referencia de la solicitud
                <input v-model.trim="reference" :disabled="busy" required maxlength="29" placeholder="beca:…" @input="reset"
                    class="mt-1 block rounded-lg border-slate-300" />
            </label>
            <button :disabled="busy" class="rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D] disabled:opacity-50">Consultar beca</button>
        </form>
        <p v-if="error" role="alert" class="mt-3 text-sm text-red-700">{{ error }}</p>
        <p v-if="review?.receipt" role="status" class="mt-3 break-all text-sm text-emerald-800">Bono emitido: {{ review.receipt.bonus_id }}</p>
        <form v-else-if="review?.award" class="mt-4 space-y-3 rounded-xl bg-blue-50 p-4 text-sm" @submit.prevent="submit(true)">
            <dl class="grid gap-2 sm:grid-cols-2">
                <div><dt class="font-semibold">Cuenta beneficiaria</dt><dd class="break-all">{{ review.award.beneficiario_id }}</dd></div>
                <div><dt class="font-semibold">Importe del bono</dt><dd>{{ formatInstitutionalMoney(String(review.award.monto_centavos), 'MXN') }}</dd></div>
                <div><dt class="font-semibold">Presupuesto</dt><dd>{{ review.budget }} · {{ formatInstitutionalMoney(review.available_cents, 'MXN') }} disponibles</dd></div>
                <div><dt class="font-semibold">Negocio autorizado</dt><dd class="break-all">{{ review.business_id }}</dd></div>
                <div><dt class="font-semibold">Inicio de vigencia</dt><dd>{{ new Date(review.award.vigencia_inicio).toLocaleString('es-MX') }}</dd></div>
                <div><dt class="font-semibold">Vencimiento</dt><dd>{{ new Date(review.award.vigencia_fin_exclusiva).toLocaleString('es-MX') }}</dd></div>
            </dl>
            <p v-if="!review.can_issue" class="text-amber-900">El presupuesto disponible es insuficiente.</p>
            <label v-else class="flex items-start gap-2"><input v-model="confirmed" type="checkbox" :disabled="busy" /> Confirmo la emisión con estos datos.</label>
            <button :disabled="busy || !confirmed || !review.can_issue" class="rounded-lg bg-[#00338D] px-4 py-2 font-semibold text-white disabled:opacity-50">Emitir bono</button>
        </form>
    </section>
</template>
