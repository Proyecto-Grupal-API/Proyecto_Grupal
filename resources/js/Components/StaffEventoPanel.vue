<script setup>
import { ref, watch, computed } from "vue";
import axios from "axios";
import PanelLateral from "@/Components/Panellateral.vue";
const props = defineProps({ evento: Object });
const emit = defineEmits(["close"]);
const datos = ref(null),
    usuario = ref(""),
    cargando = ref(false),
    ocupado = ref(false),
    error = ref(""),
    aviso = ref("");
let version = 0;
const disponibles = computed(() =>
    (datos.value?.integrantes ?? []).filter(
        (u) => !datos.value.staff.some((s) => s.usuario_id === u.id),
    ),
);
const permiteAsignar = computed(
    () =>
        props.evento &&
        props.evento.estado !== "cancelado" &&
        new Date(props.evento.fecha_hora_fin) > new Date(),
);
function fallar(e) {
    error.value = e.response?.data?.errors
        ? Object.values(e.response.data.errors).flat().join(" ")
        : e.response?.status === 403
          ? "Ya no tienes permiso para administrar este evento."
          : "No se pudo actualizar el personal. Intenta nuevamente.";
}
async function cargar() {
    const id = props.evento?.id,
        turno = ++version;
    if (!id) return;
    cargando.value = true;
    try {
        const res = await axios.get(`/api/eventos/${id}/staff`);
        if (turno === version) datos.value = res.data;
    } catch (e) {
        if (turno === version) fallar(e);
    } finally {
        if (turno === version) cargando.value = false;
    }
}
watch(
    () => props.evento?.id,
    () => {
        version++;
        datos.value = null;
        usuario.value = "";
        error.value = "";
        aviso.value = "";
        cargar();
    },
    { immediate: true },
);
async function guardar(staff = null) {
    if (ocupado.value || !props.evento) return;
    ocupado.value = true;
    error.value = "";
    aviso.value = "";
    try {
        if (staff)
            await axios.delete(
                `/api/eventos/${props.evento.id}/staff/${staff.id}`,
            );
        else
            await axios.post(`/api/eventos/${props.evento.id}/staff`, {
                usuario_id: usuario.value,
            });
        aviso.value = staff
            ? "Permiso de acceso retirado."
            : "Personal asignado al evento.";
        usuario.value = "";
        await cargar();
    } catch (e) {
        fallar(e);
    } finally {
        ocupado.value = false;
    }
}
</script>
<template>
    <PanelLateral
        :show="!!evento"
        titulo="Personal de acceso"
        @close="!ocupado && emit('close')"
    >
        <div class="space-y-5">
            <h2 class="font-bold text-lg">{{ evento?.titulo }}</h2>
            <p class="text-sm text-slate-600">
                El staff valida boletos y registra asistencia únicamente en este
                evento. No obtiene permisos de administración.
            </p>
            <p
                v-if="error"
                role="alert"
                class="rounded-lg bg-red-50 p-3 text-red-800"
            >
                {{ error }}
            </p>
            <p
                v-if="aviso"
                role="status"
                class="rounded-lg bg-green-50 p-3 text-green-800"
            >
                {{ aviso }}
            </p>
            <p v-if="cargando" role="status">Cargando personal…</p>
            <form
                v-if="datos && permiteAsignar"
                @submit.prevent="guardar()"
                class="space-y-3"
            >
                <label for="staff-usuario" class="block font-semibold"
                    >Integrante de la organización</label
                ><select
                    id="staff-usuario"
                    v-model="usuario"
                    required
                    :disabled="ocupado || cargando"
                    class="w-full rounded-lg border-gray-300"
                >
                    <option value="">Selecciona una persona</option>
                    <option v-for="u in disponibles" :key="u.id" :value="u.id">
                        {{ u.name }} · {{ u.matricula || "Sin matrícula" }}
                    </option></select
                ><button
                    :disabled="ocupado || cargando || !usuario"
                    class="rounded-lg bg-blue-900 px-4 py-3 text-white font-semibold disabled:opacity-50"
                >
                    Asignar staff
                </button>
                <p v-if="!disponibles.length" class="text-sm text-gray-500">
                    No hay más integrantes disponibles. Puedes agregarlos desde
                    Organizaciones.
                </p>
            </form>
            <p v-if="evento && !permiteAsignar" class="text-sm text-gray-600">
                El evento finalizó o fue cancelado. Puedes retirar asignaciones
                anteriores.
            </p>
            <ul class="divide-y">
                <li
                    v-for="s in datos?.staff"
                    :key="s.id"
                    class="py-4 flex items-center justify-between gap-3"
                >
                    <span class="font-semibold">{{ s.nombre }}</span
                    ><button
                        @click="guardar(s)"
                        :disabled="ocupado || cargando"
                        :aria-label="`Retirar a ${s.nombre}`"
                        class="text-red-800 text-sm font-semibold"
                    >
                        Retirar
                    </button>
                </li>
            </ul>
            <p
                v-if="datos && !datos.staff.length"
                class="text-sm text-slate-500"
            >
                Aún no se ha asignado personal. Presidencia puede validar los
                boletos de su organización.
            </p>
        </div>
    </PanelLateral>
</template>
