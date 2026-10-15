<script setup>
import Equipo4Layout from '../../Layouts/Equipo4Layout.vue'
import { usePermissions } from '@/Composables/usePermissions'

defineProps({ stats: { type: Object, default: () => ({}) } })

const { canSeeKpi, role, isAdmin } = usePermissions()
</script>

<template>
  <Equipo4Layout>
    <section class="space-y-6">
      <!-- Tarjeta de bienvenida -->
      <div class="rounded-2xl bg-white border border-slate-200/70 px-6 py-6 sm:px-8 flex flex-col sm:flex-row sm:items-center gap-5">
        <span class="grid place-items-center w-[72px] h-[72px] rounded-full bg-[#1B3A8F] text-white text-2xl font-bold shrink-0">
          E4
        </span>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl sm:text-3xl font-semibold text-slate-800 tracking-tight">Tu inventario, en un solo lugar.</h1>
            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold uppercase bg-emerald-50 text-emerald-700">
              Sistema protegido
            </span>
          </div>
          <p class="mt-1.5 text-sm text-slate-500">Bienvenido de nuevo</p>
          <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
            Panel de control del Equipo 4 · Inventarios, abastecimiento y proveedores para los negocios de Campus Digital.
          </p>
        </div>
      </div>

      <!-- KPIs principales -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div v-if="canSeeKpi('products_active')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Productos activos</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.products_active ?? 0 }}</strong>
          <span class="text-xs text-slate-400">de {{ stats.products ?? 0 }} totales</span>
        </div>
        <div v-if="canSeeKpi('inventory_units')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Unidades en stock</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.inventory_units ?? 0 }}</strong>
          <span class="text-xs text-slate-400">{{ stats.inventory_available ?? 0 }} disponibles</span>
        </div>
        <div v-if="canSeeKpi('reservations_active')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Reservas activas</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.reservations_active ?? 0 }}</strong>
          <span class="text-xs text-slate-400">{{ stats.inventory_reserved ?? 0 }} unidades apartadas</span>
        </div>
        <div v-if="canSeeKpi('alerts_active')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Alertas activas</span>
          <strong class="mt-2 block text-3xl font-semibold" :class="(stats.alerts_active ?? 0) > 0 ? 'text-rose-600' : 'text-slate-800'">
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
        <div v-if="canSeeKpi('purchase_orders_pending')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">OCs pendientes</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.purchase_orders_pending ?? 0 }}</strong>
        </div>
        <div v-if="canSeeKpi('suppliers_active')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Proveedores activos</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.suppliers_active ?? 0 }}</strong>
        </div>
        <div v-if="canSeeKpi('movements_week')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Movimientos (7 días)</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.movements_week ?? 0 }}</strong>
        </div>
        <div v-if="canSeeKpi('returns_month')" class="rounded-2xl bg-white border border-slate-200/70 p-5">
          <span class="text-sm text-slate-500">Devoluciones del mes</span>
          <strong class="mt-2 block text-3xl font-semibold text-slate-800">{{ stats.returns_month ?? 0 }}</strong>
        </div>
      </div>

      <!-- Infraestructura -->
      <div v-if="canSeeKpi('warehouses') || canSeeKpi('locations')" class="rounded-2xl bg-white border border-slate-200/70 p-6">
        <h3 class="text-lg text-slate-800 mb-3">Infraestructura</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div v-if="canSeeKpi('warehouses')">
            <span class="text-slate-500 block">Almacenes</span>
            <strong class="text-slate-800 text-lg">{{ stats.warehouses ?? 0 }}</strong>
          </div>
          <div v-if="canSeeKpi('locations')">
            <span class="text-slate-500 block">Ubicaciones</span>
            <strong class="text-slate-800 text-lg">{{ stats.locations ?? 0 }}</strong>
          </div>
        </div>
      </div>
    </section>
  </Equipo4Layout>
</template>
