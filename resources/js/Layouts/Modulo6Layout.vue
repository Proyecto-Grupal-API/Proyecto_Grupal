<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import {
    computed,
    ref,
    onMounted,
    onBeforeUnmount,
    watchEffect,
    watch,
    nextTick,
} from "vue";
import Panellateral from "@/Components/Panellateral.vue";
import axios from "axios";
import CampusBrand from "@/Components/CampusBrand.vue";

const props = defineProps({
    headerTitle: {
        type: String,
        default: "Panel de Control",
    },
});

const page = usePage();
const currentUrl = computed(() => page.url.split("?")[0].split("#")[0]);
const esPerfil = computed(() => !currentUrl.value.startsWith("/modulo6"));
const menuAbierto = ref(false);
const cuentaAbierta = ref(false);
const botonMenu = ref(null);
const lateral = ref(null);
const navegacionModulos = ref(null);
async function mostrarModuloActivo() {
    await nextTick();
    const nav = navegacionModulos.value;
    const activo = nav?.querySelector(".is-active");
    if (nav && activo && nav.scrollWidth > nav.clientWidth) {
        nav.scrollLeft +=
            activo.getBoundingClientRect().left -
            nav.getBoundingClientRect().left -
            (nav.clientWidth - activo.clientWidth) / 2;
    }
}
watch(esPerfil, mostrarModuloActivo);
const iniciales = computed(() =>
    (page.props.auth.user?.name || "Usuario")
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((n) => n[0])
        .join("")
        .toUpperCase(),
);
const modulos = [
    { nombre: "Mi Perfil", icono: "👤", href: "/profile", perfil: true },
    { nombre: "Cartera", icono: "💳" },
    { nombre: "Tienda", icono: "🛍️" },
    { nombre: "Mis Productos", icono: "📦" },
    { nombre: "Servicios", icono: "🎓" },
    { nombre: "Comunidad", icono: "👥", href: "/modulo6" },
    { nombre: "Recompensas", icono: "🎁" },
];
const enlaces = computed(() =>
    esPerfil.value
        ? [
              { nombre: "Perfil", icono: "👤", href: "/profile", activo: currentUrl.value === "/profile" },
              { nombre: "Autenticación", icono: "🔐", href: "/profile/authentication" },
              { nombre: "Mi condición", icono: "🎓", href: "/student-services" },
              { nombre: "Identidad QR", icono: "📱", href: "/identidad/qr" },
              { nombre: "Dispositivos y sesiones", icono: "💻", href: "/seguridad/dispositivos" },
              { nombre: "Tarjetas NFC", icono: "💳", href: "/nfc-cards" },
              ...(page.props.auth.user?.roles?.some(r => ['admin', 'maestro', 'student_manager'].includes(r.name) && !r.scope_type) ? [{ nombre: "Estudiantes", icono: "👥", href: "/students" }] : []),
              { nombre: "Roles y permisos", icono: "🛡️", href: "/roles" },
          ]
        : [
              {
                  nombre: "Resumen",
                  icono: "🏠",
                  href: "/modulo6",
                  activo: currentUrl.value === "/modulo6",
              },
              {
                  nombre: "Organizaciones",
                  icono: "👥",
                  href: "/modulo6/asociacion",
              },
              ...(page.props.auth.gestiona_organizaciones
                  ? [
                        {
                            nombre: "Gestión de organizaciones",
                            icono: "🏛️",
                            href: "/modulo6/gestion-organizaciones",
                        },
                    ]
                  : []),
              {
                  nombre: "Eventos y actividades",
                  icono: "🎟️",
                  href: "/modulo6/eventos",
                  activo: currentUrl.value === "/modulo6/eventos",
              },
              ...(page.props.auth.organizacion
                  ? [
                        {
                            nombre: "Staff · Control de acceso",
                            icono: "📱",
                            href: "/modulo6/staff",
                        },
                    ]
                  : []),
              {
                  nombre: "Mis boletos",
                  icono: "🎫",
                  href: "/modulo6/mis-boletos",
                  activo:
                      currentUrl.value === "/modulo6/mis-boletos" ||
                      /^\/modulo6\/eventos\/[^/]+\/boleto$/.test(
                          currentUrl.value,
                      ),
              },
              { nombre: "Becas y apoyos", icono: "🎓", href: "/modulo6/becas" },
              {
                  nombre: "Comunicación",
                  icono: "📢",
                  href: "/modulo6/comunicacion",
              },
              { nombre: "Mi bandeja", icono: "📩", href: "/modulo6/bandeja" },
              { nombre: "Encuestas", icono: "📋", href: "/modulo6/encuestas" },
              {
                  nombre: "Votaciones",
                  icono: "🗳️",
                  href: "/modulo6/votaciones",
              },
              {
                  nombre: "Transparencia",
                  icono: "📊",
                  href: "/modulo6/transparencia",
              },
          ],
);
const esActivo = (enlace) =>
    enlace.activo ?? currentUrl.value.startsWith(enlace.href);
