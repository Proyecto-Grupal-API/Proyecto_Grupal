<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    title?: string;
    subtitle?: string;
}>();

const page = usePage();

const userName = computed(() => {
    const user = page.props.auth?.user as
        | {
              name?: string;
          }
        | null
        | undefined;

    return user?.name ?? 'Usuario';
});

const userInitials = computed(() => {
    const parts = userName.value.trim().split(' ').filter(Boolean);

    if (parts.length === 0) {
        return 'U';
    }

    if (parts.length === 1) {
        return parts[0].charAt(0).toUpperCase();
    }

    return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
});

const currentUrl = computed(() => page.url);

const isExact = (path: string) => {
    return currentUrl.value === path;
};

const isActive = (path: string) => {
    return currentUrl.value.startsWith(path);
};

const isLibrary = computed(() => {
    return currentUrl.value.startsWith('/servicios-estudiante/biblioteca');
});

const isLockers = computed(() => {
    return currentUrl.value.startsWith('/servicios-estudiante/lockers');
});
</script>

<template>
    <div class="campus-shell">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-logo">CD</div>

                <div class="brand-information">
                    <strong> Campus Digital </strong>

                    <span> Servicios al Estudiante </span>
                </div>
            </div>

            <div class="sidebar-content">
                <div class="sidebar-title">SERVICIOS</div>

                <!-- INICIO -->
                <Link
                    href="/servicios-estudiante"
                    class="sidebar-item"
                    :class="{
                        active: isExact('/servicios-estudiante'),
                    }"
                >
                    <span class="sidebar-icon"> ◈ </span>

                    <span> Inicio </span>
                </Link>

                <!-- BIBLIOTECA 5.1 / 5.2 -->
                <Link
                    href="/servicios-estudiante/biblioteca"
                    class="sidebar-item"
                    :class="{
                        active: isLibrary,
                    }"
                >
                    <span class="sidebar-icon"> ▣ </span>

                    <span> Biblioteca </span>

                    <span
                        class="sidebar-arrow"
                        :class="{
                            open: isLibrary,
                        }"
                    >
                        ›
                    </span>
                </Link>

                <div v-if="isLibrary" class="submenu">
                    <Link
                        href="/servicios-estudiante/biblioteca"
                        class="submenu-item"
                        :class="{
                            active: isExact('/servicios-estudiante/biblioteca'),
                        }"
                    >
                        Catálogo
                    </Link>

                    <Link
                        href="/servicios-estudiante/biblioteca/ejemplares"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/biblioteca/ejemplares',
                            ),
                        }"
                    >
                        Ejemplares
                    </Link>

                    <Link
                        href="/servicios-estudiante/biblioteca/prestamos"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/biblioteca/prestamos',
                            ),
                        }"
                    >
                        Préstamos
                    </Link>

                    <Link
                        href="/servicios-estudiante/biblioteca/reservas"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/biblioteca/reservas',
                            ),
                        }"
                    >
                        Reservas de biblioteca
                    </Link>

                    <Link
                        href="/servicios-estudiante/biblioteca/multas"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/biblioteca/multas',
                            ),
                        }"
                    >
                        Multas
                    </Link>
                </div>

                <!-- LOCKERS 5.3 / 5.4 -->
                <Link
                    href="/servicios-estudiante/lockers"
                    class="sidebar-item"
                    :class="{
                        active: isLockers,
                    }"
                >
                    <span class="sidebar-icon"> ▤ </span>

                    <span> Lockers </span>

                    <span
                        class="sidebar-arrow"
                        :class="{
                            open: isLockers,
                        }"
                    >
                        ›
                    </span>
                </Link>

                <div v-if="isLockers" class="submenu">
                    <Link
                        href="/servicios-estudiante/lockers"
                        class="submenu-item"
                        :class="{
                            active: isExact('/servicios-estudiante/lockers'),
                        }"
                    >
                        Catálogo
                    </Link>

                    <Link
                        href="/servicios-estudiante/lockers/periodos"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/lockers/periodos',
                            ),
                        }"
                    >
                        Periodos
                    </Link>

                    <Link
                        href="/servicios-estudiante/lockers/solicitudes"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/lockers/solicitudes',
                            ),
                        }"
                    >
                        Solicitudes
                    </Link>

                    <Link
                        href="/servicios-estudiante/lockers/asignaciones"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/lockers/asignaciones',
                            ),
                        }"
                    >
                        Asignaciones
                    </Link>

                    <Link
                        href="/servicios-estudiante/lockers/acceso"
                        class="submenu-item"
                        :class="{
                            active: isActive(
                                '/servicios-estudiante/lockers/acceso',
                            ),
                        }"
                    >
                        Validación de locker
                    </Link>
                </div>

                <!-- 5.5 -->
                <Link
                    href="/servicios-estudiante/reservas"
                    class="sidebar-item"
                    :class="{
                        active: isActive('/servicios-estudiante/reservas'),
                    }"
                >
                    <span class="sidebar-icon"> ◷ </span>

                    <span> Reservas </span>
                </Link>

                <!-- 5.6 -->
                <Link
                    href="/servicios-estudiante/zonas-descanso"
                    class="sidebar-item"
                    :class="{
                        active: isActive(
                            '/servicios-estudiante/zonas-descanso',
                        ),
                    }"
                >
                    <span class="sidebar-icon"> ◒ </span>

                    <span> Zonas de descanso </span>
                </Link>

                <!-- 5.7 -->
                <Link
                    href="/servicios-estudiante/renta-equipos"
                    class="sidebar-item"
                    :class="{
                        active: isActive('/servicios-estudiante/renta-equipos'),
                    }"
                >
                    <span class="sidebar-icon"> ▦ </span>

                    <span> Renta de equipos </span>
                </Link>

                <!-- 5.8 -->
                <Link
                    href="/servicios-estudiante/servicios-impresiones"
                    class="sidebar-item"
                    :class="{
                        active: isActive(
                            '/servicios-estudiante/servicios-impresiones',
                        ),
                    }"
                >
                    <span class="sidebar-icon"> ▧ </span>

                    <span> Servicios e impresiones </span>
                </Link>

                <!-- 5.9 -->
                <Link
                    href="/servicios-estudiante/soporte"
                    class="sidebar-item"
                    :class="{
                        active: isActive('/servicios-estudiante/soporte'),
                    }"
                >
                    <span class="sidebar-icon"> ◇ </span>

                    <span> Soporte </span>
                </Link>

                <!-- 5.10 -->
                <Link
                    href="/servicios-estudiante/calendarios-cupos"
                    class="sidebar-item"
                    :class="{
                        active: isActive(
                            '/servicios-estudiante/calendarios-cupos',
                        ),
                    }"
                >
                    <span class="sidebar-icon"> ▥ </span>

                    <span> Calendarios y cupos </span>
                </Link>

                <!-- 5.11 -->
                <Link
                    href="/servicios-estudiante/validacion-servicios"
                    class="sidebar-item"
                    :class="{
                        active: isActive(
                            '/servicios-estudiante/validacion-servicios',
                        ),
                    }"
                >
                    <span class="sidebar-icon"> ◎ </span>

                    <span> Validación de servicios </span>
                </Link>
            </div>

            <div class="sidebar-footer">
                <span> Equipo 5 </span>

                <small> Campus Digital </small>
            </div>
        </aside>

        <div class="main-area">
            <header class="global-header">
                <nav class="global-navigation">
                    <Link href="/" class="global-item home-item">
                        <span> ⌂ </span>
                    </Link>

                    <button type="button" class="global-item" disabled>
                        Mi Perfil
                    </button>

                    <button type="button" class="global-item" disabled>
                        Cartera
                    </button>

                    <button type="button" class="global-item" disabled>
                        Tienda
                    </button>

                    <button type="button" class="global-item" disabled>
                        Mis Productos
                    </button>

                    <Link
                        href="/servicios-estudiante"
                        class="global-item active"
                    >
                        Servicios
                    </Link>

                    <button type="button" class="global-item" disabled>
                        Comunidad
                    </button>

                    <button type="button" class="global-item" disabled>
                        Recompensas
                    </button>
                </nav>

                <div class="header-user">
                    <button type="button" class="notification-button">♢</button>

                    <div class="user-avatar">
                        {{ userInitials }}
                    </div>

                    <div class="user-info">
                        <strong>
                            {{ userName }}
                        </strong>

                        <span> Campus Digital </span>
                    </div>
                </div>
            </header>

            <section class="page-header">
                <div>
                    <h1>
                        {{ title ?? 'Servicios al Estudiante' }}
                    </h1>

                    <p v-if="subtitle">
                        {{ subtitle }}
                    </p>
                </div>

                <span class="service-badge"> Servicios </span>
            </section>

            <main class="content">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
