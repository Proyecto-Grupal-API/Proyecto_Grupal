<script setup>
import { usePage } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import logoUrl from '@/../images/campus-digital-logo.png'

const page = usePage()

const navGroups = [
  { label: 'Dashboard', href: '/equipo4', match: (url) => url === '/equipo4' },
  { label: 'Catálogo', href: '/equipo4/productos', match: (url) => url.startsWith('/equipo4/productos') || url.startsWith('/equipo4/souvenirs') || url.startsWith('/equipo4/costos') },
  { label: 'Stock', href: '/equipo4/inventario', match: (url) => url.startsWith('/equipo4/inventario') || url.startsWith('/equipo4/almacenes') || url.startsWith('/equipo4/kardex') || url.startsWith('/equipo4/reservas') },
  { label: 'Compras', href: '/equipo4/proveedores', match: (url) => url.startsWith('/equipo4/proveedores') || url.startsWith('/equipo4/compras') || url.startsWith('/equipo4/recepciones') },
  { label: 'Operación', href: '/equipo4/devoluciones', match: (url) => url.startsWith('/equipo4/devoluciones') || url.startsWith('/equipo4/alertas') || url.startsWith('/equipo4/conteos') },
]

function isActive(group) { return group.match(page.url) }

const alertsCount = computed(() => page.props.alertsCount || 0)

// Usuario actual (con fallback si no hay auth todavia)
const currentUser = computed(() => {
  const u = page.props.auth?.user
  return {
    name: u?.name ?? 'Administrador de inventario',
    email: u?.email ?? 'admin@campus-digital.mx',
    initials: u?.initials ?? 'E4',
  }
})

// Dropdown de usuario
const showingUserMenu = ref(false)
const userMenuRef = ref(null)

function toggleUserMenu() { showingUserMenu.value = !showingUserMenu.value }

function closeUserMenu(e) {
  if (userMenuRef.value && !userMenuRef.value.contains(e.target)) {
    showingUserMenu.value = false
  }
}

function closeOnEscape(e) {
  if (e.key === 'Escape') showingUserMenu.value = false
}

onMounted(() => {
  document.addEventListener('click', closeUserMenu)
  document.addEventListener('keydown', closeOnEscape)
})

onUnmounted(() => {
  document.removeEventListener('click', closeUserMenu)
  document.removeEventListener('keydown', closeOnEscape)
})

const activeGroup = computed(() => navGroups.find(g => isActive(g))?.label ?? null)

const groupModules = {
  'Catálogo': [
    { label: 'Productos', href: '/equipo4/productos' },
    { label: 'Souvenirs', href: '/equipo4/souvenirs' },
    { label: 'Costos', href: '/equipo4/costos' },
  ],
  'Stock': [
    { label: 'Inventario', href: '/equipo4/inventario' },
    { label: 'Almacenes', href: '/equipo4/almacenes' },
    { label: 'Kardex', href: '/equipo4/kardex' },
    { label: 'Reservas', href: '/equipo4/reservas' },
  ],
  'Compras': [
    { label: 'Proveedores', href: '/equipo4/proveedores' },
    { label: 'Compras', href: '/equipo4/compras' },
    { label: 'Recepciones', href: '/equipo4/recepciones' },
  ],
  'Operación': [
    { label: 'Devoluciones', href: '/equipo4/devoluciones' },
    { label: 'Alertas', href: '/equipo4/alertas' },
    { label: 'Conteos', href: '/equipo4/conteos' },
  ],
}

function isModuleActive(module) { return page.url.startsWith(module.href) }
</script>

<template>
  <div class="min-h-screen bg-[#F5F8FC] text-slate-800 antialiased">
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
          <a href="/equipo4" class="flex items-center">
            <img :src="logoUrl" alt="Campus Digital" class="h-8 w-auto">
          </a>

          <nav class="hidden md:flex items-center gap-1 sm:-my-px">
            <a
              v-for="group in navGroups"
              :key="group.label"
              :href="group.href"
              :class="isActive(group)
                ? 'inline-flex items-center px-3 py-2 text-sm font-semibold text-[#00338D] border-b-2 border-[#0284C7]'
                : 'inline-flex items-center px-3 py-2 text-sm font-medium text-slate-500 hover:text-[#00338D] hover:border-b-2 hover:border-slate-300 border-b-2 border-transparent transition'"
            >
              {{ group.label }}
              <span
                v-if="group.label === 'Operación' && alertsCount > 0"
                class="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold"
                :title="alertsCount + ' alerta(s) urgente(s)'"
              >
                {{ alertsCount > 9 ? '9+' : alertsCount }}
              </span>
            </a>
          </nav>

          <!-- Dropdown de usuario (estilo Eq. 1) -->
          <div ref="userMenuRef" class="relative">
            <button
              type="button"
              @click="toggleUserMenu"
              class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50 focus:outline-none"
            >
              <span class="hidden md:inline">{{ currentUser.name }}</span>
              <span class="w-9 h-9 rounded-full bg-[#00338D] text-white grid place-items-center text-xs font-bold">
                {{ currentUser.initials }}
              </span>
              <svg class="hidden md:inline h-4 w-4 -mr-0.5 text-slate-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
              </svg>
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
                class="absolute right-0 mt-2 w-56 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 z-50"
              >
                <div class="px-4 py-3 border-b border-slate-100">
                  <p class="text-sm font-semibold text-slate-800">{{ currentUser.name }}</p>
                  <p class="text-xs text-slate-500 truncate">{{ currentUser.email }}</p>
                </div>

                <a href="/equipo4/perfil" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                  Mi perfil
                </a>
                <a href="/equipo4" class="block px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                  Configuración
                </a>

                <div class="border-t border-slate-100 mt-1 pt-1">
                  <form method="POST" action="/logout">
                    <input type="hidden" name="_token" :value="page.props.csrfToken || ''">
                    <button
                      type="submit"
                      class="block w-full text-left px-4 py-2 text-sm text-rose-600 transition hover:bg-rose-50"
                    >
                      Cerrar sesión
                    </button>
                  </form>
                </div>
              </div>
            </Transition>
          </div>
        </div>
      </div>
    </header>

    <div v-if="activeGroup && groupModules[activeGroup]" class="bg-white border-b border-slate-200">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex gap-6 -mb-px overflow-x-auto">
          <a
            v-for="mod in groupModules[activeGroup]"
            :key="mod.href"
            :href="mod.href"
            :class="isModuleActive(mod)
              ? 'inline-flex items-center py-3 text-sm font-semibold text-[#00338D] border-b-2 border-[#00338D] whitespace-nowrap'
              : 'inline-flex items-center py-3 text-sm font-medium text-slate-500 hover:text-[#00338D] hover:border-b-2 hover:border-slate-300 border-b-2 border-transparent whitespace-nowrap transition'"
          >
            {{ mod.label }}
            <span v-if="mod.label === 'Alertas' && alertsCount > 0" class="ml-2 w-2 h-2 rounded-full bg-rose-600"></span>
          </a>
        </nav>
      </div>
    </div>

    <main>
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        <slot />
      </div>
    </main>

    <footer class="border-t border-slate-200 bg-white mt-12">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 text-xs text-slate-500">
        Campus Digital · Equipo 4 · 2026
      </div>
    </footer>
  </div>
</template>
