<script setup>
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
defineProps({ stats: { type: Object, default: () => ({}) } })
</script>

<template>
  <Equipo4Layout>
    <section class="space-y-6">
      <!-- Hero azul oscuro -->
      <div class="rounded-2xl bg-[#00338D] px-7 py-8 text-white shadow-xl shadow-[#00338D]/10 sm:px-10 sm:py-10">
        <p class="text-sm text-blue-100">Bienvenido de nuevo</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Tu inventario, en un solo lugar.</h1>
        <p class="mt-3 max-w-2xl text-sm leading-7 text-blue-100">
          Panel de control del Equipo 4 · Inventarios, abastecimiento y proveedores para los negocios de Campus Digital.
        </p>
        <span class="mt-5 inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-[#10B981]/20 text-[#10B981]">
          Sistema protegido
        </span>
      </div>

      <!-- KPIs principales -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Productos activos</span>
          <strong class="mt-2 block text-2xl text-[#00338D]">{{ stats.products_active ?? 0 }}</strong>
          <span class="text-xs text-slate-400">de {{ stats.products ?? 0 }} totales</span>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Unidades en stock</span>
          <strong class="mt-2 block text-2xl text-[#00338D]">{{ stats.inventory_units ?? 0 }}</strong>
          <span class="text-xs text-slate-400">{{ stats.inventory_available ?? 0 }} disponibles</span>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Reservas activas</span>
          <strong class="mt-2 block text-2xl text-[#00338D]">{{ stats.reservations_active ?? 0 }}</strong>
          <span class="text-xs text-slate-400">{{ stats.inventory_reserved ?? 0 }} unidades apartadas</span>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Alertas activas</span>
          <strong class="mt-2 block text-2xl" :class="(stats.alerts_active ?? 0) > 0 ? 'text-rose-600' : 'text-[#00338D]'">
            {{ stats.alerts_active ?? 0 }}
          </strong>
          <span class="text-xs text-slate-400">
            <span v-if="(stats.alerts_critical ?? 0) > 0" class="text-rose-500 font-semibold">{{ stats.alerts_critical }} urgentes</span>
            <span v-else>sin urgencias</span>
          </span>
        </div>
      </div>

      <!-- KPIs secundarios -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">OCs pendientes</span>
          <strong class="mt-2 block text-xl text-[#00338D]">{{ stats.purchase_orders_pending ?? 0 }}</strong>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Proveedores activos</span>
          <strong class="mt-2 block text-xl text-[#00338D]">{{ stats.suppliers_active ?? 0 }}</strong>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Movimientos (7 días)</span>
          <strong class="mt-2 block text-xl text-[#00338D]">{{ stats.movements_week ?? 0 }}</strong>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
          <span class="text-sm text-slate-500">Devoluciones del mes</span>
          <strong class="mt-2 block text-xl text-[#00338D]">{{ stats.returns_month ?? 0 }}</strong>
        </div>
      </div>

      <!-- Tarjetas de acceso rápido (estilo Eq. 1) -->
      <div class="grid gap-5 md:grid-cols-2">
        <a href="/equipo4/productos" class="group rounded-2xl bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:ring-2 hover:ring-[#0284C7]">
          <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#0284C7]">Catálogo</span>
          <h2 class="mt-3 text-xl font-bold text-[#00338D]">Productos</h2>
          <p class="mt-2 text-sm leading-6 text-slate-500">Administra el catálogo, categorías y variantes.</p>
          <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#0284C7]">Gestionar →</span>
        </a>
        <a href="/equipo4/inventario" class="group rounded-2xl bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:ring-2 hover:ring-[#10B981]">
          <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#10B981]">Stock</span>
          <h2 class="mt-3 text-xl font-bold text-[#00338D]">Inventario</h2>
          <p class="mt-2 text-sm leading-6 text-slate-500">Consulta existencias y aplica ajustes manuales.</p>
          <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#10B981]">Ver stock →</span>
        </a>
        <a href="/equipo4/compras" class="group rounded-2xl bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:ring-2 hover:ring-[#00338D]">
          <span class="text-xs font-bold uppercase tracking-[0.18em] text-[#00338D]">Compras</span>
          <h2 class="mt-3 text-xl font-bold text-[#00338D]">Órdenes de Compra</h2>
          <p class="mt-2 text-sm leading-6 text-slate-500">Autoriza, recibe y da seguimiento a tus OCs.</p>
          <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-[#0284C7]">Gestionar →</span>
        </a>
        <a href="/equipo4/alertas" class="group rounded-2xl bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:ring-2 hover:ring-rose-400">
          <span class="text-xs font-bold uppercase tracking-[0.18em] text-rose-500">Operación</span>
          <h2 class="mt-3 text-xl font-bold text-[#00338D]">Alertas</h2>
          <p class="mt-2 text-sm leading-6 text-slate-500">Revisa productos con stock bajo y reglas de reorden.</p>
          <span class="mt-6 inline-block text-sm font-bold text-[#00338D] group-hover:text-rose-500">Ver alertas →</span>
        </a>
      </div>

      <!-- Infraestructura -->
      <div class="rounded-2xl bg-white p-6 shadow-sm">
        <h3 class="text-base font-bold text-[#00338D] mb-3">Infraestructura</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <span class="text-slate-500 block">Almacenes</span>
            <strong class="text-slate-800 text-lg">{{ stats.warehouses ?? 0 }}</strong>
          </div>
          <div>
            <span class="text-slate-500 block">Ubicaciones</span>
            <strong class="text-slate-800 text-lg">{{ stats.locations ?? 0 }}</strong>
          </div>
        </div>
      </div>
    </section>
  </Equipo4Layout>
</template>