watch(
    () => page.url,
    () => {
        menuAbierto.value = false;
        cuentaAbierta.value = false;
    },
);
watch(menuAbierto, async (abierto) => {
    await nextTick();
    if (abierto) lateral.value?.querySelector("a")?.focus();
    else botonMenu.value?.focus();
});
function tecladoMenu(event) {
    if (!menuAbierto.value || event.key !== "Tab") return;
    const controles = [...lateral.value.querySelectorAll("a, button")];
    const primero = controles[0],
        ultimo = controles.at(-1);
    if (event.shiftKey && document.activeElement === primero) {
        event.preventDefault();
        ultimo?.focus();
    } else if (!event.shiftKey && document.activeElement === ultimo) {
        event.preventDefault();
        primero?.focus();
    }
}
watchEffect(() => {
    const id = page.props.auth.organizacion?.id;
    if (id) axios.defaults.headers.common["X-Organization-Id"] = id;
    else delete axios.defaults.headers.common["X-Organization-Id"];
});

const mostrarNotificaciones = ref(false);
const notificaciones = ref([]);
const noLeidas = ref(0);
let timerNotificaciones;
let heartbeatTimer;
async function checkHeartbeat() {
    try {
        const response = await axios.get('/seguridad/latido', {headers: {Accept: 'application/json'}, validateStatus: () => true});
        if ([401, 419].includes(response.status) || (response.status === 200 && response.data?.ok !== true)) {
            window.location.assign('/login');
        }
    } catch { /* Retry on the next heartbeat after a network interruption. */ }
}

const cargarNotificaciones = async () => {
    try {
        const respuesta = await axios.get("/api/notificaciones");
        notificaciones.value = respuesta.data;
        noLeidas.value = Number(respuesta.headers["x-unread-count"] || 0);
    } catch (error) {
        console.error("Error al cargar notificaciones:", error);
    }
};

onMounted(() => {
    heartbeatTimer = setInterval(checkHeartbeat, 8000);
    mostrarModuloActivo();
    window.addEventListener("resize", mostrarModuloActivo);
    cargarNotificaciones();
    window.addEventListener("bandeja-actualizada", cargarNotificaciones);
    timerNotificaciones = setInterval(() => {
        if (!document.hidden) cargarNotificaciones();
    }, 15000);
});
onBeforeUnmount(() => {
    window.removeEventListener("resize", mostrarModuloActivo);
    clearInterval(timerNotificaciones);
    clearInterval(heartbeatTimer);
    window.removeEventListener("bandeja-actualizada", cargarNotificaciones);
});

const marcarComoLeidas = async () => {
    try {
        await axios.put("/api/notificaciones/leer");
        await cargarNotificaciones();
    } catch (error) {
        console.error("Error al actualizar notificaciones:", error);
    }
};

