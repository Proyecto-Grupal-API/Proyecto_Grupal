<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { ref, onMounted, onBeforeUnmount } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
const datos = ref(null),
    seleccionado = ref(null),
    prefs = ref(null),
    filtro = ref("recibidos"),
    buscar = ref(""),
    error = ref(""),
    aviso = ref(""),
    cargando = ref(false),
    ocupado = ref(false),
    preferencias = ref(false);
let timer;
function fallar(e) {
    error.value =
        Object.values(e.response?.data?.errors ?? {})
            .flat()
            .join(" ") ||
        e.response?.data?.message ||
        "No fue posible cargar los mensajes.";
}
const fecha = (v) =>
    v
        ? new Date(v).toLocaleString("es-MX", {
              timeZone: "America/Mexico_City",
          })
        : "";
const notificar = () => window.dispatchEvent(new Event("bandeja-actualizada"));
async function cargar(p = datos.value?.page ?? 1, silencioso = false) {
    if (cargando.value) return;
    cargando.value = true;
    if (!silencioso) error.value = "";
    try {
        datos.value = (
            await axios.get("/api/bandeja", {
                params: { page: p, filtro: filtro.value, buscar: buscar.value },
            })
        ).data;
    } catch (e) {
        if (!silencioso) fallar(e);
    } finally {
        cargando.value = false;
    }
}
async function abrir(id) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    try {
        seleccionado.value = (await axios.get(`/api/bandeja/${id}`)).data;
        if (!seleccionado.value.leido_en) {
            await axios.put(`/api/bandeja/${id}`, { leida: true });
            seleccionado.value.leido_en = new Date().toISOString();
            notificar();
            await cargar();
        }
    } catch (e) {
        fallar(e);
        seleccionado.value = null;
    } finally {
        ocupado.value = false;
    }
}
async function actualizar(d) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    try {
        await axios.put(`/api/bandeja/${seleccionado.value.id}`, d);
        seleccionado.value = (
            await axios.get(`/api/bandeja/${seleccionado.value.id}`)
        ).data;
        await cargar();
        notificar();
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
async function configurar() {
    preferencias.value = !preferencias.value;
    error.value = "";
    try {
        if (preferencias.value)
            prefs.value = (await axios.get("/api/bandeja/preferencias")).data;
    } catch (e) {
        fallar(e);
    }
}
async function silenciar(o) {
    ocupado.value = true;
    error.value = "";
    try {
        const { data } = await axios.put(`/api/bandeja/preferencias/${o.id}`, {
            silenciada: !o.silenciada,
        });
        o.silenciada = !o.silenciada;
        aviso.value = data.message;
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
onMounted(async () => {
    await cargar();
    const id = new URLSearchParams(window.location.search).get("mensaje");
    if (id && /^[a-f0-9]{24}$/i.test(id)) await abrir(id);
    timer = setInterval(() => {
        if (!document.hidden && !ocupado.value) cargar(undefined, true);
    }, 15000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
<template>
    <Head title="Mi bandeja — Campus Digital" />
    <Modulo6Layout headerTitle="Mi bandeja"
        ><div class="campus-page space-y-5">
            <div class="flex flex-wrap justify-between gap-3 items-center">
                <div>
                    <h1 class="text-2xl font-bold">Tus mensajes</h1>
                    <p class="text-sm text-gray-500">
                        {{ datos?.no_leidos ?? 0 }} sin leer en Recibidos
                    </p>
                </div>
                <button
                    @click="configurar"
                    class="rounded-lg border bg-white px-4 py-2"
                >
                    {{ preferencias ? "Cerrar preferencias" : "Preferencias" }}
                </button>
            </div>
            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 text-red-800 p-4"
            >
                {{ error }}
            </p>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 text-green-800 p-4"
            >
                {{ aviso }}
            </p>
            <section
                v-if="preferencias"
                class="rounded-xl border bg-white p-5 space-y-3"
            >
                <h2 class="text-lg font-semibold">Campañas por organización</h2>
                <p class="text-sm text-gray-600">
                    Silenciar evita futuras campañas de esa organización. Tus
                    mensajes anteriores se conservan.
                </p>
                <div
                    v-for="o in prefs"
                    :key="o.id"
                    class="flex flex-wrap justify-between items-center gap-3 border-t py-3"
                >
                    <span>{{ o.nombre }}</span
                    ><button
                        @click="silenciar(o)"
                        :disabled="ocupado"
                        class="text-blue-800 font-semibold"
                    >
                        {{ o.silenciada ? "Volver a recibir" : "Silenciar" }}
                    </button>
                </div>
            </section>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="f in [
                        { id: 'recibidos', nombre: 'Recibidos' },
                        { id: 'no_leidos', nombre: 'Sin leer' },
                        { id: 'importantes', nombre: 'Importantes' },
                        { id: 'archivados', nombre: 'Archivados' },
                    ]"
                    :key="f.id"
                    @click="
                        filtro = f.id;
                        cargar(1);
                    "
                    :disabled="cargando"
                    :class="
                        filtro === f.id ? 'bg-blue-900 text-white' : 'bg-white'
                    "
                    class="rounded-lg border px-4 py-2"
                >
                    {{ f.nombre }}
                </button>
            </div>
            <form @submit.prevent="cargar(1)" class="flex gap-3">
                <input
                    v-model="buscar"
                    aria-label="Buscar mensajes"
                    placeholder="Buscar por asunto"
                    maxlength="120"
                    class="rounded-lg border-gray-300 flex-1 min-w-0"
                /><button
                    :disabled="cargando"
                    class="rounded-lg border bg-white px-4"
                >
                    Buscar
                </button>
            </form>
            <div class="grid lg:grid-cols-2 gap-5 items-start">
                <section class="space-y-3">
                    <p v-if="cargando && !datos">Cargando mensajes…</p>
                    <button
                        v-for="m in datos?.mensajes"
                        :key="m.id"
                        @click="abrir(m.id)"
                        :disabled="ocupado"
                        class="rounded-xl border bg-white p-5 w-full text-left space-y-2"
                        :class="
                            seleccionado?.id === m.id
                                ? 'border-blue-500 ring-1 ring-blue-500'
                                : ''
                        "
                    >
                        <span class="block text-xs text-gray-500"
                            >{{ m.emisor_nombre || "Campus Digital" }} ·
                            {{ fecha(m.creado_en) }}</span
                        ><span
                            class="block break-words"
                            :class="
                                !m.leido_en
                                    ? 'font-bold text-blue-950'
                                    : 'font-medium text-gray-700'
                            "
                            >{{ m.asunto }}</span
                        ><span class="block text-xs text-gray-500"
                            >{{ !m.leido_en ? "Sin leer" : "Leído"
                            }}{{ m.importante ? " · Importante" : "" }}</span
                        >
                    </button>
                    <p
                        v-if="datos && !datos.mensajes.length && !cargando"
                        class="rounded-xl border bg-white p-8 text-center text-gray-500"
                    >
                        No hay mensajes en esta vista.
                    </p>
                    <div
                        v-if="datos?.last_page > 1"
                        class="flex justify-center gap-4"
                    >
                        <button
                            :disabled="datos.page === 1 || cargando"
                            @click="cargar(datos.page - 1)"
                        >
                            Anterior</button
                        ><span>{{ datos.page }} / {{ datos.last_page }}</span
                        ><button
                            :disabled="
                                datos.page === datos.last_page || cargando
                            "
                            @click="cargar(datos.page + 1)"
                        >
                            Siguiente
                        </button>
                    </div>
                </section>
                <article
                    v-if="seleccionado"
                    class="rounded-xl border bg-white p-5 md:p-6 space-y-5 min-w-0"
                >
                    <div class="flex justify-between gap-3">
                        <span class="text-sm text-gray-500">{{
                            seleccionado.emisor_nombre || "Campus Digital"
                        }}</span
                        ><button
                            @click="seleccionado = null"
                            aria-label="Cerrar mensaje"
                        >
                            Cerrar
                        </button>
                    </div>
                    <h2 class="text-xl font-bold break-words">
                        {{ seleccionado.asunto }}
                    </h2>
                    <p class="whitespace-pre-line break-words text-gray-700">
                        {{ seleccionado.cuerpo }}
                    </p>
                    <Link
                        v-if="seleccionado.accion_url"
                        :href="seleccionado.accion_url"
                        class="inline-block rounded-lg bg-blue-900 text-white px-4 py-2"
                        >{{ seleccionado.texto_accion || "Abrir" }}</Link
                    >
                    <div
                        class="flex flex-wrap gap-3 border-t pt-4 text-sm font-semibold"
                    >
                        <button
                            @click="
                                actualizar({ leida: !seleccionado.leido_en })
                            "
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            {{
                                seleccionado.leido_en
                                    ? "Marcar sin leer"
                                    : "Marcar leído"
                            }}</button
                        ><button
                            @click="
                                actualizar({
                                    importante: !seleccionado.importante,
                                })
                            "
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            {{
                                seleccionado.importante
                                    ? "Quitar importante"
                                    : "Marcar importante"
                            }}</button
                        ><button
                            @click="
                                actualizar({
                                    archivada: !seleccionado.archivado_en,
                                })
                            "
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            {{
                                seleccionado.archivado_en
                                    ? "Mover a recibidos"
                                    : "Archivar"
                            }}
                        </button>
                    </div>
                </article>
            </div>
        </div></Modulo6Layout
    >
</template>
