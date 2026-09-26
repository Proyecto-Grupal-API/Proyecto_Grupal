<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";
import EscanerEvento from "@/Components/EscanerEvento.vue";
import StaffEventoPanel from "@/Components/StaffEventoPanel.vue";
const staffEvento = ref(null);
const page = usePage();
const modo = ref(page.props.auth.puede_editar ? "gestion" : "catalogo");
const datos = ref(null),
    cargando = ref(false),
    ocupado = ref(false),
    error = ref(""),
    errores = ref({}),
    aviso = ref("");
const buscar = ref(""),
    estado = ref(""),
    panel = ref(false),
    formulario = ref({}),
    seleccionado = ref(null),
    lista = ref(null),
    paginaLista = ref(1),
    filtroLista = ref(""),
    token = ref("");
const puedeGestionar = computed(
    () => datos.value?.puede_gestionar ?? page.props.auth.puede_editar,
);
const fecha = (v) =>
    v
        ? new Intl.DateTimeFormat("es-MX", {
              dateStyle: "medium",
              timeStyle: "short",
              timeZone: "America/Mexico_City",
          }).format(new Date(v))
        : "";
const local = (v) =>
    new Date(v)
        .toLocaleString("sv-SE", { timeZone: "America/Mexico_City" })
        .replace(" ", "T")
        .slice(0, 16);
const dinero = (v) =>
    Number(v) === 0
        ? "Gratuito"
        : new Intl.NumberFormat("es-MX", {
              style: "currency",
              currency: "MXN",
          }).format(v);
const editable = (e) =>
    e.estado !== "cancelado" && new Date(e.fecha_hora_inicio) > new Date();
