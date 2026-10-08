<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import CampusLayout from '../../Layouts/CampusLayout.vue';
import { Search, ShoppingBag, Store, ClipboardList, ArrowRight, Plus, CheckCircle2, SlidersHorizontal } from 'lucide-vue-next';

const props = defineProps({
  businesses: Array,
  products: Array,
  stats: Object,
});

const search = ref('');
const category = ref('Todos');
const cartForm = useForm({ product_id: '', quantity: 1 });
const checkoutForm = useForm({ payment_method: 'wallet', delivery_point: 'Biblioteca Central', notes: '' });
const showCart = ref(false);

const categories = computed(() => ['Todos', ...new Set(props.products.map(p => p.category).filter(Boolean))]);
const filteredProducts = computed(() => props.products.filter(p => {
  const matchesText = `${p.name} ${p.description ?? ''} ${p.category ?? ''}`.toLowerCase().includes(search.value.toLowerCase());
  const matchesCategory = category.value === 'Todos' || p.category === category.value;
  return matchesText && matchesCategory;
}));

const cartItems = computed(() => props.cart?.items || []);
const cartTotal = computed(() => Number(props.cart?.subtotal || 0));
const checkout = () => checkoutForm.post('/tienda/checkout', { preserveScroll: true, onSuccess: () => { showCart.value = false; } });

const addToCart = (product) => {
  cartForm.product_id = product.id;
  cartForm.quantity = 1;
  cartForm.post('/tienda/carrito', { preserveScroll: true, onSuccess: () => window.location.reload() });
};
</script>

