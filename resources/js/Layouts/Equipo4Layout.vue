<script setup>
import { usePage, router, Link, Head } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { usePermissions } from '@/Composables/usePermissions'

const page = usePage()
const { can, hasAnyPermission } = usePermissions()

// Nav groups con permiso requerido (al menos 1)
const navGroups = computed(() => {
  const all = [
    {
      label: 'Dashboard',
      href: '/equipo4',
      match: (url) => url === '/equipo4',
      requires: ['dashboard.view'],
    },
    {
      label: 'Catálogo',
      href: '/equipo4/productos',
      match: (url) => url.startsWith('/equipo4/productos') || url.startsWith('/equipo4/souvenirs') || url.startsWith('/equipo4/costos') || url.startsWith('/equipo4/categorias'),
      requires: ['productos.view', 'souvenirs.view', 'costos.view'],
    },
    {
      label: 'Stock',
      href: '/equipo4/inventario',
      match: (url) => url.startsWith('/equipo4/inventario') || url.startsWith('/equipo4/almacenes') || url.startsWith('/equipo4/kardex') || url.startsWith('/equipo4/reservas'),
      requires: ['inventario.view', 'almacenes.view', 'kardex.view', 'reservas.view'],
    },
    {
      label: 'Compras',
      href: '/equipo4/proveedores',
      match: (url) => url.startsWith('/equipo4/proveedores') || url.startsWith('/equipo4/compras') || url.startsWith('/equipo4/recepciones'),
      requires: ['proveedores.view', 'compras.view', 'recepciones.view'],
    },
    {
      label: 'Operación',
      href: '/equipo4/devoluciones',
      match: (url) => url.startsWith('/equipo4/devoluciones') || url.startsWith('/equipo4/alertas') || url.startsWith('/equipo4/conteos'),
      requires: ['devoluciones.view', 'alertas.view', 'conteos.view'],
    },
  ]

  return all.filter(g => hasAnyPermission(g.requires))
})

function isActive(group) { return group.match(page.url) }

const alertsCount = computed(() => page.props.alertsCount || 0)

const currentUser = computed(() => {
  const u = page.props.inventoryContext?.user
  return {
    name: u?.name ?? 'Usuario',
    email: u?.email ?? '',
    initials: u?.initials ?? 'U',
    role: u?.role ?? '',
  }
})

const roleLabels = {
  admin: 'Propietario del negocio',
  business_manager: 'Gerente del negocio',
  inventory_manager: 'Responsable de inventario',
  buyer: 'Comprador',
  auditor: 'Auditor',
}

const currentRoleLabel = computed(() => roleLabels[currentUser.value.role] || currentUser.value.role || '')

const showingUserMenu = ref(false)
const userMenuRef = ref(null)

function toggleUserMenu() { showingUserMenu.value = !showingUserMenu.value }
function closeUserMenu(e) { if (userMenuRef.value && !userMenuRef.value.contains(e.target)) showingUserMenu.value = false }
function closeOnEscape(e) { if (e.key === 'Escape') showingUserMenu.value = false }

onMounted(() => {
  document.addEventListener('click', closeUserMenu)
  document.addEventListener('keydown', closeOnEscape)
})

onUnmounted(() => {
  document.removeEventListener('click', closeUserMenu)
  document.removeEventListener('keydown', closeOnEscape)
})

const activeGroup = computed(() => navGroups.value.find(g => isActive(g))?.label ?? null)

