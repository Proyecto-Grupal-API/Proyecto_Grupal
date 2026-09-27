<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import CampusIcon from './CampusIcon.vue';

defineProps({ open: { type: Boolean, default: false } });
defineEmits(['close']);

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const roles = computed(() => user.value.roles ?? []);
const hasGlobalRole = name => roles.value.some(role => role.name === name && role.scope_type == null && role.scope_id == null);
const canManageStudents = computed(() => ['admin', 'maestro', 'student_manager'].some(hasGlobalRole));
const hasConfirmedStudentProfile = computed(() => page.props.auth?.hasStudentProfile === true);

const links = computed(() => [
    { label: 'Inicio', name: 'dashboard', active: 'dashboard', icon: '🏠' },
    { label: 'Perfil', name: 'profile.edit', active: 'profile.*', icon: '👤' },
    ...(hasConfirmedStudentProfile.value ? [{ label: 'Mi condición y privacidad', name: 'student-services.index', active: 'student-services.*', icon: '🎓' }] : []),
    { label: 'Seguridad y roles', name: 'roles.index', active: 'roles.*', icon: '🔐' },
    { label: 'Dispositivos y sesiones', name: 'security.devices.index', active: 'security.devices.*', icon: '💻' },
    { label: 'Identidad QR', name: 'identity.qr.index', active: 'identity.qr.*', icon: '📱' },
    { label: 'Tarjetas NFC', name: 'nfc-cards.index', active: 'nfc-cards.*', icon: '🪪' },
    ...(canManageStudents.value ? [{ label: 'Estudiantes', name: 'students.index', active: 'students.*', icon: '👥' }] : []),
]);
</script>

<template>
    <div v-if="open" class="sidebar-backdrop" @click="$emit('close')" />
    <aside class="campus-sidebar" :class="{ open }" aria-label="Navegación de identidad">
        <div class="logo">
            <Link :href="route('dashboard')" class="logo-link" @click="$emit('close')">
                <span class="logo-icon" aria-hidden="true">🛡️</span>
                <span><strong>Campus</strong><small>Digital · Mi Perfil</small></span>
            </Link>
            <button type="button" class="close-button" aria-label="Cerrar menú" @click="$emit('close')"><CampusIcon name="close" /></button>
        </div>

        <nav class="menu" aria-label="Mi Perfil">
            <p class="menu-title">IDENTIDAD</p>
            <Link
                v-for="item in links"
                :key="item.name"
                :href="route(item.name)"
                class="menu-item"
                :class="{ activo: route().current(item.active) }"
                :aria-current="route().current(item.active) ? 'page' : undefined"
                @click="$emit('close')"
            ><span class="menu-icon" aria-hidden="true">{{ item.icon }}</span><span>{{ item.label }}</span></Link>
        </nav>

        <div class="sidebar-footer">
            <div class="mobile-user"><strong>{{ user.name }}</strong><span>{{ user.email }}</span></div>
            <Link :href="route('logout')" method="post" as="button" class="menu-item logout-item">
                <CampusIcon name="logout" class="logout-icon" />Cerrar sesión
            </Link>
        </div>
    </aside>
</template>

<style scoped>
.campus-sidebar { display: flex; width: 220px; height: 100vh; min-height: 0; flex: none; flex-direction: column; position: sticky; top: 0; background: #10285d; color: white; }
.logo { display: flex; height: 70px; flex: none; align-items: center; justify-content: space-between; padding: 0 18px; border-bottom: 1px solid rgba(255,255,255,.15); }
.logo-link { display: flex; min-width: 0; align-items: center; gap: 10px; }
.logo-icon { display: flex; width: 34px; height: 34px; flex: none; align-items: center; justify-content: center; border-radius: 8px; background: #2468e5; }
.logo strong { display: block; font-size: 15px; font-weight: 700; line-height: 1.2; }
.logo small { display: block; margin-top: 2px; color: #76b5ff; font-size: 11px; white-space: nowrap; }
.menu { min-height: 0; flex: 1; overflow-y: auto; padding: 0 8px; }
.menu-title { margin: 20px 8px 10px; color: #4e8ce9; font-size: 10px; font-weight: 700; letter-spacing: 1px; }
.menu-item { display: flex; width: 100%; min-height: 40px; align-items: center; gap: 10px; margin-bottom: 4px; padding: 8px 12px; border: 0; border-radius: 6px; background: transparent; color: #8fc5ff; font-size: 13px; line-height: 1.25; text-align: left; }
.menu-item:hover { background: rgba(255,255,255,.08); }
.menu-item.activo { background: #276be8; color: white; font-weight: 700; }
.menu-icon { width: 18px; flex: none; text-align: center; }
.sidebar-footer { flex: none; border-top: 1px solid rgba(255,255,255,.15); padding: 8px; }
.logout-item { cursor: pointer; }
.logout-icon { width: 18px; height: 18px; flex: none; }
.mobile-user, .close-button { display: none; }
.sidebar-backdrop { display: none; }
a:focus-visible, button:focus-visible { outline: 2px solid white; outline-offset: 2px; }
@media (max-width: 1023px) {
    .campus-sidebar { position: fixed; z-index: 50; left: 0; top: 0; visibility: hidden; transform: translateX(-100%); transition: transform .2s, visibility .2s; }
    .campus-sidebar.open { visibility: visible; transform: translateX(0); }
    .sidebar-backdrop { display: block; position: fixed; inset: 0; z-index: 40; background: rgba(15,23,42,.5); }
    .close-button { display: flex; width: 24px; height: 24px; align-items: center; justify-content: center; }
    .close-button svg { width: 18px; height: 18px; }
    .mobile-user { display: flex; min-width: 0; flex-direction: column; margin: 0 12px 8px; }
    .mobile-user strong, .mobile-user span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mobile-user strong { font-size: 13px; }
    .mobile-user span { color: #8fc5ff; font-size: 11px; }
}
</style>