function fallar(e) {
    errores.value = e.response?.data?.errors ?? {};
    error.value = Object.keys(errores.value).length
        ? "Revisa los datos indicados."
        : e.response?.status >= 500
          ? "No fue posible completar la operación. Intenta nuevamente."
          : e.response?.data?.message ||
            "No fue posible conectar con el servidor.";
}
async function cargar(pagina = 1) {
    cargando.value = true;
    try {
        datos.value = (
            await axios.get("/api/eventos", {
                params: {
                    gestion: modo.value === "gestion" ? 1 : 0,
                    buscar: buscar.value,
                    estado: modo.value === "gestion" ? estado.value : "",
                    page: pagina,
                },
            })
        ).data;
        if (seleccionado.value)
            seleccionado.value =
                datos.value.eventos.find(
                    (e) => e.id === seleccionado.value.id,
                ) ?? null;
    } catch (e) {
        fallar(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(() => cargar());
async function cambiarModo(v) {
    modo.value = v;
    seleccionado.value = null;
    error.value = "";
    errores.value = {};
    await cargar();
}
function abrir(e = null) {
    error.value = "";
    errores.value = {};
    aviso.value = "";
    formulario.value = e
        ? {
              id: e.id,
              titulo: e.titulo,
              descripcion: e.descripcion,
              ubicacion: e.ubicacion,
              costo: e.costo,
              capacidad: e.capacidad,
              lista_espera: !!e.lista_espera,
              fecha_hora_inicio: local(e.fecha_hora_inicio),
              fecha_hora_fin: local(e.fecha_hora_fin),
              fecha_inicio_registro: local(e.fecha_inicio_registro),
              fecha_fin_registro: local(e.fecha_fin_registro),
          }
        : {
              titulo: "",
              descripcion: "",
              ubicacion: "",
              costo: 0,
              capacidad: 50,
              lista_espera: true,
              fecha_hora_inicio: "",
              fecha_hora_fin: "",
              fecha_inicio_registro: local(Date.now()),
              fecha_fin_registro: "",
          };
    panel.value = true;
}
async function ejecutar(fn) {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    errores.value = {};
    aviso.value = "";
    try {
        const res = await fn();
        aviso.value = res.data.message;
        panel.value = false;
        await cargar(datos.value?.page ?? 1);
        if (seleccionado.value) await cargarLista(paginaLista.value);
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
const guardar = () =>
    ejecutar(() =>
        formulario.value.id
            ? axios.put(`/api/eventos/${formulario.value.id}`, formulario.value)
            : axios.post("/api/eventos", formulario.value),
    );
const publicar = (e) =>
    ejecutar(() => axios.post(`/api/eventos/${e.id}/publicar`));
function cancelar(e) {
    const motivo = prompt("Motivo de cancelación (mínimo 10 caracteres):");
    if (motivo !== null)
        ejecutar(() => axios.post(`/api/eventos/${e.id}/cancelar`, { motivo }));
}
const inscribir = (e) =>
    ejecutar(() => axios.post(`/api/eventos/${e.id}/inscripcion`));
async function cargarLista(pagina = 1) {
    if (!seleccionado.value) return;
    paginaLista.value = pagina;
    try {
        lista.value = (
            await axios.get(`/api/eventos/${seleccionado.value.id}/inscritos`, {
                params: { page: pagina, estado: filtroLista.value },
            })
        ).data;
    } catch (e) {
        fallar(e);
    }
}
async function verLista(e) {
    seleccionado.value = e;
    lista.value = null;
    token.value = "";
    filtroLista.value = "";
    await cargarLista();
}
function checkin(codigo = token.value) {
    if (!codigo?.trim() || !seleccionado.value) return;
    token.value = codigo.trim();
    ejecutar(() =>
        axios.post("/api/eventos/checkin", {
            evento_id: seleccionado.value.id,
            token_qr: token.value,
        }),
    ).then(() => {
        token.value = "";
    });
}
function cerrar() {
    if (!ocupado.value) {
        panel.value = false;
        error.value = "";
        errores.value = {};
    }
}
</script>
<template>
    <Head title="Eventos — Campus Digital" />
    <Modulo6Layout headerTitle="Eventos y actividades">
        <div class="campus-page space-y-6">
            <div class="flex flex-wrap justify-between gap-4 items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Participa en tu campus
                    </h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Horarios de Ciudad de México ·
                        {{
                            page.props.auth.organizacion?.nombre ||
                            "Comunidad estudiantil"
                        }}
                    </p>
                </div>
                <div class="flex gap-3">
                    <Link
                        href="/modulo6/mis-boletos"
                        class="rounded-lg border px-4 py-2 bg-white text-sm font-semibold"
                        >Mis boletos</Link
                    ><button
                        v-if="modo === 'gestion' && puedeGestionar"
                        @click="abrir()"
                        :disabled="ocupado"
                        class="rounded-lg bg-blue-900 text-white px-4 py-2 text-sm font-semibold"
                    >
                        Crear evento
                    </button>
                </div>
            </div>
            <div class="flex gap-2">
                <button
                    @click="cambiarModo('catalogo')"
                    :disabled="ocupado || cargando"
                    :class="
                        modo === 'catalogo'
                            ? 'bg-blue-900 text-white'
                            : 'bg-white text-gray-700'
                    "
                    class="rounded-lg px-4 py-2 border"
                >
                    Explorar eventos</button
                ><button
                    v-if="puedeGestionar"
                    @click="cambiarModo('gestion')"
                    :disabled="ocupado || cargando"
                    :class="
                        modo === 'gestion'
                            ? 'bg-blue-900 text-white'
                            : 'bg-white text-gray-700'
                    "
                    class="rounded-lg px-4 py-2 border"
                >
                    Administrar organización
                </button>
            </div>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 border border-green-200 p-4 text-green-800"
            >
                {{ aviso }}
            </p>
            <div
                v-if="error && !panel"
                role="alert"
                class="rounded-lg bg-red-50 p-4 text-red-800"
            >
                {{ error }}
                <ul class="list-disc pl-5">
                    <li v-for="(mensajes, campo) in errores" :key="campo">
                        {{ mensajes[0] }}
                    </li>
                </ul>
            </div>
            <form @submit.prevent="cargar()" class="flex flex-wrap gap-3">
                <label class="flex-1 min-w-40"
                    ><span class="sr-only">Buscar eventos</span
                    ><input
                        v-model="buscar"
                        placeholder="Buscar por título"
                        maxlength="120"
                        class="w-full rounded-lg border-gray-300" /></label
                ><select
                    v-if="modo === 'gestion'"
                    v-model="estado"
                    aria-label="Estado del evento"
                    class="rounded-lg border-gray-300"
                >
                    <option value="">Todos los estados</option>
                    <option value="borrador">Borradores</option>
                    <option value="publicado">Publicados</option>
                    <option value="cancelado">Cancelados</option></select
                ><button
                    :disabled="cargando"
                    class="rounded-lg border px-4 py-2 bg-white"
                >
                    Buscar
                </button>
            </form>
            <p v-if="cargando" role="status" class="text-gray-500">
                Cargando eventos…
            </p>
            <div v-if="datos" class="grid md:grid-cols-2 xl:grid-cols-3 gap-5">
                <article
                    v-for="e in datos.eventos"
                    :key="e.id"
                    class="rounded-xl border bg-white p-5 flex flex-col gap-3"
                >
                    <div class="flex justify-between items-center gap-2">
                        <span
                            class="text-xs font-semibold uppercase text-blue-700"
                            >{{ e.organizacion_nombre }}</span
                        ><span
                            class="text-xs rounded-full px-2 py-1 bg-gray-100"
                            >{{ e.estado }}</span
                        >
                    </div>
                    <h2 class="font-bold text-xl text-gray-900">
                        {{ e.titulo }}
                    </h2>
                    <p class="text-sm text-gray-600 whitespace-pre-line">
                        {{ e.descripcion }}
                    </p>
                    <div class="text-sm space-y-1">
                        <p class="font-medium">
                            {{ fecha(e.fecha_hora_inicio) }}
                        </p>
                        <p>{{ e.ubicacion }}</p>
                        <p class="font-semibold text-blue-800">
                            {{ dinero(e.costo) }}
                        </p>
                    </div>
                    <p class="text-sm text-gray-500">
                        {{ e.stats.confirmados }} / {{ e.capacidad }} lugares
                        reservados<span v-if="e.stats.espera">
                            · {{ e.stats.espera }} en espera</span
                        >
                    </p>
                    <p class="text-xs text-gray-500">
                        Inscripción: {{ fecha(e.fecha_inicio_registro) }} —
                        {{ fecha(e.fecha_fin_registro) }}
                    </p>
                    <div
                        v-if="modo === 'gestion'"
                        class="mt-auto flex flex-wrap gap-3 text-sm font-semibold"
                    >
                        <button
                            @click="staffEvento = e"
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            Personal de acceso
                        </button>
                        <Link
                            :href="`/modulo6/staff?evento=${e.id}`"
                            class="text-blue-800"
                            >Control de acceso</Link
                        >
                        <button
                            v-if="editable(e)"
                            @click="abrir(e)"
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            Editar</button
                        ><button
                            v-if="e.estado === 'borrador' && editable(e)"
                            @click="publicar(e)"
                            :disabled="ocupado"
                            class="text-green-800"
                        >
                            Publicar</button
                        ><button
                            @click="verLista(e)"
                            :disabled="ocupado"
                            class="text-blue-800"
                        >
                            Inscritos y asistencia</button
                        ><button
                            v-if="editable(e)"
                            @click="cancelar(e)"
                            :disabled="ocupado"
                            class="text-red-700"
                        >
                            Cancelar evento
                        </button>
                    </div>
                    <div v-else class="mt-auto space-y-2">
                        <Link
                            v-if="
                                e.mi_registro &&
                                e.mi_registro.estado !== 'cancelada'
                            "
                            :href="`/modulo6/eventos/${e.id}/boleto`"
                            class="block text-center rounded-lg bg-blue-900 px-4 py-2 font-semibold text-white"
                            >{{
                                e.mi_registro.estado === "espera"
                                    ? "Ver mi lugar en espera"
                                    : "Ver mi reserva"
                            }}</Link
                        >
                        <button
                            v-else
                            :disabled="
                                ocupado ||
                                !e.registro_abierto ||
                                (e.stats.confirmados >= e.capacidad &&
                                    !e.lista_espera)
                            "
                            @click="inscribir(e)"
                            class="w-full rounded-lg bg-blue-900 px-4 py-2 font-semibold text-white disabled:opacity-50"
                        >
                            {{
                                !e.registro_abierto
                                    ? "Inscripción cerrada"
                                    : e.stats.confirmados >= e.capacidad
                                      ? e.lista_espera
                                          ? "Unirme a la espera"
                                          : "Cupo agotado"
                                      : e.costo > 0
                                        ? "Reservar lugar"
                                        : "Inscribirme"
                            }}
                        </button>
                        <p v-if="e.costo > 0" class="text-xs text-amber-800">
                            El lugar se reserva con pago pendiente. Aún no se
                            realizan cobros ni se habilita el acceso.
                        </p>
                    </div>
                </article>
            </div>
            <p
                v-if="datos && !datos.eventos.length && !cargando"
                class="rounded-xl border bg-white p-8 text-center text-gray-600"
            >
                No hay eventos que coincidan con esta búsqueda.
            </p>
            <div
                v-if="datos && datos.last_page > 1"
                class="flex justify-center gap-4 items-center"
            >
                <button
                    :disabled="datos.page === 1 || cargando"
                    @click="cargar(datos.page - 1)"
                    class="disabled:opacity-40"
                >
                    Anterior</button
                ><span>{{ datos.page }} / {{ datos.last_page }}</span
                ><button
                    :disabled="datos.page === datos.last_page || cargando"
                    @click="cargar(datos.page + 1)"
                    class="disabled:opacity-40"
                >
                    Siguiente
                </button>
            </div>
            <section
                v-if="seleccionado && modo === 'gestion'"
                class="rounded-xl border bg-white p-6 space-y-5"
            >
                <div class="flex justify-between gap-3">
                    <h2 class="text-xl font-semibold">
                        Inscritos: {{ seleccionado.titulo }}
                    </h2>
                    <button
                        @click="seleccionado = null"
                        aria-label="Cerrar inscritos"
                    >
                        Cerrar
                    </button>
                </div>
                <div
                    v-if="seleccionado.estado === 'publicado'"
                    class="max-w-xl space-y-3"
                >
                    <h3 class="font-semibold">Registrar asistencia</h3>
                    <p class="text-sm text-gray-500">
                        Desde 30 minutos antes del inicio hasta el final. Solo
                        reservas confirmadas con pago exento o confirmado.
                    </p>
                    <EscanerEvento
                        :evento-id="seleccionado.id"
                        :disabled="ocupado"
                        @token="checkin"
                    />
                    <form @submit.prevent="checkin()" class="flex gap-2">
                        <input
                            v-model="token"
                            required
                            maxlength="255"
                            aria-label="Código del boleto"
                            placeholder="Pega el código o usa un lector QR"
                            autocomplete="off"
                            class="rounded-lg border-gray-300 min-w-0 flex-1"
                        /><button
                            :disabled="ocupado"
                            class="rounded-lg bg-blue-900 text-white px-3 py-2"
                        >
                            Validar acceso
                        </button>
                    </form>
                </div>
                <select
                    v-model="filtroLista"
                    @change="cargarLista()"
                    aria-label="Estado de inscripción"
                    class="rounded-lg border-gray-300"
                >
                    <option value="">Todas las inscripciones</option>
                    <option value="confirmada">Confirmadas</option>
                    <option value="espera">Lista de espera</option>
                    <option value="cancelada">Canceladas</option>
                </select>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="border-b text-gray-500">
                            <tr>
                                <th class="py-3">Estudiante</th>
                                <th>Matrícula</th>
                                <th>Reserva</th>
                                <th>Pago</th>
                                <th>Asistencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="reg in lista?.registros" :key="reg.id">
                                <td class="py-3 pr-3">{{ reg.nombre }}</td>
                                <td class="pr-3">{{ reg.matricula || "—" }}</td>
                                <td class="pr-3">{{ reg.estado }}</td>
                                <td class="pr-3">{{ reg.estado_pago }}</td>
                                <td>
                                    {{
                                        reg.estado_asistencia === "asistio"
                                            ? "Registrada"
                                            : "Pendiente"
                                    }}
                                </td>
                            </tr>
                            <tr v-if="lista && !lista.registros.length">
                                <td
                                    colspan="5"
                                    class="py-6 text-center text-gray-500"
                                >
                                    Sin inscripciones.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="lista && lista.last_page > 1" class="flex gap-4">
                    <button
                        :disabled="lista.page === 1"
                        @click="cargarLista(lista.page - 1)"
                    >
                        Anterior</button
                    ><span>{{ lista.page }} / {{ lista.last_page }}</span
                    ><button
                        :disabled="lista.page === lista.last_page"
                        @click="cargarLista(lista.page + 1)"
                    >
                        Siguiente
                    </button>
                </div>
            </section>
        </div>
        <StaffEventoPanel :evento="staffEvento" @close="staffEvento = null" />
        <PanelLateral
            :show="panel"
            :titulo="formulario.id ? 'Editar evento' : 'Nuevo borrador'"
            @close="cerrar"
        >
            <div
                v-if="error"
                role="alert"
                class="bg-red-50 text-red-800 p-3 mb-4 rounded-lg"
            >
                {{ error }}
                <ul class="list-disc pl-4">
                    <li v-for="(m, c) in errores" :key="c">{{ m[0] }}</li>
                </ul>
            </div>
            <form id="form-evento" @submit.prevent="guardar" class="space-y-4">
                <p class="text-sm text-gray-500">
                    Horarios de Ciudad de México. Los nuevos eventos se guardan
                    como borrador antes de publicarse.
                </p>
                <div>
                    <label for="evt-titulo">Título</label
                    ><input
                        id="evt-titulo"
                        v-model="formulario.titulo"
                        required
                        maxlength="150"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="evt-descripcion">Descripción</label
                    ><textarea
                        id="evt-descripcion"
                        v-model="formulario.descripcion"
                        required
                        maxlength="5000"
                        rows="3"
                        class="w-full rounded-lg border-gray-300"
                    ></textarea>
                </div>
                <div>
                    <label for="evt-lugar">Ubicación</label
                    ><input
                        id="evt-lugar"
                        v-model="formulario.ubicacion"
                        required
                        maxlength="255"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div
                    v-for="campo in [
                        { id: 'fecha_hora_inicio', label: 'Inicio del evento' },
                        { id: 'fecha_hora_fin', label: 'Fin del evento' },
                        {
                            id: 'fecha_inicio_registro',
                            label: 'Apertura de inscripción',
                        },
                        {
                            id: 'fecha_fin_registro',
                            label: 'Cierre de inscripción',
                        },
                    ]"
                    :key="campo.id"
                >
                    <label :for="campo.id">{{ campo.label }}</label
                    ><input
                        :id="campo.id"
                        v-model="formulario[campo.id]"
                        type="datetime-local"
                        required
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="evt-cupo">Cupo</label
                        ><input
                            id="evt-cupo"
                            v-model.number="formulario.capacidad"
                            required
                            type="number"
                            min="1"
                            max="10000"
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                    <div>
                        <label for="evt-costo">Costo (MXN)</label
                        ><input
                            id="evt-costo"
                            v-model.number="formulario.costo"
                            required
                            type="number"
                            min="0"
                            max="100000"
                            step="0.01"
                            class="w-full rounded-lg border-gray-300"
                        />
                    </div>
                </div>
                <label class="flex items-center gap-2"
                    ><input
                        v-model="formulario.lista_espera"
                        type="checkbox"
                        class="rounded"
                    />Habilitar lista de espera</label
                >
                <p v-if="formulario.costo > 0" class="text-sm text-amber-800">
                    Se aceptarán reservas con pago pendiente; el cobro lo
                    integrará el equipo 2.
                </p>
            </form>
            <template #footer
                ><button
                    form="form-evento"
                    type="submit"
                    :disabled="ocupado"
                    class="rounded-lg bg-blue-900 px-4 py-2 text-white disabled:opacity-50"
                >
                    {{
                        ocupado
                            ? "Guardando…"
                            : formulario.id
                              ? "Guardar cambios"
                              : "Guardar borrador"
                    }}
                </button></template
            >
        </PanelLateral>
    </Modulo6Layout>
</template>
