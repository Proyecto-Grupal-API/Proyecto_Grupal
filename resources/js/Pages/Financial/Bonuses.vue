<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
const props = defineProps({ bonuses: Object, balances: Array, filters: Object, types: Array, timezone: String });
const type = ref(props.filters.type);
const selected = ref(null);
const entries = ref([]);
const meta = ref(null);
const busy = ref(false);
const error = ref('');
let controller;
const labels = { BECA: 'Beca', CAMPANIA: 'Campaña', BENEFICIO: 'Beneficio', CATEGORIA: 'Categoría', NEGOCIO: 'Negocio', ACTIVO: 'Activo', PENDIENTE: 'Próximamente', AGOTADO: 'Agotado', CANCELADO: 'Cancelado', EXPIRADO: 'Vencido', EMISION: 'Emisión', CONSUMO: 'Consumo', CANCELACION: 'Cancelación', EXPIRACION: 'Vencimiento', DEVOLUCION: 'Devolución' };
const money = (cents, currency) => new Intl.NumberFormat('es-MX', { style: 'currency', currency }).format(cents / 100);
const date = (value) => value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short', timeZone: props.timezone || 'America/Mexico_City' }).format(new Date(value)) : 'Sin fecha';
function filter() { close(); router.get(route('financial.bonuses.index'), type.value ? { type: type.value } : {}, { preserveState: false, preserveScroll: true }); }
function close() { controller?.abort(); controller = null; selected.value = null; entries.value = []; meta.value = null; error.value = ''; busy.value = false; }
async function open(bonus) {
    close(); selected.value = bonus; busy.value = true;
    const local = new AbortController(); controller = local;
    try {
        const [detail, history] = await Promise.all([
            axios.get(route('financial.bonuses.show', bonus.id), { signal: local.signal }),
            axios.get(route('financial.bonuses.history', bonus.id), { signal: local.signal }),
        ]);
        if (controller !== local) return;
        selected.value = detail.data.data; entries.value = history.data.data; meta.value = history.data.meta;
    } catch (e) {
        if (!axios.isCancel(e) && controller === local) error.value = e.response?.status === 404 ? 'Este bono ya no está disponible para tu cuenta.' : 'No pudimos cargar el detalle. Intenta nuevamente.';
    } finally { if (controller === local) busy.value = false; }
}
async function historyPage(page) {
    if (busy.value || !selected.value) return;
    busy.value = true; error.value = '';
    const local = controller;
    try {
        const response = await axios.get(route('financial.bonuses.history', selected.value.id), { params: { page }, signal: local.signal });
        if (controller === local) { entries.value = response.data.data; meta.value = response.data.meta; }
    } catch (e) { if (!axios.isCancel(e) && controller === local) error.value = 'No pudimos cargar esta página del historial.'; }
    finally { if (controller === local) busy.value = false; }
}
onBeforeUnmount(close);
</script>