.campus-shell {
    min-height: 100vh;
    display: flex;
    background: #f3f6fb;
    color: #25324a;
    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        'Segoe UI',
        sans-serif;
}

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 250px;
    height: 100vh;
    display: flex;
    flex-direction: column;
    background: #193873;
    color: white;
    z-index: 30;
}

.brand {
    min-height: 96px;
    padding: 20px 21px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.brand-logo {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 9px;
    background: #2e7bea;
    color: white;
    font-size: 12px;
    font-weight: 900;
}

.brand-information {
    display: flex;
    flex-direction: column;
}

.brand-information strong {
    font-size: 15px;
    font-weight: 800;
}

.brand-information span {
    margin-top: 3px;
    color: #bad0ef;
    font-size: 9px;
}

.sidebar-content {
    flex: 1;
    min-height: 0;
    padding: 20px 10px;
    overflow-y: auto;
}

.sidebar-content::-webkit-scrollbar {
    width: 5px;
}

.sidebar-content::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.16);
}

.sidebar-title {
    padding: 0 12px 10px;
    color: #75a2d7;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.sidebar-item {
    width: 100%;
    min-height: 43px;
    margin-bottom: 4px;
    padding: 0 13px;
    display: flex;
    align-items: center;
    gap: 11px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #afc5df;
    text-align: left;
    text-decoration: none;
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    box-sizing: border-box;
}

