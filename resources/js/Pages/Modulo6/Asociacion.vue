<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import PanelLateral from "@/Components/Panellateral.vue";

const page = usePage();
const datos = ref(null);
const cargando = ref(true);
const guardando = ref(false);
const error = ref("");
const errores = ref({});
const aviso = ref("");
const panel = ref(null);
const busqueda = ref("");
const seleccion = ref("");
const perfil = ref({});
const miembro = ref({});
const cargo = ref({});
const miembrosFiltrados = computed(() =>
    (datos.value?.miembros ?? []).filter((m) =>
        `${m.nombre} ${m.matricula ?? ""}`
            .toLowerCase()
            .includes(busqueda.value.toLowerCase()),
    ),
);
const fecha = (valor) => (valor ? valor.slice(0, 10) : "");
const hoy = () => new Date().toISOString().slice(0, 10);
const nombreCargo = (valor) =>
    ({
        presidencia: "Presidencia",
        vicepresidencia: "Vicepresidencia",
        secretaria: "Secretaría",
        tesoreria: "Tesorería",
        comunicacion: "Comunicación",
    })[valor] ?? valor;

function mostrarError(e) {
    errores.value = e.response?.data?.errors ?? {};
    error.value = Object.keys(errores.value).length
        ? "Revisa los datos del formulario."
        : e.response?.status === 401 || e.response?.status === 419
          ? "Tu sesión terminó. Recarga la página e inicia sesión nuevamente."
          : (e.response?.status >= 500
                ? "No fue posible guardar los cambios. Intenta nuevamente."
                : e.response?.data?.message) ||
            "No fue posible conectar. Intenta nuevamente.";
}
async function cargar() {
    const { data } = await axios.get("/api/organizaciones");
    datos.value = data;
    seleccion.value = data.organizacion?.id ?? "";
}
async function iniciar() {
    cargando.value = true;
    error.value = "";
    try {
        await cargar();
    } catch (e) {
        mostrarError(e);
    } finally {
        cargando.value = false;
    }
}
onMounted(iniciar);
function abrir(tipo, rol = null) {
    error.value = "";
    errores.value = {};
    aviso.value = "";
    perfil.value = {
        nombre: datos.value.organizacion.nombre,
        descripcion: datos.value.organizacion.descripcion ?? "",
        email: datos.value.organizacion.email ?? "",
        telefono: datos.value.organizacion.telefono ?? "",
    };
    miembro.value = { matricula: "", fecha_inicio: hoy() };
    cargo.value = rol
        ? {
              id: rol.id,
              usuario_id: rol.usuario_id,
              slug_rol: rol.slug_rol,
              fecha_inicio: fecha(rol.fecha_inicio),
              fecha_fin: fecha(rol.fecha_fin),
          }
        : {
              usuario_id: "",
              slug_rol: "secretaria",
              fecha_inicio: hoy(),
              fecha_fin: "",
          };
    panel.value = tipo;
}
function cerrarPanel() {
    if (guardando.value) return;
    panel.value = null;
    error.value = "";
    errores.value = {};
}
async function ejecutar(peticion) {
    if (guardando.value) return;
    guardando.value = true;
    error.value = "";
    errores.value = {};
    aviso.value = "";
    try {
        await peticion();
        panel.value = null;
        aviso.value = "Cambios guardados.";
        await cargar();
    } catch (e) {
        mostrarError(e);
    } finally {
        guardando.value = false;
    }
}
const guardarPerfil = () =>
    ejecutar(() => axios.put("/api/organizaciones/perfil", perfil.value));
const guardarMiembro = () =>
    ejecutar(() => axios.post("/api/organizaciones/miembros", miembro.value));
const guardarCargo = () =>
    ejecutar(() =>
        cargo.value.id
            ? axios.put(
                  `/api/organizaciones/roles/${cargo.value.id}`,
                  cargo.value,
              )
            : axios.post("/api/organizaciones/roles", cargo.value),
    );
function quitar(tipo, id) {
    if (
        confirm(
            tipo === "miembros"
                ? "¿Dar de baja a este miembro y retirar sus cargos?"
                : "¿Retirar este cargo?",
        )
    )
        ejecutar(() => axios.delete(`/api/organizaciones/${tipo}/${id}`));
}
async function cambiarOrganizacion() {
    guardando.value = true;
    error.value = "";
    try {
        await axios.post("/api/organizaciones/seleccionar", {
            organizacion_id: seleccion.value,
        });
        window.location.reload();
    } catch (e) {
        mostrarError(e);
        seleccion.value = datos.value.organizacion?.id ?? "";
        guardando.value = false;
    }
}
</script>