// Subtabs por grupo con permiso requerido
const allGroupModules = {
  'Catálogo': [
    { label: 'Productos', href: '/equipo4/productos', requires: 'productos.view' },
    { label: 'Souvenirs', href: '/equipo4/souvenirs', requires: 'souvenirs.view' },
    { label: 'Costos', href: '/equipo4/costos', requires: 'costos.view' },
    { label: 'Categorías', href: '/equipo4/categorias', requires: 'productos.view' },
  ],
  'Stock': [
    { label: 'Inventario', href: '/equipo4/inventario', requires: 'inventario.view' },
    { label: 'Almacenes', href: '/equipo4/almacenes', requires: 'almacenes.view' },
    { label: 'Kardex', href: '/equipo4/kardex', requires: 'kardex.view' },
    { label: 'Reservas', href: '/equipo4/reservas', requires: 'reservas.view' },
  ],
  'Compras': [
    { label: 'Proveedores', href: '/equipo4/proveedores', requires: 'proveedores.view' },
    { label: 'Compras', href: '/equipo4/compras', requires: 'compras.view' },
    { label: 'Recepciones', href: '/equipo4/recepciones', requires: 'recepciones.view' },
  ],
  'Operación': [
    { label: 'Devoluciones', href: '/equipo4/devoluciones', requires: 'devoluciones.view' },
    { label: 'Alertas', href: '/equipo4/alertas', requires: 'alertas.view' },
    { label: 'Conteos', href: '/equipo4/conteos', requires: 'conteos.view' },
  ],
}

const groupModules = computed(() => {
  const result = {}
  for (const [group, modules] of Object.entries(allGroupModules)) {
    result[group] = modules.filter(m => can(m.requires))
  }
  return result
})

// Solo mostrar el badge de alertas si el usuario puede ver alertas
const showAlertsBadge = computed(() => can('alertas.view') && alertsCount.value > 0)

// Pills superiores: el Dashboard se representa con el icono de casa
const pillGroups = computed(() => navGroups.value.filter(g => g.label !== 'Dashboard'))
const dashboardGroup = computed(() => navGroups.value.find(g => g.label === 'Dashboard') || null)

// Contenido del sidebar: secciones del grupo activo
const sidebarTitle = computed(() => (activeGroup.value && activeGroup.value !== 'Dashboard') ? activeGroup.value : 'General')
const sidebarItems = computed(() => {
  const g = activeGroup.value
  if (g && g !== 'Dashboard' && groupModules.value[g]?.length) return groupModules.value[g]
  return [{ label: 'Resumen', href: '/equipo4' }]
})

function isSidebarActive(item) {
  if (item.href === '/equipo4') return page.url === '/equipo4'
  return page.url.startsWith(item.href)
}

// Iconos (trazos outline 24x24) por modulo
const icons = {
  cube: 'M21 8l-9-5-9 5v8l9 5 9-5V8z M3 8l9 5 9-5 M12 13v8',
  list: 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01',
  coin: 'M12 3a9 9 0 100 18 9 9 0 000-18z M12 7v10 M9 10c0-1.2 1.3-2 3-2s3 .8 3 2-1.3 1.6-3 2-3 .8-3 2 1.3 2 3 2 3-.8 3-2',
  bell: 'M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9 M13.7 21a2 2 0 01-3.4 0',
  back: 'M3 10h11a5 5 0 010 10H9 M7 6l-4 4 4 4',
  check: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2 M9 3h6v4H9z M9 14l2 2 4-4',
  home: 'M3 12l9-9 9 9 M5 10v10h5v-6h4v6h5V10',
  building: 'M3 21h18 M5 21V7l7-4 7 4v14 M9 21v-6h6v6',
  users: 'M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2 M10 3a4 4 0 100 8 4 4 0 000-8z M21 21v-2a4 4 0 00-3-3.9 M16 3.1a4 4 0 010 7.8',
  tag: 'M3 12V3h9l9 9-9 9-9-9z M7.5 7.5h.01',
  truck: 'M1 6h13v10H1z M14 9h4l4 4v3h-8z M6 19a2 2 0 100-4 2 2 0 000 4z M18 19a2 2 0 100-4 2 2 0 000 4z',
  clipboard: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2 M9 3h6v4H9z',
  lock: 'M5 11h14v10H5z M8 11V7a4 4 0 018 0v4',
}
const iconByHref = {
  '/equipo4': 'home',
  '/equipo4/productos': 'cube', '/equipo4/souvenirs': 'tag', '/equipo4/costos': 'coin', '/equipo4/categorias': 'list',
  '/equipo4/inventario': 'cube', '/equipo4/almacenes': 'building', '/equipo4/kardex': 'list', '/equipo4/reservas': 'lock',
  '/equipo4/proveedores': 'users', '/equipo4/compras': 'clipboard', '/equipo4/recepciones': 'truck',
  '/equipo4/devoluciones': 'back', '/equipo4/alertas': 'bell', '/equipo4/conteos': 'check',
}
function iconPath(href) { return icons[iconByHref[href] || 'cube'] }
</script>