<template>
  <CampusLayout title="Tienda">
    <div class="max-w-[1180px] mx-auto">
      <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 mb-5">
        <div>
          <div class="text-xs font-semibold text-campus-blue mb-1">EQUIPO 3 · COMERCIO Y NEGOCIOS VIRTUALES</div>
          <h1 class="text-2xl md:text-3xl font-bold text-slate-800">Marketplace Campus</h1>
          <p class="text-sm text-slate-500 mt-1">Descubre negocios, productos y servicios autorizados dentro del ecosistema.</p>
        </div>
        <div class="flex gap-2">
          <button @click="showCart=true" class="rounded-lg border border-campus-border bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 flex items-center gap-2"><ShoppingBag :size="16"/> Carrito <span v-if="cartItems.length" class="rounded-full bg-campus-blue text-white px-1.5 text-[10px]">{{ cartItems.length }}</span></button>
          <Link href="/tienda/negocios" class="rounded-lg border border-campus-border bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 flex items-center gap-2"><Store :size="16"/> Negocios</Link>
          <Link href="/tienda/pedidos" class="rounded-lg bg-campus-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 flex items-center gap-2"><ClipboardList :size="16"/> Mis pedidos</Link>
        </div>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl border border-campus-border p-4 shadow-card"><div class="text-xs text-slate-400">Negocios autorizados</div><div class="text-2xl font-bold text-campus-navy mt-1">{{ stats.businesses }}</div></div>
        <div class="bg-white rounded-xl border border-campus-border p-4 shadow-card"><div class="text-xs text-slate-400">Productos activos</div><div class="text-2xl font-bold text-campus-navy mt-1">{{ stats.products }}</div></div>
        <div class="bg-white rounded-xl border border-campus-border p-4 shadow-card"><div class="text-xs text-slate-400">Pedidos</div><div class="text-2xl font-bold text-campus-navy mt-1">{{ stats.orders }}</div></div>
        <div class="bg-white rounded-xl border border-campus-border p-4 shadow-card"><div class="text-xs text-slate-400">Ventas confirmadas</div><div class="text-2xl font-bold text-campus-navy mt-1">${{ Number(stats.sales).toLocaleString('es-MX',{minimumFractionDigits:2}) }}</div></div>
      </div>

      <div class="bg-white rounded-xl border border-campus-border shadow-card p-4 mb-5">
        <div class="flex flex-col lg:flex-row gap-3">
          <div class="relative flex-1">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" :size="17"/>
            <input v-model="search" placeholder="Buscar productos, servicios o categorías..." class="w-full rounded-lg bg-slate-50 border border-slate-100 pl-10 pr-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-100"/>
          </div>
          <div class="flex items-center gap-2 overflow-auto">
            <SlidersHorizontal :size="16" class="text-slate-400"/>
            <button v-for="c in categories" :key="c" @click="category=c" :class="['px-3 py-2 rounded-full text-xs font-semibold whitespace-nowrap', category===c ? 'bg-campus-blue text-white' : 'bg-slate-50 text-slate-600']">{{ c }}</button>
          </div>
        </div>
      </div>

      <section class="mb-7">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-bold text-slate-800">Negocios destacados</h2>
          <Link href="/tienda/negocios" class="text-xs font-semibold text-campus-blue flex items-center gap-1">Ver todos <ArrowRight :size="14"/></Link>
        </div>
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
          <div v-for="business in businesses" :key="business.id" class="bg-white border border-campus-border rounded-xl overflow-hidden shadow-card hover:-translate-y-0.5 transition">
            <div class="h-24 bg-gradient-to-r from-campus-navy to-campus-blue relative">
              <img v-if="business.banner" :src="business.banner" class="w-full h-full object-cover opacity-80"/>
              <div class="absolute bottom-3 left-3 h-12 w-12 rounded-xl bg-white flex items-center justify-center text-campus-blue font-bold shadow">CD</div>
            </div>
            <div class="p-4 pt-7">
              <div class="flex items-center justify-between">
                <h3 class="font-bold text-slate-800">{{ business.name }}</h3>
                <span v-if="business.verified" class="text-[10px] px-2 py-1 rounded-full bg-green-50 text-green-700">Verificado</span>
              </div>
              <p class="text-xs text-slate-500 mt-1 line-clamp-2">{{ business.description }}</p>
              <div class="mt-3 flex flex-wrap gap-1.5">
                <span v-for="tag in (business.tags || []).slice(0,3)" :key="tag" class="text-[10px] rounded-full bg-slate-50 text-slate-500 px-2 py-1">{{ tag }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section>
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-bold text-slate-800">Catálogo</h2>
          <Link href="/tienda/productos" class="text-xs font-semibold text-campus-blue flex items-center gap-1">Explorar catálogo <ArrowRight :size="14"/></Link>
        </div>
        <div v-if="filteredProducts.length" class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <article v-for="product in filteredProducts" :key="product.id" class="bg-white border border-campus-border rounded-xl overflow-hidden shadow-card">
            <div class="h-40 bg-slate-100 relative flex items-center justify-center">
              <img v-if="product.image" :src="product.image" class="w-full h-full object-cover"/>
              <ShoppingBag v-else :size="42" class="text-slate-300"/>
              <span class="absolute top-2 left-2 text-[10px] px-2 py-1 rounded-full bg-white/90 text-slate-600">{{ product.category }}</span>
            </div>
            <div class="p-4">
              <h3 class="font-bold text-sm text-slate-800">{{ product.name }}</h3>
              <p class="text-xs text-slate-500 mt-1 h-8 overflow-hidden">{{ product.description }}</p>
              <div class="flex items-center justify-between mt-4">
                <div class="text-lg font-bold text-campus-navy">${{ Number(product.price).toLocaleString('es-MX',{minimumFractionDigits:2}) }}</div>
                <button @click="addToCart(product)" class="rounded-lg bg-campus-blue text-white px-3 py-2 text-xs font-bold flex items-center gap-1 hover:bg-blue-700"><Plus :size="14"/> Agregar</button>
              </div>
            </div>
          </article>
        </div>
        <div v-else class="bg-white rounded-xl border border-dashed border-slate-200 p-12 text-center text-sm text-slate-400">No se encontraron productos.</div>
      </section>

      <div v-if="showCart" class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-campus-navy/40" @click="showCart=false"></div>
        <aside class="absolute right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl p-5 overflow-y-auto">
          <div class="flex items-center justify-between border-b border-slate-100 pb-4">
            <div><h2 class="font-bold text-lg text-slate-800">Carrito</h2><p class="text-xs text-slate-400">{{ cartItems.length }} línea(s)</p></div>
            <button @click="showCart=false" class="text-slate-400">✕</button>
          </div>
          <div v-if="cartItems.length" class="py-4 space-y-3">
            <div v-for="item in cartItems" :key="item.product_id" class="rounded-lg bg-slate-50 p-3">
              <div class="font-semibold text-sm">{{ item.name }}</div>
              <div class="flex justify-between mt-1 text-xs text-slate-500"><span>{{ item.quantity }} × ${{ Number(item.unit_price).toFixed(2) }}</span><b>${{ (item.quantity*item.unit_price).toFixed(2) }}</b></div>
            </div>
            <div class="border-t border-slate-100 pt-4 flex justify-between font-bold text-campus-navy"><span>Total</span><span>${{ cartTotal.toFixed(2) }} MXN</span></div>
            <div class="mt-4">
              <label class="text-xs font-semibold text-slate-600">Método de pago</label>
              <select v-model="checkoutForm.payment_method" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm">
                <option value="wallet">Wallet Campus Digital</option>
                <option value="bonus">Bono compatible</option>
                <option value="points">Puntos</option>
                <option value="mixed">Pago mixto</option>
                <option value="spei">SPEI directo</option>
                <option value="openpay">Openpay</option>
              </select>
            </div>
            <div class="mt-3">
              <label class="text-xs font-semibold text-slate-600">Punto de entrega</label>
              <input v-model="checkoutForm.delivery_point" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm"/>
            </div>
            <button @click="checkout" :disabled="checkoutForm.processing" class="mt-4 w-full rounded-lg bg-campus-blue text-white py-3 text-sm font-bold disabled:opacity-50">
              {{ checkoutForm.processing ? 'Procesando…' : 'Confirmar checkout' }}
            </button>
            <p class="text-[10px] text-slate-400 mt-2">Los pagos Wallet/bono/puntos usan adaptadores demo del Equipo 2/7. SPEI y Openpay quedan en estado de validación hasta integrar sus APIs.</p>
          </div>
          <div v-else class="py-16 text-center text-sm text-slate-400">Tu carrito está vacío.</div>
        </aside>
      </div>

      <div class="mt-6 bg-campus-navy text-white rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div><div class="font-bold">Panel administrativo habilitado</div><div class="text-xs text-blue-100 mt-1">El usuario admin puede gestionar negocios, catálogo, pedidos, pagos directos y postventa.</div></div>
        <div class="flex items-center gap-2 text-xs font-semibold"><CheckCircle2 :size="17" class="text-green-300"/> Acceso completo</div>
      </div>
    </div>
  </CampusLayout>
</template>
