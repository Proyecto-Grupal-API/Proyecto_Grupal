<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import CondicionIdentidad from "@/Components/CondicionIdentidad.vue";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
const page = usePage(),
    datos = ref(null),
    cargando = ref(false),
    error = ref("");
const fecha = (v) =>
    new Date(v).toLocaleString("es-MX", {
        timeZone: "America/Mexico_City",
        dateStyle: "medium",
        timeStyle: "short",
    });
async function cargar() {
    if (cargando.value || !page.props.auth.organizacion) return;
    cargando.value = true;
    error.value = "";
    try {
        datos.value = (await axios.get("/api/dashboard")).data;
    } catch (e) {
        error.value =
            e.response?.data?.message ||
            "No se pudieron cargar las cifras. Intenta de nuevo.";
    } finally {
        cargando.value = false;
    }
}
onMounted(cargar);
const tarjetas = computed(() =>
    datos.value
        ? [
              {
                  titulo: "Integrantes activos",
                  valor: datos.value.miembrosActivos,
                  href: "/modulo6/asociacion",
              },
              {
                  titulo: "Eventos próximos o en curso",
                  valor: datos.value.eventosActivos,
                  href: "/modulo6/eventos",
              },
              {
                  titulo: datos.value.puede_gestionar
                      ? "Solicitudes por revisar"
                      : "Mis solicitudes en revisión",
                  valor: datos.value.becasPendientes,
                  href: "/modulo6/becas",
              },
              {
                  titulo: "Mis mensajes sin leer",
                  valor: datos.value.personales.no_leidos,
                  href: "/modulo6/bandeja",
              },
          ]
        : [],
);
const ocupacion = computed(() =>
    datos.value?.proximoEvento?.capacidad
        ? Math.min(
              100,
              Math.round(
                  (datos.value.proximoEvento.reservas /
                      datos.value.proximoEvento.capacidad) *
                      100,
              ),
          )
        : 0,
);
</script>
<template>
    <Head title="Dashboard — Campus Digital" /><Modulo6Layout
        headerTitle="Panel de Comunidad"
        ><div class="campus-page space-y-6">
            <CondicionIdentidad />
            <div class="flex flex-wrap justify-between gap-3 items-center">
                <div>
                    <h1 class="text-2xl font-bold">
                        {{ datos?.organizacion || "Tu comunidad" }}
                    </h1>
                    <p v-if="datos" class="text-sm text-gray-500 mt-1">
                        Actualizado: {{ fecha(datos.generado_en) }}
                    </p>
                </div>
                <button
                    v-if="page.props.auth.organizacion"
                    @click="cargar"
                    :disabled="cargando"
                    class="rounded-lg border bg-white px-4 py-2"
                >
                    {{ cargando ? "Actualizando…" : "Actualizar cifras" }}
                </button>
            </div>
            <p
                v-if="!page.props.auth.organizacion"
                class="border rounded-xl bg-white p-6"
            >
                Necesitas una membresía activa para consultar el panel de una
                organización.
                <Link href="/modulo6/bandeja" class="text-blue-800 underline"
                    >Ir a mi bandeja</Link
                >
            </p>
            <p
                v-if="error"
                role="alert"
                class="bg-red-50 text-red-800 p-4 rounded-lg"
            >
                {{ error }}
            </p>
            <p v-if="cargando && !datos">Cargando indicadores…</p>
            <template v-if="datos"
                ><div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <Link
                        v-for="t in tarjetas"
                        :key="t.titulo"
                        :href="t.href"
                        class="bg-white border rounded-xl p-5 hover:border-blue-400"
                        ><p class="text-sm text-gray-600">{{ t.titulo }}</p>
                        <p class="text-3xl font-bold text-blue-950 mt-2">
                            {{ t.valor }}
                        </p></Link
                    >
                </div>
                <div class="grid lg:grid-cols-2 gap-5">
                    <section class="border bg-white rounded-xl p-5 space-y-4">
                        <h2 class="text-xl font-bold">
                            {{
                                datos.proximoEvento?.en_curso
                                    ? "Evento en curso"
                                    : "Próximo evento"
                            }}
                        </h2>
                        <template v-if="datos.proximoEvento"
                            ><h3 class="font-semibold break-words">
                                {{ datos.proximoEvento.nombre }}
                            </h3>
                            <p class="text-sm text-gray-600">
                                {{ datos.proximoEvento.lugar }}<br />{{
                                    fecha(datos.proximoEvento.fecha)
                                }}
                                · Ciudad de México
                            </p>
                            <p class="text-sm">
                                {{ datos.proximoEvento.reservas }} reservas
                                confirmadas de
                                {{ datos.proximoEvento.capacidad }} lugares ·
                                {{ ocupacion }}%
                            </p>
                            <div
                                class="bg-gray-100 h-2 rounded-full overflow-hidden"
                            >
                                <div
                                    class="bg-blue-800 h-2"
                                    :style="{ width: ocupacion + '%' }"
                                ></div>
                            </div>
                            <p class="text-sm text-gray-600">
                                {{
                                    datos.proximoEvento.asistencias
                                }}
                                asistencias registradas por check-in.
                            </p></template
                        >
                        <p v-else class="text-gray-500">
                            No hay eventos próximos ni en curso.
                        </p>
                        <Link
                            href="/modulo6/eventos"
                            class="inline-block text-blue-800 font-semibold"
                            >{{
                                datos.puede_gestionar
                                    ? "Gestionar eventos y asistencia"
                                    : "Explorar eventos"
                            }}</Link
                        >
                    </section>
                    <section class="bg-white border rounded-xl p-5 space-y-4">
                        <div class="flex justify-between gap-3">
                            <h2 class="text-xl font-bold">
                                {{
                                    datos.puede_gestionar
                                        ? "Solicitudes recientes"
                                        : "Mis solicitudes recientes"
                                }}
                            </h2>
                            <Link
                                href="/modulo6/becas"
                                class="text-blue-800 shrink-0"
                                >Ver becas</Link
                            >
                        </div>
                        <ul class="divide-y">
                            <li
                                v-for="s in datos.ultimasBecas"
                                :key="s.id"
                                class="py-3"
                            >
                                <Link
                                    :href="`/modulo6/becas/solicitudes/${s.id}`"
                                    class="font-semibold text-blue-800 break-words"
                                    >{{ s.beca }}</Link
                                >
                                <p class="text-sm text-gray-500">
                                    {{ s.folio || "Solicitud sin folio" }} ·
                                    {{ s.estado.replaceAll("_", " ") }}
                                </p>
                            </li>
                        </ul>
                        <p
                            v-if="!datos.ultimasBecas.length"
                            class="text-gray-500"
                        >
                            No hay solicitudes para mostrar.
                        </p>
                        <p class="text-xs text-gray-500">
                            {{
                                datos.puede_gestionar
                                    ? "Solo se incluyen expedientes enviados. Los borradores de estudiantes son privados."
                                    : "Esta lista incluye únicamente tus solicitudes en la organización seleccionada."
                            }}
                        </p>
                    </section>
                </div>
                <section class="border rounded-xl bg-white p-5 space-y-4">
                    <h2 class="text-xl font-bold">Tu participación</h2>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <Link
                            href="/modulo6/encuestas"
                            class="rounded-lg bg-blue-50 p-4 text-blue-950"
                            >{{
                                datos.personales.consultas.encuestas
                            }}
                            encuestas abiertas por responder</Link
                        ><Link
                            href="/modulo6/votaciones"
                            class="rounded-lg bg-blue-50 p-4 text-blue-950"
                            >{{
                                datos.personales.consultas.votaciones
                            }}
                            votaciones abiertas por responder</Link
                        ><Link
                            href="/modulo6/mis-boletos"
                            class="rounded-lg bg-blue-50 p-4 text-blue-950"
                            >{{ datos.personales.boletos }} reservas o lugares
                            en espera para eventos próximos o en curso</Link
                        >
                    </div>
                </section>
                <section
                    v-if="datos.gestion"
                    class="border rounded-xl bg-white p-5 space-y-4"
                >
                    <h2 class="text-xl font-bold">Pendientes de presidencia</h2>
                    <div class="flex flex-wrap gap-4">
                        <Link href="/modulo6/comunicacion" class="text-blue-800"
                            >{{ datos.gestion.campanas_pendientes }} campañas
                            con entregas pendientes</Link
                        ><Link
                            href="/modulo6/transparencia"
                            class="text-blue-800"
                            >{{ datos.gestion.reportes_borrador }} reportes por
                            revisar</Link
                        >
                    </div>
                </section>
                <p class="text-sm text-gray-500">
                    Caja disponible y entrega de apoyos: integración pendiente
                    con los equipos correspondientes. Las aprobaciones locales
                    no confirman pagos ni servicios entregados.
                </p>
            </template>
        </div></Modulo6Layout
    >
</template>