<template>
  <Head title="Inventarios" />
  <div class="min-h-screen bg-[#F4F7FB] text-slate-800 antialiased">
    <!-- Sidebar -->
    <aside class="hidden lg:flex fixed inset-y-0 left-0 z-40 w-[252px] flex-col bg-[#102F82] text-white">
      <a href="/equipo4" class="flex items-center gap-3 px-5 h-[72px] border-b border-white/10">
        <span class="grid place-items-center w-10 h-10 rounded-xl bg-[#2F6BE8]">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6l8-3z"/></svg>
        </span>
        <span class="leading-tight">
          <span class="block text-base font-bold">Campus</span>
          <span class="block text-xs text-blue-200">Digital · Equipo 4</span>
        </span>
      </a>

      <div class="px-4 pt-6 flex-1 overflow-y-auto">
        <p class="px-3 mb-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-blue-300">{{ sidebarTitle }}</p>
        <nav class="space-y-1">
          <a
            v-for="item in sidebarItems"
            :key="item.href"
            :href="item.href"
            :class="isSidebarActive(item)
              ? 'flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-medium bg-[#2F6BE8] text-white'
              : 'flex items-center gap-3 px-3 py-3 rounded-lg text-sm font-medium text-blue-100 hover:bg-white/10 transition'"
          >
            <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="iconPath(item.href)"/></svg>
            <span class="flex-1">{{ item.label }}</span>
            <span v-if="item.label === 'Alertas' && showAlertsBadge" class="min-w-[20px] h-5 px-1.5 grid place-items-center rounded-full bg-rose-500 text-white text-[10px] font-bold">
              {{ alertsCount > 9 ? '9+' : alertsCount }}
            </span>
          </a>
        </nav>
      </div>

      <p class="px-5 py-4 text-[11px] text-blue-300 border-t border-white/10">Campus Digital · Equipo 4 · 2026</p>
    </aside>

    <div class="lg:pl-[252px]">
      <!-- Barra superior -->
      <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
        <div class="flex h-[72px] items-center gap-3 px-4 sm:px-6">
          <a href="/equipo4" class="lg:hidden grid place-items-center w-10 h-10 rounded-xl bg-[#2F6BE8] text-white shrink-0" aria-label="Inicio">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6l8-3z"/></svg>
          </a>

          <nav class="flex items-center gap-2 overflow-x-auto flex-1 min-w-0">
            <a
              v-if="dashboardGroup"
              href="/equipo4"
              aria-label="Dashboard"
              :class="isActive(dashboardGroup)
                ? 'grid place-items-center w-10 h-10 rounded-full bg-[#1B3A8F] text-white shrink-0'
                : 'grid place-items-center w-10 h-10 rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200 transition shrink-0'"
            >
              <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icons.home"/></svg>
            </a>
            <a
              v-for="group in pillGroups"
              :key="group.label"
              :href="group.href"
              :class="isActive(group)
                ? 'inline-flex items-center gap-2 px-4 h-10 rounded-full text-sm font-semibold bg-[#1B3A8F] text-white whitespace-nowrap'
                : 'inline-flex items-center gap-2 px-4 h-10 rounded-full text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200 transition whitespace-nowrap'"
            >
              {{ group.label }}
              <span
                v-if="group.label === 'Operación' && showAlertsBadge"
                class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold"
                :title="alertsCount + ' alerta(s) urgente(s)'"
              >
                {{ alertsCount > 9 ? '9+' : alertsCount }}
              </span>
            </a>
          </nav>

          <a v-if="can('alertas.view')" href="/equipo4/alertas" class="relative grid place-items-center w-10 h-10 rounded-full text-amber-500 hover:bg-slate-100 transition shrink-0" aria-label="Alertas">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"><path :d="icons.bell"/></svg>
            <span v-if="showAlertsBadge" class="absolute top-1 right-1 w-2.5 h-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>
          </a>

          <div ref="userMenuRef" class="relative shrink-0">
            <button
              type="button"
              @click="toggleUserMenu"
              class="flex items-center gap-3 rounded-full pl-3 pr-1 py-1 text-sm transition hover:bg-slate-50 focus:outline-none"
            >
              <div class="hidden md:flex flex-col items-end leading-tight text-right">
                <span class="text-sm font-semibold text-slate-800">{{ currentUser.name }}</span>
                <span class="text-[11px] text-slate-400">{{ currentRoleLabel }}</span>
              </div>
              <span class="w-10 h-10 rounded-full bg-[#1B3A8F] text-white grid place-items-center text-xs font-bold">
                {{ currentUser.initials }}
              </span>
            </button>

            <Transition
              enter-active-class="transition ease-out duration-200"
              enter-from-class="opacity-0 scale-95"
              enter-to-class="opacity-100 scale-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="opacity-100 scale-100"
              leave-to-class="opacity-0 scale-95"
            >
              <div
                v-show="showingUserMenu"
                class="absolute right-0 mt-2 w-64 origin-top-right rounded-xl bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 z-50"
              >
                <div class="px-4 py-3 border-b border-slate-100">
                  <p class="text-sm font-semibold text-slate-800">{{ currentUser.name }}</p>
                  <p class="text-xs text-slate-500 truncate">{{ currentUser.email }}</p>
                  <p class="text-[10px] text-[#2F6BE8] font-semibold mt-1">{{ currentRoleLabel }}</p>
                </div>
                <Link href="/profile" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Mi perfil</Link>
                <Link href="/logout" method="post" as="button" class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Cerrar sesión</Link>
              </div>
            </Transition>
          </div>
        </div>

        <nav aria-label="Módulos de Campus Digital" class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-5 py-3 text-sm">
          <Link href="/profile" class="text-slate-600 hover:text-blue-700">Mi perfil</Link>
          <Link href="/servicios-estudiante" class="text-slate-600 hover:text-blue-700">Servicios</Link>
          <Link href="/modulo6" class="text-slate-600 hover:text-blue-700">Comunidad</Link>
          <label class="flex items-center gap-2 ml-auto">Negocio
            <select :value="page.props.inventoryContext?.business_id" @change="router.post('/equipo4/context', { business_id: $event.target.value })" class="rounded-lg border-slate-200 text-sm">
              <option v-for="business in page.props.inventoryContext?.businesses || []" :key="business.id" :value="business.id">{{ business.name }}</option>
            </select>
          </label>
        </nav>

        <!-- Secciones en pantallas pequeñas (el sidebar se oculta) -->
        <nav v-if="sidebarItems.length > 1" class="lg:hidden flex gap-2 overflow-x-auto px-4 sm:px-6 pb-3">
          <a
            v-for="item in sidebarItems"
            :key="item.href"
            :href="item.href"
            :class="isSidebarActive(item)
              ? 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#2F6BE8] text-white whitespace-nowrap'
              : 'px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-600 whitespace-nowrap'"
          >{{ item.label }}</a>
        </nav>
      </header>

      <main>
        <div class="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8 py-8">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