<template>
    <Head title="Mis bonos" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-xs font-bold uppercase tracking-widest text-sky-600">Finanzas · 2.5</p><h1 class="mt-1 text-2xl font-bold text-[#00338D]">Mis bonos</h1></div>
                <Link :href="route('financial.bonuses.admin.index')" class="text-sm font-semibold text-[#00338D]">Administrar bonos</Link>
                <Link :href="route('financial.dashboard')" class="text-sm font-semibold text-[#00338D]">Volver a Finanzas</Link>
            </div>
        </template>
        <main class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6">
            <div class="mx-auto max-w-7xl space-y-6">
                <section class="rounded-2xl bg-[#00338D] p-6 text-white">
                    <h2 class="text-xl font-bold">Tus beneficios dentro del campus</h2>
                    <p class="mt-2 text-sm text-blue-100">Los bonos conservan sus propias condiciones y vigencia. No forman parte del saldo de tu wallet ni se convierten en efectivo.</p>
                    <div v-if="balances.length" class="mt-5 flex flex-wrap gap-8">
                        <div v-for="balance in balances" :key="balance.currency"><p class="text-sm text-blue-100">Saldo en bonos vigentes · {{ balance.currency }}</p><p class="mt-1 text-3xl font-bold">{{ money(balance.amount_cents, balance.currency) }}</p></div>
                    </div>
                    <p v-else class="mt-5 font-semibold">Actualmente no tienes saldo en bonos vigentes.</p>
                    <p class="mt-3 text-xs text-blue-100">El uso depende de las restricciones de cada bono. El resumen incluye todos los tipos.</p>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h2 class="text-xl font-bold text-[#00338D]">Bonos registrados <span class="text-sm font-normal text-slate-500">({{ bonuses.total }})</span></h2>
                        <label class="text-sm text-slate-600">Tipo
                            <select v-model="type" @change="filter" class="ml-2 rounded-lg border-slate-300 text-sm"><option value="">Todos</option><option v-for="value in types" :key="value" :value="value">{{ labels[value] }}</option></select>
                        </label>
                    </div>
                    <div v-if="bonuses.data.length" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="bonus in bonuses.data" :key="bonus.id" class="rounded-xl border border-slate-200 p-5">
                            <div class="flex items-center justify-between gap-2"><h3 class="font-bold text-[#00338D]">{{ labels[bonus.type] }}</h3><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="bonus.status === 'ACTIVO' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ labels[bonus.status] }}</span></div>
                            <p class="mt-4 text-2xl font-bold text-[#00338D]">{{ money(bonus.remaining_amount_cents, bonus.currency) }}</p>
                            <p class="text-xs text-slate-500">Saldo registrado · {{ bonus.currency }}</p>
                            <p class="mt-3 text-sm text-slate-600">Vence: {{ date(bonus.expires_at) }}</p>
                            <button @click="open(bonus)" class="mt-4 rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D] hover:bg-blue-50">Ver condiciones e historial</button>
                        </article>
                    </div>
                    <p v-else class="mt-6 rounded-xl border border-dashed border-slate-200 p-8 text-center text-slate-500">{{ type ? 'No tienes bonos de este tipo.' : 'Todavía no tienes bonos registrados.' }}</p>
                    <nav v-if="bonuses.last_page > 1" class="mt-6 flex items-center justify-center gap-4" aria-label="Páginas de bonos">
                        <Link v-if="bonuses.prev_page_url" :href="bonuses.prev_page_url" class="text-sm font-semibold text-[#00338D]">Anterior</Link><span class="text-sm text-slate-500">{{ bonuses.current_page }} / {{ bonuses.last_page }}</span><Link v-if="bonuses.next_page_url" :href="bonuses.next_page_url" class="text-sm font-semibold text-[#00338D]">Siguiente</Link>
                    </nav>
                </section>
                <section v-if="selected" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-label="Detalle del bono" :aria-busy="busy">
                    <div class="flex items-center justify-between gap-3"><h2 class="text-xl font-bold text-[#00338D]">{{ labels[selected.type] }} · {{ labels[selected.status] }}</h2><button @click="close" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Cerrar detalle</button></div>
                    <p v-if="error" role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
                    <p v-if="busy" role="status" class="mt-4 text-sm text-slate-500">Cargando…</p>
                    <template v-if="selected.restrictions">
                        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            <div><dt class="text-slate-500">Importe original</dt><dd class="font-semibold">{{ money(selected.original_amount_cents, selected.currency) }}</dd></div>
                            <div><dt class="text-slate-500">Saldo registrado</dt><dd class="font-semibold">{{ money(selected.remaining_amount_cents, selected.currency) }}</dd></div>
                            <div><dt class="text-slate-500">Vigencia</dt><dd>{{ date(selected.valid_from) }} — {{ date(selected.expires_at) }}</dd></div>
                            <div><dt class="text-slate-500">Puede combinarse con otros bonos</dt><dd>{{ selected.combinable ? 'Sí' : 'No' }}</dd></div>
                            <div><dt class="text-slate-500">Permite uso parcial</dt><dd>{{ selected.allows_partial_use ? 'Sí' : 'No' }}</dd></div>
                            <div v-if="selected.external_reference"><dt class="text-slate-500">Referencia</dt><dd class="break-all">{{ selected.external_reference }}</dd></div>
                        </dl>
                        <p v-if="selected.status === 'EXPIRADO'" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">La vigencia terminó. Aunque conserve un saldo registrado, este bono ya no está disponible para nuevas compras.</p>
                        <h3 class="mt-6 font-bold text-[#00338D]">Restricciones</h3>
                        <ul v-if="selected.restrictions.length" class="mt-2 space-y-1 text-sm text-slate-600"><li v-for="restriction in selected.restrictions" :key="restriction.id" class="break-all">{{ labels[restriction.type] }}: {{ restriction.target_id }}</li></ul>
                        <p v-else class="mt-2 text-sm text-slate-500">Sin restricciones registradas por negocio o categoría.</p>
                        <h3 class="mt-6 font-bold text-[#00338D]">Historial del bono</h3>
                        <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b text-slate-500"><tr><th class="py-2 pr-4">Fecha</th><th class="pr-4">Movimiento</th><th class="pr-4 text-right">Importe</th><th class="text-right">Saldo posterior</th></tr></thead><tbody><tr v-for="entry in entries" :key="entry.id" class="border-b border-slate-100"><td class="py-3 pr-4 whitespace-nowrap">{{ date(entry.created_at) }}</td><td class="pr-4">{{ labels[entry.movement_type] || entry.movement_type }}<p v-if="entry.reason" class="mt-1 text-xs text-slate-500">{{ entry.reason }}</p></td><td class="pr-4 text-right whitespace-nowrap">{{ money(entry.amount_cents, selected.currency) }}</td><td class="text-right whitespace-nowrap">{{ money(entry.remaining_after_cents, selected.currency) }}</td></tr></tbody></table></div>
                        <p v-if="!entries.length && !busy" class="mt-4 text-sm text-slate-500">No hay movimientos registrados.</p>
                        <nav v-if="meta && meta.last_page > 1" class="mt-4 flex items-center justify-center gap-4" aria-label="Páginas del historial"><button :disabled="busy || meta.current_page <= 1" @click="historyPage(meta.current_page - 1)" class="text-sm font-semibold text-[#00338D] disabled:opacity-40">Anterior</button><span class="text-sm text-slate-500">{{ meta.current_page }} / {{ meta.last_page }}</span><button :disabled="busy || meta.current_page >= meta.last_page" @click="historyPage(meta.current_page + 1)" class="text-sm font-semibold text-[#00338D] disabled:opacity-40">Siguiente</button></nav>
                    </template>
                </section>
            </div>
        </main>
    </AuthenticatedLayout>
</template>