.sidebar-item:hover:not(.disabled) {
    background: rgba(255, 255, 255, 0.07);
    color: white;
}

.sidebar-item.active {
    background: #2f79e8;
    color: white;
}

.sidebar-icon {
    width: 20px;
    flex-shrink: 0;
    text-align: center;
    font-size: 13px;
}

.sidebar-arrow {
    margin-left: auto;
    font-size: 17px;
    transition: transform 0.15s ease;
}

.sidebar-arrow.open {
    transform: rotate(90deg);
}

.sidebar-item.disabled {
    cursor: default;
    opacity: 0.48;
}

.submenu {
    margin: -1px 0 7px 31px;
    padding: 4px 0 4px 12px;
    border-left: 1px solid rgba(255, 255, 255, 0.18);
}

.submenu-item {
    width: 100%;
    min-height: 32px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #9eb8d8;
    text-align: left;
    text-decoration: none;
    font: inherit;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    box-sizing: border-box;
}

.submenu-item:hover:not(.disabled) {
    color: white;
    background: rgba(255, 255, 255, 0.06);
}

.submenu-item.active {
    color: white;
    background: rgba(255, 255, 255, 0.1);
}

.submenu-item.disabled {
    cursor: default;
    opacity: 0.48;
}

.sidebar-footer {
    padding: 17px 22px;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    color: #9eb8d8;
}

.sidebar-footer span {
    font-size: 10px;
    font-weight: 700;
}

.sidebar-footer small {
    margin-top: 3px;
    color: #708caf;
    font-size: 8px;
}

.main-area {
    width: calc(100% - 250px);
    min-height: 100vh;
    margin-left: 250px;
}

.global-header {
    min-height: 76px;
    padding: 0 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    background: white;
    border-bottom: 1px solid #e1e6ee;
}

.global-navigation {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
}

.global-item {
    min-height: 37px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 0;
    border-radius: 18px;
    background: #edf2f7;
    color: #4f6078;
    text-decoration: none;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
}

.global-item:disabled {
    opacity: 1;
}

.global-item.active {
    background: #2d57ac;
    color: white;
}

.home-item {
    padding: 0 11px;
    background: transparent;
    font-size: 18px;
}

.header-user {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.notification-button {
    border: 0;
    background: transparent;
    color: #718096;
    font-size: 16px;
    cursor: pointer;
}

.user-avatar {
    width: 35px;
    height: 35px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #284fa0;
    color: white;
    font-size: 10px;
    font-weight: 800;
}

.user-info {
    min-width: 95px;
    display: flex;
    flex-direction: column;
}

.user-info strong {
    color: #25324a;
    font-size: 10px;
}

.user-info span {
    margin-top: 2px;
    color: #8c9aae;
    font-size: 8px;
}

.page-header {
    min-height: 79px;
    padding: 17px 29px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: white;
    border-bottom: 1px solid #e1e6ee;
}

.page-header h1 {
    margin: 0;
    color: #24324b;
    font-size: 20px;
    font-weight: 700;
}

.page-header p {
    margin: 5px 0 0;
    color: #8290a5;
    font-size: 10px;
}

.service-badge {
    padding: 6px 10px;
    border-radius: 7px;
    background: #eaf1fc;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
}

.content {
    padding: 24px 27px 40px;
}

@media (max-width: 1150px) {
    .global-navigation {
        max-width: calc(100vw - 480px);
    }

    .user-info {
        display: none;
    }
}

@media (max-width: 850px) {
    .sidebar {
        width: 210px;
    }

    .main-area {
        width: calc(100% - 210px);
        margin-left: 210px;
    }

    .global-header {
        padding-inline: 14px;
    }

    .global-navigation {
        max-width: calc(100vw - 285px);
    }

    .service-badge {
        display: none;
    }

    .content {
        padding: 20px;
    }
}

@media (max-width: 700px) {
    .campus-shell {
        display: block;
    }

    .sidebar {
        position: static;
        width: 100%;
        height: auto;
        min-height: auto;
    }

    .brand {
        min-height: 65px;
    }

    .sidebar-content {
        display: none;
    }

    .sidebar-footer {
        display: none;
    }

    .main-area {
        width: 100%;
        margin-left: 0;
    }

    .global-header {
        min-height: 64px;
    }

    .global-navigation {
        max-width: calc(100vw - 75px);
    }

    .notification-button {
        display: none;
    }

    .page-header {
        padding: 15px 19px;
    }

    .content {
        padding: 18px;
    }
}
</style>
