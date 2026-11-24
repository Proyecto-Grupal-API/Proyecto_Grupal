<script setup>
import { Head } from "@inertiajs/vue3";
import { ref, onMounted } from "vue";
import axios from "axios";
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
// Avisos generales a todos los usuarios (coordinación de comunicaciones; petición del profesor, 20-nov-2026).
const avisos = ref([]),
    form = ref({ asunto: "", cuerpo: "", confirmacion: false }),
    errores = ref({}),
    error = ref(""),
    exito = ref(""),
    ocupado = ref(false);
const fecha = (v) => (v ? new Date(v).toLocaleString("es-MX", { dateStyle: "medium", timeStyle: "short" }) : "—");
async function cargar() {
    const { data } = await axios.get("/api/v1/comunidad/portal/avisos-generales");
    avisos.value = data.avisos;
}
async function publicar() {
    if (ocupado.value) return;
    ocupado.value = true;
    error.value = "";
    exito.value = "";
    errores.value = {};
    try {
        const { data } = await axios.post("/api/v1/comunidad/portal/avisos-generales", form.value);
        exito.value = data.message;
        form.value = { asunto: "", cuerpo: "", confirmacion: false };
        await cargar();
    } catch (e) {
        errores.value = e.response?.data?.errors ?? {};
        error.value = Object.keys(errores.value).length ? "Revisa los datos indicados." : e.response?.data?.message || "No fue posible publicar el aviso.";
    } finally {
        ocupado.value = false;
    }
}
onMounted(cargar);
</script>

<template>
    <Head title="Avisos generales — Campus Digital" />
    <Modulo6Layout headerTitle="Avisos generales">
        <div class="campus-page space-y-5">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Avisos generales</h1>
                <p class="text-sm text-gray-600">Coordinación de comunicaciones: el aviso llega a la bandeja de todos los usuarios de Campus Digital. No se envía correo ni SMS.</p>
            </div>
            <form class="space-y-3 rounded-xl border bg-white p-5" @submit.prevent="publicar" data-form-aviso-general>
                <label class="block text-sm font-semibold">Asunto
                    <input v-model="form.asunto" maxlength="150" class="mt-1 w-full rounded-lg border-gray-300" />
                    <span v-if="errores.asunto" class="text-xs text-red-700">{{ errores.asunto[0] }}</span>
                </label>
                <label class="block text-sm font-semibold">Mensaje
                    <textarea v-model="form.cuerpo" rows="5" maxlength="5000" class="mt-1 w-full rounded-lg border-gray-300"></textarea>
                    <span v-if="errores.cuerpo" class="text-xs text-red-700">{{ errores.cuerpo[0] }}</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.confirmacion" type="checkbox" class="rounded" /> Confirmo que este aviso se enviará a todos los usuarios.
                </label>
                <span v-if="errores.confirmacion" class="block text-xs text-red-700">{{ errores.confirmacion[0] }}</span>
                <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <p v-if="exito" class="text-sm text-green-700" data-aviso-publicado>{{ exito }}</p>
                <button type="submit" :disabled="ocupado" class="rounded-xl bg-[#00338D] px-5 py-2 font-semibold text-white disabled:opacity-50">Publicar aviso general</button>
            </form>
            <section class="rounded-xl border bg-white p-5">
                <h2 class="font-bold text-gray-900">Avisos publicados</h2>
                <p v-if="!avisos.length" class="mt-2 text-sm text-gray-500">Todavía no hay avisos generales.</p>
                <article v-for="a in avisos" :key="a.id" class="mt-3 border-t pt-3" data-aviso-general>
                    <h3 class="font-semibold">{{ a.asunto }}</h3>
                    <p class="text-xs text-gray-500">{{ a.autor_nombre }} · {{ fecha(a.publicado_en) }} · {{ a.estado === "enviado" ? `entregado a ${a.entregados} usuarios` : a.estado === "fallido" ? "entrega interrumpida" : `entregando (${a.entregados})` }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ a.cuerpo }}</p>
                </article>
            </section>
        </div>
    </Modulo6Layout>
</template>
