<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({ user: { type: Object, required: true } });
defineEmits(['toggle-sidebar']);

const modules = [
    { label: 'Mi Perfil', icon: '👤', available: true },
    { label: 'Cartera', icon: '💳' },
    { label: 'Tienda', icon: '🛍️' },
    { label: 'Mis Productos', icon: '📦' },
    { label: 'Servicios', icon: '🎓' },
    { label: 'Comunidad', icon: '👥' },
    { label: 'Recompensas', icon: '🎁' },
];

const page = usePage();
const profileModuleActive = computed(() => {
    // Reading the Inertia URL keeps this state reactive after client-side visits.
    const currentUrl = page.url;
    return Boolean(currentUrl) && [
        'dashboard', 'profile.*', 'roles.*', 'security.*', 'identity.qr.*',
        'nfc-cards.*', 'students.*', 'student-services.*',
    ].some(pattern => route().current(pattern));
});
const initials = computed(() => props.user.name?.trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('') || 'U');
</script>

<template>
    <header class="campus-topbar">
        <div class="topbar-left">
            <button type="button" class="mobile-menu" aria-label="Abrir menú" @click="$emit('toggle-sidebar')">☰</button>
            <Link :href="route('dashboard')" class="home-button" aria-label="Inicio" title="Inicio">🏠</Link>
            <nav class="module-list" aria-label="Módulos">
                <template v-for="module in modules" :key="module.label">
                    <Link
                        v-if="module.available"
                        :href="route('dashboard')"
                        class="module-button"
                        :class="{ activo: profileModuleActive }"
                        :aria-current="profileModuleActive ? 'true' : undefined"
                    ><span aria-hidden="true">{{ module.icon }}</span>{{ module.label }}</Link>
                    <button v-else type="button" class="module-button unavailable" disabled :title="`${module.label}: aún no integrado`">
                        <span aria-hidden="true">{{ module.icon }}</span>{{ module.label }}
                    </button>
                </template>
            </nav>
        </div>

        <div class="topbar-right">
            <button type="button" class="notification-button" disabled aria-label="Notificaciones aún no disponibles" title="Notificaciones aún no disponibles">🔔</button>
            <div class="avatar" aria-hidden="true">{{ initials }}</div>
            <div class="student-info">
                <strong :title="user.name">{{ user.name }}</strong>
                <span :title="user.email">{{ user.email }}</span>
            </div>
        </div>
    </header>
</template>

<style scoped>
.campus-topbar { min-height: 70px; padding: 0 20px; display: flex; align-items: center; justify-content: space-between; gap: 15px; background: white; border-bottom: 1px solid #e2e8f0; }
.topbar-left { display: flex; min-width: 0; flex: 1; align-items: center; gap: 8px; }
.home-button { flex: none; padding: 8px; font-size: 18px; line-height: 1; }
.module-list { display: flex; min-width: 0; align-items: center; gap: 8px; overflow-x: auto; padding: 8px 0; scrollbar-width: thin; }
.module-button { display: inline-flex; flex: none; align-items: center; gap: 5px; border: 0; border-radius: 18px; padding: 8px 12px; background: #edf2f7; color: #263b70; font-size: 12px; font-weight: 600; white-space: nowrap; }
.module-button:hover:not(:disabled) { background: #dce6f8; }
.module-button.activo { background: #2849a8; color: white; }
.module-button.unavailable { cursor: not-allowed; opacity: .75; }
.topbar-right { display: flex; flex: none; align-items: center; gap: 10px; }
.notification-button { padding: 8px; background: transparent; font-size: 17px; cursor: not-allowed; }
.avatar { display: flex; width: 36px; height: 36px; flex: none; align-items: center; justify-content: center; border-radius: 50%; background: #24459c; color: white; font-size: 12px; font-weight: bold; }
.student-info { display: flex; min-width: 90px; max-width: 145px; flex-direction: column; }
.student-info strong { overflow: hidden; color: #17233d; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.student-info span { overflow: hidden; color: #8a9ab8; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
.mobile-menu { display: none; flex: none; border-radius: 6px; padding: 8px; color: #263b70; }
a:focus-visible, button:focus-visible { outline: 2px solid #2849a8; outline-offset: 2px; }
@media (max-width: 1023px) { .mobile-menu { display: block; } }
@media (max-width: 640px) { .campus-topbar { padding: 0 12px; } .student-info { display: none; } .notification-button { padding: 4px; } }
</style>
