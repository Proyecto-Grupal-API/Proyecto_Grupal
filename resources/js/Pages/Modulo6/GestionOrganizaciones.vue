<script setup>
import { Head } from "@inertiajs/vue3";
import { ref, onMounted, watch } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
const datos = ref(null),
    cargando = ref(false),
    ocupado = ref(false),
    buscandoTitular = ref(false);
const error = ref(""),
    errores = ref({}),
    aviso = ref(""),
    buscar = ref(""),
    filtro = ref("");
const panel = ref(null),
    form = ref({}),
    titular = ref(null),
    detalle = ref(null),
    cambio = ref({});
let consulta = 0;
const estados = {
    activa: "Activa",
    suspendida: "Suspendida",
    configurando: "Alta pendiente",
};
const fecha = (v) =>
    v
        ? new Intl.DateTimeFormat("es-MX", {
              dateStyle: "medium",
              timeZone: "UTC",
          }).format(new Date(v))
        : "Sin fecha de término";
function fallo(e) {
    errores.value = e.response?.data?.errors ?? {};
    error.value = [401, 419].includes(e.response?.status)
        ? "Tu sesión terminó. Inicia sesión nuevamente."
        : e.response?.status === 403
          ? "Tu cuenta ya no tiene permiso para gestionar organizaciones."
          : Object.keys(errores.value).length
            ? "Revisa los datos indicados."
            : e.response?.status === 409
              ? e.response.data.message
              : "No se pudo completar la operación. Puedes reintentar sin crear duplicados.";
}
async function cargar(page = 1) {
    cargando.value = true;
    try {
        datos.value = (
            await axios.get("/api/gestion-organizaciones", {
                params: { buscar: buscar.value, estado: filtro.value, page },
            })
        ).data;
    } catch (e) {
        fallo(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(() => cargar());
function alta() {
    error.value = "";
    errores.value = {};
    aviso.value = "";
    titular.value = null;
    form.value = {
        clave_alta: crypto.randomUUID(),
        nombre: "",
        tipo: "asociacion",
        descripcion: "",
        email: "",
        telefono: "",
        matricula_presidencia: "",
        fecha_fin_presidencia: "",
    };
    panel.value = "alta";
}
watch(
    () => form.value.matricula_presidencia,
    () => {
        consulta++;
        titular.value = null;
        buscandoTitular.value = false;
    },
);
async function consultarTitular() {
    const turno = ++consulta;
    buscandoTitular.value = true;
    error.value = "";
    errores.value = {};
    try {
        const r = await axios.post("/api/gestion-organizaciones/titular", {
            matricula: form.value.matricula_presidencia,
        });
        if (turno === consulta) titular.value = r.data.titular;
    } catch (e) {
        if (turno === consulta) fallo(e);
    } finally {
        if (turno === consulta) buscandoTitular.value = false;
    }
}
function cerrar() {
    if (ocupado.value) return;
    consulta++;
    panel.value = null;
    error.value = "";
    errores.value = {};
}
async function ver(o) {
    if (ocupado.value) return;
    error.value = "";
    errores.value = {};
    detalle.value = null;
    panel.value = "detalle";
    ocupado.value = true;
    try {
        detalle.value = (
            await axios.get(`/api/gestion-organizaciones/${o.id}`)
        ).data;
    } catch (e) {
        fallo(e);
    } finally {
        ocupado.value = false;
    }
}
function prepararEstado(o) {
    error.value = "";
    errores.value = {};
    cambio.value = {
        id: o.id,
        nombre: o.nombre,
        estado: o.estado === "activa" ? "suspendida" : "activa",
        version_estado: o.version_estado,
        clave_cambio: crypto.randomUUID(),
        motivo: "",
    };
    panel.value = "estado";
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    errores.value = {};
    aviso.value = "";
    try {
        const r = await fn();
        aviso.value = r.data.message;
        panel.value = null;
        await cargar(datos.value?.page ?? 1);
    } catch (e) {
        fallo(e);
    } finally {
        ocupado.value = false;
    }
}
function guardar() {
    if (
        !titular.value ||
        titular.value.matricula !== form.value.matricula_presidencia.trim()
    )
        return;
    ejecutar(() => axios.post("/api/gestion-organizaciones", form.value));
}
const guardarEstado = () =>
    ejecutar(() =>
        axios.post(
            `/api/gestion-organizaciones/${cambio.value.id}/estado`,
            cambio.value,
        ),
    );
const completar = (o) =>
    ejecutar(() => axios.post(`/api/gestion-organizaciones/${o.id}/completar`));
</script>
<template>
    <Head title="Gestión de organizaciones" />
    <Modulo6Layout headerTitle="Gestión de organizaciones">
        <div class="campus-page space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p
                        class="text-xs font-bold uppercase tracking-widest text-blue-700"
                    >
                        Registro de Comunidad
                    </p>
                    <h1 class="text-3xl font-bold text-slate-900 mt-2">
                        Organizaciones del campus
                    </h1>
                    <p class="mt-2 text-slate-600 max-w-2xl">
                        Da de alta asociaciones y consejos con una presidencia
                        responsable. Administra su estado sin perder su
                        trayectoria.
                    </p>
                </div>
                <button
                    @click="alta"
                    :disabled="ocupado"
                    class="rounded-xl bg-blue-900 text-white px-5 py-3 font-semibold"
                >
                    Nueva organización
                </button>
            </div>
            <p
                v-if="aviso"
                role="status"
                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900"
            >
                {{ aviso }}
            </p>
            <div
                v-if="error && !panel"
                role="alert"
                class="rounded-xl bg-red-50 p-4 text-red-900"
            >
                {{ error
                }}<button @click="cargar()" class="ml-3 underline">
                    Reintentar
                </button>
            </div>
            <form
                @submit.prevent="cargar()"
                class="flex flex-wrap gap-3 rounded-xl border bg-white p-4"
            >
                <label class="w-full sm:flex-1 sm:min-w-0 sm:w-auto"
                    ><span class="sr-only">Buscar organización</span
                    ><input
                        v-model="buscar"
                        maxlength="120"
                        placeholder="Buscar por nombre"
                        class="w-full rounded-lg border-slate-300" /></label
                ><label
                    ><span class="sr-only">Estado de la organización</span
                    ><select
                        v-model="filtro"
                        class="rounded-lg border-slate-300"
                    >
                        <option value="">Todos los estados</option>
                        <option
                            v-for="(nombre, estado) in estados"
                            :key="estado"
                            :value="estado"
                        >
                            {{ nombre }}
                        </option>
                    </select></label
                ><button
                    :disabled="cargando || ocupado"
                    class="rounded-lg border px-4 py-2 font-semibold"
                >
                    Buscar
                </button>
            </form>
            <p v-if="cargando" role="status" class="text-slate-600">
                Cargando organizaciones…
            </p>
            <p
                v-if="datos && !datos.organizaciones.length && !cargando"
                class="rounded-xl border bg-white p-8 text-center text-slate-600"
            >
                No hay organizaciones que coincidan con la búsqueda.
            </p>
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
                <article
                    v-for="o in datos?.organizaciones"
                    :key="o.id"
                    class="rounded-2xl border bg-white p-5 flex flex-col gap-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p
                            class="text-xs font-bold uppercase tracking-wide text-blue-800"
                        >
                            {{
                                o.tipo === "consejo"
                                    ? "Consejo estudiantil"
                                    : "Asociación"
                            }}
                        </p>
                        <span
                            :class="
                                o.estado === 'activa'
                                    ? 'bg-emerald-50 text-emerald-800'
                                    : o.estado === 'suspendida'
                                      ? 'bg-amber-50 text-amber-900'
                                      : 'bg-slate-100 text-slate-700'
                            "
                            class="rounded-full px-3 py-1 text-xs font-semibold"
                            >{{ estados[o.estado] ?? o.estado }}</span
                        >
                    </div>
                    <h2 class="text-xl font-bold text-slate-900">
                        {{ o.nombre }}
                    </h2>
                    <p class="text-sm text-slate-600 line-clamp-3">
                        {{ o.descripcion }}
                    </p>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p
                            class="text-xs font-semibold uppercase text-slate-500"
                        >
                            Presidencia
                        </p>
                        <p class="mt-1 font-semibold">
                            {{ o.presidencia?.nombre ?? "Por confirmar" }}
                        </p>
                        <p
                            v-if="o.presidencia"
                            class="text-sm mt-1"
                            :class="
                                o.presidencia.vigente
                                    ? 'text-slate-500'
                                    : 'text-amber-800'
                            "
                        >
                            {{
                                o.presidencia.vigente
                                    ? "Vigente"
                                    : "Requiere regularización"
                            }}
                            · {{ fecha(o.presidencia.fecha_fin) }}
                        </p>
                    </div>
                    <div
                        class="mt-auto flex flex-wrap gap-4 text-sm font-semibold"
                    >
                        <button
                            @click="ver(o)"
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            Ver ficha e historial</button
                        ><button
                            v-if="['activa', 'suspendida'].includes(o.estado)"
                            @click="prepararEstado(o)"
                            :disabled="ocupado"
                            :class="
                                o.estado === 'activa'
                                    ? 'text-amber-800'
                                    : 'text-emerald-800'
                            "
                        >
                            {{
                                o.estado === "activa"
                                    ? "Suspender"
                                    : "Reactivar"
                            }}</button
                        ><button
                            v-if="o.estado === 'configurando'"
                            @click="completar(o)"
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            Completar alta
                        </button>
                    </div>
                </article>
            </div>
            <div
                v-if="datos"
                class="flex justify-between items-center gap-4 text-sm text-slate-600"
            >
                <span>{{ datos.total }} organizaciones</span>
                <div v-if="datos.last_page > 1" class="flex gap-4">
                    <button
                        :disabled="cargando || datos.page === 1"
                        @click="cargar(datos.page - 1)"
                    >
                        Anterior</button
                    ><span>{{ datos.page }} / {{ datos.last_page }}</span
                    ><button
                        :disabled="cargando || datos.page === datos.last_page"
                        @click="cargar(datos.page + 1)"
                    >
                        Siguiente
                    </button>
                </div>
            </div>
        </div>
        <PanelLateral
            :show="!!panel"
            :titulo="
                panel === 'alta'
                    ? 'Nueva organización'
                    : panel === 'estado'
                      ? cambio.estado === 'suspendida'
                          ? 'Suspender organización'
                          : 'Reactivar organización'
                      : 'Ficha e historial'
            "
            @close="cerrar"
        >
            <div
                v-if="error"
                role="alert"
                class="rounded-xl bg-red-50 p-4 mb-5 text-red-900"
            >
                {{ error }}
                <ul class="list-disc pl-4 mt-2">
                    <li v-for="(mensajes, campo) in errores" :key="campo">
                        {{ mensajes[0] }}
                    </li>
                </ul>
            </div>
            <form
                v-if="panel === 'alta'"
                id="alta-org"
                @submit.prevent="guardar"
                class="space-y-4"
            >
                <p class="text-sm text-slate-600">
                    La organización quedará activa con su primera presidencia
                    asignada. El titular debe tener una cuenta y matrícula
                    registradas.
                </p>
                <div>
                    <label for="org-nombre" class="block font-semibold mb-1"
                        >Nombre</label
                    ><input
                        id="org-nombre"
                        v-model="form.nombre"
                        required
                        minlength="3"
                        maxlength="150"
                        class="w-full rounded-lg border-slate-300"
                    />
                </div>
                <div>
                    <label for="org-tipo" class="block font-semibold mb-1"
                        >Tipo</label
                    ><select
                        id="org-tipo"
                        v-model="form.tipo"
                        class="w-full rounded-lg border-slate-300"
                    >
                        <option value="asociacion">
                            Asociación estudiantil
                        </option>
                        <option value="consejo">Consejo estudiantil</option>
                    </select>
                </div>
                <div>
                    <label for="org-proposito" class="block font-semibold mb-1"
                        >Propósito y descripción</label
                    ><textarea
                        id="org-proposito"
                        v-model="form.descripcion"
                        required
                        minlength="20"
                        maxlength="2000"
                        rows="3"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea>
                </div>
                <div>
                    <label for="org-correo" class="block font-semibold mb-1"
                        >Correo de contacto</label
                    ><input
                        id="org-correo"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="255"
                        class="w-full rounded-lg border-slate-300"
                    />
                </div>
                <div>
                    <label for="org-telefono" class="block font-semibold mb-1"
                        >Teléfono (opcional)</label
                    ><input
                        id="org-telefono"
                        v-model="form.telefono"
                        maxlength="30"
                        class="w-full rounded-lg border-slate-300"
                    />
                </div>
                <fieldset class="rounded-xl border p-4 space-y-3">
                    <legend class="px-2 font-bold">Presidencia inicial</legend>
                    <label for="org-matricula" class="block font-semibold"
                        >Matrícula del titular</label
                    ><input
                        id="org-matricula"
                        v-model="form.matricula_presidencia"
                        required
                        maxlength="40"
                        class="w-full rounded-lg border-slate-300"
                    /><button
                        type="button"
                        @click="consultarTitular"
                        :disabled="
                            !form.matricula_presidencia ||
                            buscandoTitular ||
                            ocupado
                        "
                        class="text-blue-800 font-semibold"
                    >
                        {{
                            buscandoTitular
                                ? "Buscando…"
                                : "Consultar matrícula"
                        }}
                    </button>
                    <p
                        v-if="titular"
                        role="status"
                        class="rounded-lg bg-blue-50 p-3 text-blue-900"
                    >
                        Asignarás la presidencia a
                        <strong>{{ titular.name }}</strong> ({{
                            titular.matricula
                        }}).
                    </p>
                    <label for="org-fin" class="block font-semibold"
                        >Último día del cargo</label
                    ><input
                        id="org-fin"
                        v-model="form.fecha_fin_presidencia"
                        type="date"
                        required
                        class="w-full rounded-lg border-slate-300"
                    />
                    <p class="text-xs text-slate-500">
                        La vigencia inicia al completar el alta.
                    </p>
                </fieldset>
            </form>
            <form
                v-if="panel === 'estado'"
                id="estado-org"
                @submit.prevent="guardarEstado"
                class="space-y-5"
            >
                <h2 class="font-bold text-xl">{{ cambio.nombre }}</h2>
                <p class="text-slate-600">
                    {{
                        cambio.estado === "suspendida"
                            ? "Se bloqueará la operación de la organización, incluidas nuevas inscripciones y validación de accesos. Se conservarán sus integrantes, cargos, reservas y expedientes."
                            : "Volverá a operar con los cargos y fechas que sigan vigentes. Las campañas pausadas requieren revisión antes de reintentarse."
                    }}
                </p>
                <div>
                    <label for="org-motivo" class="block font-semibold mb-2"
                        >Motivo del cambio</label
                    ><textarea
                        id="org-motivo"
                        v-model="cambio.motivo"
                        minlength="10"
                        maxlength="500"
                        required
                        rows="4"
                        class="w-full rounded-lg border-slate-300"
                    ></textarea>
                </div>
                <p class="text-sm text-slate-500">
                    El cambio se registrará con tu usuario, fecha y motivo.
                </p>
            </form>
            <div v-if="panel === 'detalle'" class="space-y-5">
                <p v-if="!detalle" role="status">Cargando ficha…</p>
                <template v-else
                    ><h2 class="text-xl font-bold">
                        {{ detalle.organizacion.nombre }}
                    </h2>
                    <p class="text-slate-600 whitespace-pre-line">
                        {{ detalle.organizacion.descripcion }}
                    </p>
                    <p class="text-sm">
                        {{ detalle.organizacion.email
                        }}<span v-if="detalle.organizacion.telefono">
                            · {{ detalle.organizacion.telefono }}</span
                        >
                    </p>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="font-semibold">
                            Presidencia:
                            {{
                                detalle.organizacion.presidencia?.nombre ??
                                "Por confirmar"
                            }}
                        </p>
                        <p class="text-sm text-slate-600">
                            {{ detalle.organizacion.presidencia?.matricula }}
                        </p>
                    </div>
                    <h3 class="font-bold">Historial de estado</h3>
                    <p
                        v-if="!detalle.historial.length"
                        class="text-sm text-slate-500"
                    >
                        Organización anterior a este registro. Sus nuevos
                        cambios aparecerán aquí.
                    </p>
                    <ol class="space-y-4">
                        <li
                            v-for="h in [...detalle.historial].reverse()"
                            :key="h.id"
                            class="border-l-2 border-blue-200 pl-4"
                        >
                            <p class="font-semibold">
                                {{ estados[h.desde] ?? h.desde }} →
                                {{ estados[h.hasta] ?? h.hasta }}
                            </p>
                            <p class="text-sm mt-1 whitespace-pre-line">
                                {{ h.motivo }}
                            </p>
                            <p class="text-xs text-slate-500 mt-2">
                                {{
                                    new Date(h.fecha).toLocaleString("es-MX", {
                                        timeZone: "America/Mexico_City",
                                    })
                                }}
                                · Ciudad de México
                            </p>
                            <p class="text-xs text-slate-500">
                                Registrado por {{ h.actor_nombre }}
                            </p>
                        </li>
                    </ol></template
                >
            </div>
            <template #footer
                ><button
                    v-if="panel === 'alta'"
                    form="alta-org"
                    :disabled="ocupado || !titular || buscandoTitular"
                    class="rounded-lg bg-blue-900 px-4 py-3 font-semibold text-white disabled:opacity-40"
                >
                    {{
                        ocupado ? "Creando…" : "Crear y asignar presidencia"
                    }}</button
                ><button
                    v-if="panel === 'estado'"
                    form="estado-org"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 px-4 py-3 font-semibold text-white disabled:opacity-40"
                >
                    {{ ocupado ? "Guardando…" : "Confirmar cambio" }}
                </button></template
            >
        </PanelLateral>
    </Modulo6Layout>
</template>