const eliminarLeidas = async () => {
    if (
        confirm(
            "¿Estás seguro de que deseas limpiar todas las notificaciones leídas?",
        )
    ) {
        try {
            await axios.delete("/api/notificaciones/leidas");
            await cargarNotificaciones();
        } catch (error) {
            console.error("Error al eliminar notificaciones:", error);
        }
    }
};
</script>

<template>
    <div
        class="campus-shell"
        @keydown.esc="
            menuAbierto = false;
            cuentaAbierta = false;
        "
    >
        <a href="#contenido-principal" class="campus-skip">Ir al contenido</a>
        <button
            v-if="menuAbierto"
            class="campus-sidebar-backdrop"
            aria-label="Cerrar menú"
            @click="menuAbierto = false"
        ></button>
        <aside
            id="navegacion-lateral"
            ref="lateral"
            class="campus-sidebar"
            :class="{ 'is-open': menuAbierto }"
            @keydown="tecladoMenu"
        >
            <Link
                href="/modulo6"
                class="campus-brand-link"
                aria-label="Campus Digital · Inicio"
                ><CampusBrand
            /></Link>
            <nav
                aria-label="Navegación de la sección"
                class="campus-section-nav"
                @click="menuAbierto = false"
            >
                <p class="campus-section-label">
                    {{ esPerfil ? "Identidad" : "Comunidad" }}
                </p>
                <Link
                    v-for="enlace in enlaces"
                    :key="enlace.href"
                    :href="enlace.href"
                    class="campus-sidebar-link"
                    :class="{ 'is-active': esActivo(enlace) }"
                    :aria-current="esActivo(enlace) ? 'page' : undefined"
                >
                    <span aria-hidden="true" class="campus-nav-icon">{{
                        enlace.icono
                    }}</span>
                    <span>{{ enlace.nombre }}</span>
                </Link>
            </nav>
            <button class="campus-sidebar-close" @click="menuAbierto = false">
                Cerrar menú
            </button>
        </aside>
        <div class="campus-workspace">
            <header class="campus-topbar">
                <button
                    ref="botonMenu"
                    class="campus-menu-toggle"
                    aria-label="Abrir menú"
                    aria-controls="navegacion-lateral"
                    :aria-expanded="menuAbierto"
                    @click="menuAbierto = !menuAbierto"
                >
                    ☰
                </button>
                <span class="campus-mobile-title">Campus Digital</span>
                <nav
                    ref="navegacionModulos"
                    class="campus-modules"
                    aria-label="Módulos de Campus Digital"
                >
                    <Link
                        href="/modulo6"
                        class="campus-home"
                        aria-label="Inicio de Campus Digital"
                        >🏠</Link
                    >
                    <template v-for="modulo in modulos" :key="modulo.nombre">
                        <Link
                            v-if="modulo.href"
                            :href="modulo.href"
                            class="campus-module"
                            :class="{
                                'is-active': modulo.perfil
                                    ? esPerfil
                                    : !esPerfil,
                            }"
                            :aria-current="
                                (modulo.perfil ? esPerfil : !esPerfil)
                                    ? 'true'
                                    : undefined
                            "
                        >
                            <span aria-hidden="true">{{ modulo.icono }}</span
                            >{{ modulo.nombre }}
                        </Link>
                        <span
                            v-else
                            class="campus-module is-unavailable"
                            role="link"
                            aria-disabled="true"
                            :aria-label="`${modulo.nombre}: pendiente de integración`"
                            title="Pendiente de integración"
                        >
                            <span aria-hidden="true">{{ modulo.icono }}</span
                            >{{ modulo.nombre }}
                        </span>
                    </template>
                </nav>
                <div class="campus-account-actions">
                    <slot name="headerActions" />
                    <button
                        :aria-label="`Notificaciones: ${noLeidas} sin leer`"
                        @click="mostrarNotificaciones = true"
                        class="campus-notifications"
                    >
                        <span aria-hidden="true">🔔</span
                        ><span
                            v-if="noLeidas > 0"
                            class="campus-notification-dot"
                        ></span>
                    </button>
                    <div class="campus-account">
                        <button
                            class="campus-account-button"
                            :aria-expanded="cuentaAbierta"
                            aria-controls="menu-cuenta"
                            aria-label="Menú de cuenta"
                            @click="cuentaAbierta = !cuentaAbierta"
                        >
                            <span class="campus-avatar">{{ iniciales }}</span>
                            <span class="campus-account-info"
                                ><strong>{{
                                    page.props.auth.user?.name
                                }}</strong
                                ><small>{{
                                    page.props.auth.user?.matricula ||
                                    "Mi cuenta"
                                }}</small></span
                            >
                        </button>
                        <template v-if="cuentaAbierta">
                            <button
                                class="campus-account-backdrop"
                                aria-label="Cerrar menú de cuenta"
                                @click="cuentaAbierta = false"
                            ></button>
                            <div id="menu-cuenta" class="campus-account-menu">
                                <p>{{ page.props.auth.user?.email }}</p>
                                <Link href="/profile">Mi perfil</Link>
                                <Link
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    >Cerrar sesión</Link
                                >
                            </div>
                        </template>
                    </div>
                </div>
            </header>
            <main
                id="contenido-principal"
                tabindex="-1"
                class="campus-content"
                :aria-label="headerTitle"
            >
                <div v-if="page.props.flash?.error || page.props.flash?.success || page.props.flash?.status" role="status" class="mx-6 mt-4 rounded-lg border bg-white p-4 text-sm">
                    {{ page.props.flash.error || page.props.flash.success || page.props.flash.status }}
                </div>
                <slot />
            </main>
        </div>
        <Panellateral
            :show="mostrarNotificaciones"
            titulo="Centro de Notificaciones"
            @close="mostrarNotificaciones = false"
        >
            <div class="space-y-4">
                <div
                    v-for="noti in notificaciones"
                    :key="noti.id"
                    :class="
                        noti.leida
                            ? 'bg-white border-gray-200'
                            : 'bg-blue-50 border-blue-200'
                    "
                    class="p-4 rounded-lg border shadow-sm relative"
                >
                    <div
                        v-if="!noti.leida"
                        class="absolute top-4 right-4 w-2 h-2 bg-blue-600 rounded-full"
                    ></div>
                    <p class="font-bold text-sm text-gray-800 pr-4">
                        {{ noti.titulo }}
                    </p>
                    <p class="text-xs text-gray-600 mt-1">
                        {{ noti.detalle }}
                    </p>
                    <Link
                        :href="`/modulo6/bandeja?mensaje=${noti.id}`"
                        class="block mt-2 text-sm font-semibold text-blue-800"
                        >Abrir mensaje</Link
                    >
                    <p
                        class="text-[10px] text-gray-400 mt-2 uppercase font-bold tracking-wider"
                    >
                        {{ noti.tiempo }}
                    </p>
                </div>

                <div
                    v-if="notificaciones.length === 0"
                    class="text-center text-gray-500 text-sm py-4"
                >
                    No tienes notificaciones recientes.
                </div>
            </div>

            <template #footer>
                <div class="flex flex-wrap gap-2 w-full">
                    <Link
                        href="/modulo6/bandeja"
                        class="w-full text-center text-blue-800 font-semibold py-2"
                        >Ver toda la bandeja</Link
                    >
                    <button
                        @click="marcarComoLeidas"
                        class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-bold hover:bg-gray-200 transition"
                    >
                        Marcar leídas
                    </button>
                    <button
                        @click="eliminarLeidas"
                        title="Limpiar leídas"
                        class="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-bold hover:bg-red-100 transition"
                    >
                        🗑️
                    </button>
                </div>
            </template>
        </Panellateral>
    </div>
</template>