<template>
    <Head title="Organizaciones — Campus Digital" />
    <Modulo6Layout headerTitle="Asociaciones y Consejo">
        <div class="campus-page space-y-6">
            <div
                v-if="cargando"
                role="status"
                class="p-10 text-center text-gray-600"
            >
                Cargando organización…
            </div>
            <template v-else>
                <Link
                    v-if="page.props.auth.gestiona_organizaciones"
                    href="/modulo6/gestion-organizaciones"
                    class="block rounded-xl border bg-blue-50 p-5 font-semibold text-blue-900"
                    >Abrir gestión de organizaciones →</Link
                >
                <div
                    v-if="datos?.organizaciones_suspendidas?.length"
                    role="status"
                    class="rounded-xl bg-amber-50 p-5 text-amber-900"
                >
                    <p class="font-semibold">Organizaciones suspendidas</p>
                    <p class="text-sm mt-1">
                        Tu membresía se conserva. La operación está suspendida
                        en:
                        {{
                            datos.organizaciones_suspendidas
                                .map((o) => o.nombre)
                                .join(", ")
                        }}.
                    </p>
                </div>
                <div
                    v-if="error && !panel"
                    role="alert"
                    class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800"
                >
                    {{ error }}
                    <ul
                        v-if="Object.keys(errores).length"
                        class="mt-2 list-disc pl-5"
                    >
                        <li v-for="(mensajes, campo) in errores" :key="campo">
                            {{ mensajes[0] }}
                        </li>
                    </ul>
                    <button
                        v-if="!datos"
                        @click="iniciar"
                        class="ml-3 underline"
                    >
                        Reintentar
                    </button>
                </div>
                <p
                    v-if="aviso"
                    role="status"
                    class="rounded-lg bg-green-50 p-4 text-green-800"
                >
                    {{ aviso }}
                </p>
                <div
                    v-if="datos && !datos.organizacion"
                    class="rounded-xl border bg-white p-8 text-center"
                >
                    <h1 class="text-xl font-semibold">
                        No tienes una organización activa seleccionable
                    </h1>
                    <p class="mt-2 text-gray-600">
                        Solicita a su presidencia que te incorpore con tu
                        matrícula.
                    </p>
                </div>
                <template v-if="datos?.organizacion">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <label
                                for="organizacion"
                                class="block text-sm font-medium text-gray-600 mb-1"
                                >Organización activa</label
                            >
                            <select
                                id="organizacion"
                                v-model="seleccion"
                                @change="cambiarOrganizacion"
                                :disabled="guardando"
                                class="rounded-lg border-gray-300 max-w-full"
                            >
                                <option
                                    v-for="org in datos.organizaciones"
                                    :key="org.id"
                                    :value="org.id"
                                >
                                    {{ org.nombre }}
                                </option>
                            </select>
                        </div>
                        <p class="text-sm text-gray-600">
                            {{
                                datos.puede_editar
                                    ? "Puedes administrar esta organización."
                                    : "Acceso de consulta como integrante."
                            }}
                        </p>
                    </div>
                    <section
                        class="rounded-xl border border-gray-200 bg-white p-6"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-4"
                        >
                            <div>
                                <p
                                    class="text-xs uppercase tracking-wider font-semibold text-blue-700"
                                >
                                    {{
                                        datos.organizacion.tipo === "consejo"
                                            ? "Consejo estudiantil"
                                            : "Asociación estudiantil"
                                    }}
                                </p>
                                <h1
                                    class="mt-1 text-2xl font-bold text-gray-900"
                                >
                                    {{ datos.organizacion.nombre }}
                                </h1>
                                <p
                                    class="mt-2 text-gray-600 whitespace-pre-line"
                                >
                                    {{
                                        datos.organizacion.descripcion ||
                                        "Sin descripción registrada."
                                    }}
                                </p>
                                <p class="mt-3 text-sm text-gray-500">
                                    {{ datos.organizacion.email
                                    }}<span v-if="datos.organizacion.telefono">
                                        ·
                                        {{ datos.organizacion.telefono }}</span
                                    >
                                </p>
                            </div>
                            <button
                                v-if="datos.puede_editar"
                                :disabled="guardando"
                                @click="abrir('perfil')"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold disabled:opacity-50"
                            >
                                Editar perfil
                            </button>
                        </div>
                    </section>
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <section
                            class="bg-white rounded-xl border border-gray-200 p-6"
                        >
                            <h2 class="text-lg font-semibold">
                                Mesa directiva
                            </h2>
                            <p class="mt-1 mb-4 text-sm text-gray-500">
                                Los permisos respetan las fechas de cada cargo.
                            </p>
                            <div class="space-y-3">
                                <article
                                    v-for="rol in datos.roles"
                                    :key="rol.id"
                                    class="rounded-lg bg-gray-50 p-4"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <h3 class="font-semibold text-blue-900">
                                            {{ nombreCargo(rol.slug_rol) }}
                                        </h3>
                                        <span
                                            class="text-xs"
                                            :class="
                                                rol.vigente
                                                    ? 'text-green-700'
                                                    : 'text-gray-500'
                                            "
                                            >{{
                                                rol.vigente
                                                    ? "Vigente"
                                                    : "Fuera de vigencia"
                                            }}</span
                                        >
                                    </div>
                                    <p class="mt-1 text-sm">{{ rol.nombre }}</p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ fecha(rol.fecha_inicio) }} —
                                        {{
                                            fecha(rol.fecha_fin) ||
                                            "Sin fecha de término"
                                        }}
                                    </p>
                                    <div
                                        v-if="datos.puede_editar"
                                        class="mt-3 flex gap-4 text-sm"
                                    >
                                        <button
                                            :disabled="guardando"
                                            @click="abrir('cargo', rol)"
                                            class="font-medium text-blue-700"
                                        >
                                            Editar cargo
                                        </button>
                                        <button
                                            :disabled="guardando"
                                            @click="quitar('roles', rol.id)"
                                            class="text-red-700"
                                        >
                                            Retirar
                                        </button>
                                    </div>
                                </article>
                                <p
                                    v-if="!datos.roles.length"
                                    class="text-sm text-gray-500"
                                >
                                    No hay cargos asignados.
                                </p>
                            </div>
                            <button
                                v-if="datos.puede_editar"
                                :disabled="guardando"
                                @click="abrir('cargo')"
                                class="mt-5 text-sm font-semibold text-blue-800"
                            >
                                + Asignar cargo
                            </button>
                        </section>
                        <section
                            class="xl:col-span-2 bg-white rounded-xl border border-gray-200 p-6 min-w-0"
                        >
                            <div
                                class="flex flex-wrap justify-between items-center gap-3"
                            >
                                <h2 class="text-lg font-semibold">
                                    Integrantes
                                    <span class="text-gray-500"
                                        >({{ datos.miembros.length }})</span
                                    >
                                </h2>
                                <button
                                    v-if="datos.puede_editar"
                                    :disabled="guardando"
                                    @click="abrir('miembro')"
                                    class="rounded-lg bg-blue-900 text-white px-4 py-2 text-sm font-semibold"
                                >
                                    Añadir integrante
                                </button>
                            </div>
                            <label for="busqueda" class="sr-only"
                                >Buscar por nombre o matrícula</label
                            ><input
                                id="busqueda"
                                v-model="busqueda"
                                type="search"
                                placeholder="Buscar por nombre o matrícula"
                                class="mt-4 w-full rounded-lg border-gray-300"
                            />
                            <div class="overflow-x-auto mt-4">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-gray-500 border-b">
                                        <tr>
                                            <th class="py-3 pr-3">Nombre</th>
                                            <th class="py-3 pr-3">Matrícula</th>
                                            <th class="py-3 pr-3">Ingreso</th>
                                            <th
                                                v-if="datos.puede_editar"
                                                class="py-3 text-right"
                                            >
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="m in miembrosFiltrados"
                                            :key="m.id"
                                        >
                                            <td class="py-4 pr-3 font-medium">
                                                {{ m.nombre }}
                                            </td>
                                            <td class="py-4 pr-3">
                                                {{
                                                    m.matricula ||
                                                    "Sin matrícula"
                                                }}
                                            </td>
                                            <td
                                                class="py-4 pr-3 whitespace-nowrap"
                                            >
                                                {{ fecha(m.fecha_inicio) }}
                                            </td>
                                            <td
                                                v-if="datos.puede_editar"
                                                class="py-4 text-right"
                                            >
                                                <button
                                                    :disabled="guardando"
                                                    @click="
                                                        quitar('miembros', m.id)
                                                    "
                                                    class="text-red-700 whitespace-nowrap"
                                                >
                                                    Dar de baja
                                                </button>
                                            </td>
                                        </tr>
                                        <tr v-if="!miembrosFiltrados.length">
                                            <td
                                                :colspan="
                                                    datos.puede_editar ? 4 : 3
                                                "
                                                class="py-8 text-center text-gray-500"
                                            >
                                                No hay integrantes que
                                                coincidan.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </template>
            </template>
        </div>
        <PanelLateral
            :show="panel !== null"
            :titulo="
                {
                    perfil: 'Editar organización',
                    miembro: 'Añadir integrante',
                    cargo: 'Asignar o editar cargo',
                }[panel] || ''
            "
            @close="cerrarPanel"
        >
            <div
                v-if="error"
                role="alert"
                class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800"
            >
                {{ error }}
                <ul class="mt-2 list-disc pl-4">
                    <li v-for="(mensajes, campo) in errores" :key="campo">
                        {{ mensajes[0] }}
                    </li>
                </ul>
            </div>
            <form
                v-if="panel === 'perfil'"
                id="form-perfil"
                @submit.prevent="guardarPerfil"
                class="space-y-4"
            >
                <div>
                    <label for="nombre" class="block text-sm font-medium mb-1"
                        >Nombre</label
                    ><input
                        id="nombre"
                        v-model="perfil.nombre"
                        required
                        maxlength="150"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label
                        for="descripcion"
                        class="block text-sm font-medium mb-1"
                        >Descripción</label
                    ><textarea
                        id="descripcion"
                        v-model="perfil.descripcion"
                        rows="4"
                        maxlength="2000"
                        class="w-full rounded-lg border-gray-300"
                    ></textarea>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium mb-1"
                        >Correo de contacto</label
                    ><input
                        id="email"
                        v-model="perfil.email"
                        type="email"
                        required
                        maxlength="255"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="telefono" class="block text-sm font-medium mb-1"
                        >Teléfono</label
                    ><input
                        id="telefono"
                        v-model="perfil.telefono"
                        type="tel"
                        maxlength="30"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
            </form>
            <form
                v-if="panel === 'miembro'"
                id="form-miembro"
                @submit.prevent="guardarMiembro"
                class="space-y-4"
            >
                <p class="text-sm text-gray-600">
                    El estudiante debe tener una cuenta y matrícula registradas.
                    Añadirlo como integrante no le otorga permisos de
                    administración.
                </p>
                <div>
                    <label
                        for="matricula"
                        class="block text-sm font-medium mb-1"
                        >Matrícula</label
                    ><input
                        id="matricula"
                        v-model.trim="miembro.matricula"
                        required
                        maxlength="40"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="ingreso" class="block text-sm font-medium mb-1"
                        >Fecha de ingreso</label
                    ><input
                        id="ingreso"
                        v-model="miembro.fecha_inicio"
                        type="date"
                        required
                        :max="hoy()"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
            </form>
            <form
                v-if="panel === 'cargo'"
                id="form-cargo"
                @submit.prevent="guardarCargo"
                class="space-y-4"
            >
                <p class="text-sm text-gray-600">
                    Solo la presidencia vigente administra la organización.
                    Editar su titular transfiere ese permiso.
                </p>
                <div>
                    <label for="titular" class="block text-sm font-medium mb-1"
                        >Titular</label
                    ><select
                        id="titular"
                        v-model="cargo.usuario_id"
                        required
                        class="w-full rounded-lg border-gray-300"
                    >
                        <option value="" disabled>
                            Selecciona un integrante
                        </option>
                        <option
                            v-for="m in datos?.miembros"
                            :key="m.id"
                            :value="m.usuario_id"
                        >
                            {{ m.nombre }} · {{ m.matricula }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="cargo" class="block text-sm font-medium mb-1"
                        >Cargo</label
                    ><select
                        id="cargo"
                        v-model="cargo.slug_rol"
                        required
                        class="w-full rounded-lg border-gray-300"
                    >
                        <option
                            v-for="slug in datos?.cargos"
                            :key="slug"
                            :value="slug"
                        >
                            {{ nombreCargo(slug) }}
                        </option>
                    </select>
                </div>
                <div>
                    <label for="inicio" class="block text-sm font-medium mb-1"
                        >Inicio de vigencia</label
                    ><input
                        id="inicio"
                        v-model="cargo.fecha_inicio"
                        required
                        type="date"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
                <div>
                    <label for="fin" class="block text-sm font-medium mb-1"
                        >Fin de vigencia (opcional)</label
                    ><input
                        id="fin"
                        v-model="cargo.fecha_fin"
                        type="date"
                        :min="cargo.fecha_inicio"
                        class="w-full rounded-lg border-gray-300"
                    />
                </div>
            </form>
            <template #footer
                ><button
                    :form="`form-${panel}`"
                    type="submit"
                    :disabled="guardando"
                    class="rounded-lg bg-blue-900 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                    {{ guardando ? "Guardando…" : "Guardar cambios" }}
                </button></template
            >
        </PanelLateral>
    </Modulo6Layout>
</template>
