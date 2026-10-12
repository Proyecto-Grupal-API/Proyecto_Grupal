<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { Home, UserRound, WalletCards, Store, Package, Wrench, Users, Gift, Bell, LogOut, ShoppingCart } from 'lucide-vue-next';

defineProps({ title: { type: String, default: 'Tienda' } });

const form = useForm({});
const logout = () => form.post('/logout');

const items = [
  { label: 'Mi Perfil', href: '#', icon: UserRound },
  { label: 'Cartera', href: '#', icon: WalletCards },
  { label: 'Tienda', href: '/tienda', icon: Store, active: true },
  { label: 'Mis Productos', href: '#', icon: Package },
  { label: 'Servicios', href: '#', icon: Wrench },
  { label: 'Comunidad', href: '#', icon: Users },
  { label: 'Recompensas', href: '#', icon: Gift },
];
</script>

<template>
  <div class="min-h-screen bg-campus-bg">
    <aside class="fixed inset-y-0 left-0 z-30 w-[210px] bg-campus-navy text-white">
      <div class="flex h-[66px] items-center gap-3 px-4 border-b border-white/10">
        <div class="h-9 w-9 rounded-lg bg-blue-500 flex items-center justify-center font-bold">C</div>
        <div>
          <div class="font-bold text-sm">Campus</div>
          <div class="text-[11px] text-blue-200">Digital v2.4</div>
        </div>
      </div>

      <div class="px-3 pt-5 text-[10px] uppercase tracking-wider text-blue-200 font-semibold">Identidad</div>
      <nav class="px-2 py-2 space-y-1">
        <Link v-for="item in items" :key="item.label" :href="item.href"
          :class="['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition', item.active ? 'bg-blue-600 text-white' : 'text-blue-100 hover:bg-white/10']">
          <component :is="item.icon" :size="16" />
          <span>{{ item.label }}</span>
        </Link>
      </nav>
    </aside>

    <div class="ml-[210px] min-h-screen">
      <header class="h-[66px] bg-white border-b border-campus-border flex items-center justify-between px-5 sticky top-0 z-20">
        <nav class="flex items-center gap-2">
          <Link href="/tienda" class="mr-2 text-lg">🏠</Link>
          <Link v-for="item in items" :key="item.label" :href="item.href"
            :class="['rounded-full px-4 py-2 text-xs font-semibold flex items-center gap-1.5', item.active ? 'bg-blue-50 text-campus-blue' : 'bg-slate-50 text-slate-600 hover:bg-slate-100']">
            <component :is="item.icon" :size="14" />
            {{ item.label }}
          </Link>
        </nav>
        <div class="flex items-center gap-4">
          <Bell :size="18" class="text-slate-400"/>
          <div class="flex items-center gap-2">
            <div class="h-9 w-9 rounded-full bg-campus-navy text-white flex items-center justify-center text-xs font-bold">AD</div>
            <div class="hidden md:block">
              <div class="text-xs font-bold text-slate-700">Administrador</div>
              <div class="text-[10px] text-slate-400">ADMIN · Campus Digital</div>
            </div>
            <button @click="logout" class="ml-2 text-slate-400 hover:text-red-500" title="Cerrar sesión"><LogOut :size="16"/></button>
          </div>
        </div>
      </header>

      <main class="p-5 md:p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
