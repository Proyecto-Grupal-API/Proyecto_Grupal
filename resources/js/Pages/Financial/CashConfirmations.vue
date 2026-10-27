<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ confirmations: Object, selected: Object });
const form = useForm({});
const money = item => new Intl.NumberFormat('es-MX', { style: 'currency', currency: item.currency }).format(item.amount_cents / 100);
const date = value => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value)) : '—';
const status = value => ({ PENDING: 'Por confirmar', CONFIRMED: 'Confirmada: esperando al cajero', REJECTED: 'Rechazada', CANCELLED: 'Cancelada por el cajero', EXPIRED: 'Vencida', CONSUMED: 'Operación finalizada' }[value] || value);
function decide(action) {
    if (form.processing) return;
    if (action === 'approve' && !window.confirm(`¿Confirmas ${props.selected.operation === 'TOPUP' ? 'recargar' : 'retirar'} ${money(props.selected)} en ${props.selected.register_name}?`)) return;
    form.post(route('financial.cash.confirmations.student.decide', { confirmationId: props.selected.id, action }), { preserveScroll: true });
}
</script>
<template>
    <Head title="Confirmaciones de caja" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-xl font-bold text-[#00338D]">Confirmaciones de caja</h1></template>
        <div class="mx-auto max-w-4xl space-y-5 px-4 py-8">
            <Link :href="route('financial.dashboard')" class="text-sm text-[#00338D]">Volver a Finanzas</Link>
            <p class="text-sm text-slate-600">Revisa el importe y la caja antes de confirmar. Tu confirmación permite al cajero finalizar esta operación; por sí sola no mueve dinero.</p>
            <p v-if="form.errors.confirmation" role="alert" class="rounded-xl bg-red-50 p-4 text-red-700">{{ form.errors.confirmation }}</p>
            <section v-if="selected" class="space-y-4 rounded-2xl border bg-white p-6">
                <h2 class="text-lg font-bold text-[#00338D]">{{ selected.operation === 'TOPUP' ? 'Recarga en efectivo' : 'Retiro en efectivo' }}</h2>
                <p class="text-3xl font-bold text-[#00338D]">{{ money(selected) }}</p>
                <p><strong>Caja:</strong> {{ selected.register_name }}</p>
                <p><strong>Motivo:</strong> {{ selected.reason }}</p>
                <p><strong>Estado:</strong> {{ status(selected.status) }}</p>
                <p class="text-sm text-slate-600">Vigencia: {{ date(selected.expires_at) }}</p>
                <p class="text-sm text-slate-600">{{ selected.operation === 'TOPUP' ? 'El cajero debe recibir el efectivo antes de acreditar tu wallet.' : 'Al finalizar, se descontará este importe de tu wallet y el cajero deberá entregarte el efectivo.' }}</p>
                <div class="flex flex-wrap gap-3">
                    <button v-if="selected.status === 'PENDING'" :disabled="form.processing" @click="decide('approve')" class="rounded-xl bg-[#00338D] px-5 py-3 font-semibold text-white disabled:opacity-50">Confirmar operación</button>
                    <button v-if="['PENDING', 'CONFIRMED'].includes(selected.status)" :disabled="form.processing" @click="decide('reject')" class="rounded-xl border px-5 py-3 disabled:opacity-50">Rechazar</button>
                    <Link :href="route('financial.cash.confirmations.student.show', { confirmationId: selected.id })" class="rounded-xl border px-5 py-3">Actualizar estado</Link>
                </div>
                <Link :href="route('financial.cash.confirmations.student.index')" class="inline-block text-sm text-[#00338D]">Ver mis confirmaciones</Link>
            </section>
            <section v-else class="space-y-4 rounded-2xl border bg-white p-6">
                <h2 class="font-bold text-[#00338D]">Mis operaciones de caja</h2>
                <p v-if="!confirmations?.data?.length" class="text-slate-500">No hay confirmaciones registradas.</p>
                <Link v-for="item in confirmations?.data || []" :key="item.id" :href="route('financial.cash.confirmations.student.show', { confirmationId: item.id })" class="block rounded-xl border p-4">
                    <strong>{{ item.operation === 'TOPUP' ? 'Recarga' : 'Retiro' }} · {{ money(item) }}</strong>
                    <p class="text-sm text-slate-600">{{ item.register_name }} · {{ status(item.status) }} · {{ date(item.expires_at) }}</p>
                </Link>
                <div class="flex gap-4"><Link v-if="confirmations?.prev_page_url" :href="confirmations.prev_page_url" class="text-[#00338D]">Anterior</Link><Link v-if="confirmations?.next_page_url" :href="confirmations.next_page_url" class="text-[#00338D]">Siguiente</Link></div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
