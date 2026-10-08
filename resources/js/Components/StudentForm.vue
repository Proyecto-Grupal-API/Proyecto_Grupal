<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TemporaryPasswordModal from '@/Components/TemporaryPasswordModal.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    student: { type: Object, default: null },
    campuses: { type: Array, required: true },
    statuses: { type: Array, required: true },
    contactChannels: { type: Array, required: true },
});

const form = useForm({
    name: props.student?.name ?? '', email: props.student?.email ?? '',
    enrollment_number: props.student?.student_profile?.enrollment_number ?? '',
    campus_id: props.student?.student_profile?.campus_id ?? props.campuses[0]?.id ?? '',
    academic_program_id: props.student?.student_profile?.academic_program_id ?? '',
    current_semester: props.student?.student_profile?.current_semester ?? 1,
    group_name: props.student?.student_profile?.group_name ?? '',
    academic_status: props.student?.student_profile?.academic_status ?? 'active',
    status_reason: '', personal_email: props.student?.student_profile?.personal_email ?? '',
    phone: props.student?.student_profile?.phone ?? '', preferred_contact_channel: props.student?.student_profile?.preferred_contact_channel ?? 'institutional_email',
    locale: props.student?.student_profile?.locale ?? 'es-MX', photo: null,
});

const selectedCampus = () => props.campuses.find((campus) => String(campus.id) === String(form.campus_id));
const createSubmitting = ref(false);
const reissueSubmitting = ref(false);
const showReissueConfirmation = ref(false);
const temporaryPassword = ref(null);
const temporaryMode = ref(null);
const requestError = ref('');
const reissueError = ref('');

function receiptFrom(response, expectedStatus) {
    const data = response?.data;
    return response?.status === expectedStatus
        && data && typeof data === 'object'
        && typeof data.student_id === 'string' && data.student_id.length > 0
        && typeof data.temporary_password === 'string' && data.temporary_password.length > 0
        ? data.temporary_password : null;
}

async function submit() {
    if (props.student) {
        form.patch(`/students/${props.student.id}`, { forceFormData: true, preserveScroll: true });
        return;
    }
    if (createSubmitting.value || temporaryPassword.value !== null) return;

    createSubmitting.value = true;
    requestError.value = '';
    form.clearErrors();
    const data = new FormData();
    for (const [key, value] of Object.entries(form.data())) {
        if (value !== null && value !== undefined) data.append(key, value);
    }

    try {
        const response = await window.axios.post(route('students.store'), data, {
            headers: { Accept: 'application/json' },
        });
        const password = receiptFrom(response, 201);
        if (password === null) {
            requestError.value = 'La cuenta pudo haberse creado, pero no se pudo mostrar la credencial. Verifica el registro antes de intentar otra alta.';
            return;
        }
        temporaryPassword.value = password;
        temporaryMode.value = 'created';
    } catch (error) {
        if (error.response?.status === 422 && error.response.data?.errors) {
            form.setError(Object.fromEntries(
                Object.entries(error.response.data.errors).map(([field, messages]) => [
                    field, Array.isArray(messages) ? messages[0] : String(messages),
                ]),
            ));
        } else if (error.response?.status === 403) {
            requestError.value = 'No tienes permiso para crear esta cuenta.';
        } else if (error.response?.status === 419) {
            requestError.value = 'La sesión expiró. Verifica si la cuenta fue creada antes de intentarlo nuevamente.';
        } else {
            requestError.value = 'No se pudo confirmar la entrega de la credencial. Verifica si la cuenta fue creada antes de intentarlo nuevamente.';
        }
    } finally {
        createSubmitting.value = false;
    }
}

async function reissue() {
    if (!props.student || reissueSubmitting.value) return;
    showReissueConfirmation.value = false;
    reissueSubmitting.value = true;
    reissueError.value = '';

    try {
        const response = await window.axios.post(route('students.temporary-password.reissue', props.student.id), null, {
            headers: { Accept: 'application/json' },
        });
        const password = receiptFrom(response, 200);
        if (password === null) {
            reissueError.value = 'La credencial pudo haberse generado, pero no se pudo mostrar. Verifica el estado antes de volver a intentarlo.';
            return;
        }
        temporaryPassword.value = password;
        temporaryMode.value = 'reissued';
    } catch (error) {
        const status = error.response?.status;
        if (status === 403) reissueError.value = 'No tienes permiso para realizar esta acción.';
        else if (status === 404) reissueError.value = 'El estudiante ya no está disponible.';
        else if (status === 409) reissueError.value = 'La cuenta ya no admite una contraseña temporal inicial. Su estado pudo haber cambiado.';
        else if (status === 422) reissueError.value = 'La solicitud de contraseña temporal no es válida.';
        else if (status === 419) reissueError.value = 'La sesión expiró. Verifica el estado antes de volver a intentarlo.';
        else reissueError.value = 'No se pudo confirmar la generación. Verifica el estado antes de volver a intentarlo.';
    } finally {
        reissueSubmitting.value = false;
    }
}

function closeReceipt() {
    const wasCreated = temporaryMode.value === 'created';
    temporaryPassword.value = null;
    temporaryMode.value = null;
    if (wasCreated) router.visit(route('students.index'));
}

onBeforeUnmount(() => { temporaryPassword.value = null; });
</script>

<template>
    <Head :title="student ? 'Editar estudiante' : 'Nuevo estudiante'" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-[#00338D]">{{ student ? 'Editar perfil académico' : 'Registrar estudiante' }}</h2></template>
        <div class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <form class="mx-auto max-w-5xl space-y-6 rounded-2xl bg-white p-6 shadow-sm sm:p-8" @submit.prevent="submit">
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Nombre completo</span><input v-model="form.name" class="mt-1 w-full rounded-lg border-slate-300" /><small class="text-red-600">{{ form.errors.name }}</small></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Correo institucional</span><input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-slate-300" /><small class="text-red-600">{{ form.errors.email }}</small></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Matrícula</span><input v-model="form.enrollment_number" class="mt-1 w-full rounded-lg border-slate-300" /><small class="text-red-600">{{ form.errors.enrollment_number }}</small></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Campus</span><select v-model="form.campus_id" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="campus in campuses" :key="campus.id" :value="campus.id">{{ campus.name }}</option></select></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Carrera</span><select v-model="form.academic_program_id" class="mt-1 w-full rounded-lg border-slate-300"><option value="">Selecciona una carrera</option><option v-for="program in selectedCampus()?.academic_programs ?? []" :key="program.id" :value="program.id">{{ program.name }}</option></select><small class="text-red-600">{{ form.errors.academic_program_id }}</small></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Semestre</span><input v-model="form.current_semester" type="number" min="1" max="20" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Grupo</span><input v-model="form.group_name" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Estatus académico</span><select v-model="form.academic_status" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</option></select></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Canal preferido</span><select v-model="form.preferred_contact_channel" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="channel in contactChannels" :key="channel.value" :value="channel.value">{{ channel.label }}</option></select></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Correo personal</span><input v-model="form.personal_email" type="email" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Teléfono</span><input v-model="form.phone" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block"><span class="text-sm font-semibold text-slate-700">Motivo del cambio de estatus</span><input v-model="form.status_reason" class="mt-1 w-full rounded-lg border-slate-300" /></label>
                    <label class="block md:col-span-2"><span class="text-sm font-semibold text-slate-700">Fotografía</span><input type="file" accept="image/*" class="mt-1 block" @change="form.photo = $event.target.files[0]" /></label>
                </div>
                <p v-if="requestError" role="alert" class="text-sm text-rose-700">{{ requestError }}</p>
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5"><Link href="/students" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600">Cancelar</Link><button type="submit" class="rounded-lg bg-[#00338D] px-5 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="form.processing || createSubmitting || temporaryPassword !== null">{{ createSubmitting ? 'Guardando...' : 'Guardar perfil' }}</button></div>
            </form>
            <section v-if="student" class="mx-auto mt-6 max-w-5xl rounded-2xl bg-white p-6 shadow-sm sm:p-8">
                <h3 class="text-lg font-semibold text-[#00338D]">Acceso inicial</h3>
                <p class="mt-2 text-sm text-slate-600">Puedes generar una contraseña temporal para una cuenta pendiente o sustituir una credencial inicial aún no cambiada. La cuenta debe seguir siendo elegible.</p>
                <p v-if="reissueError" role="alert" class="mt-3 text-sm text-rose-700">{{ reissueError }}</p>
                <button type="button" class="mt-4 rounded-lg border border-[#00338D] px-4 py-2 text-sm font-semibold text-[#00338D] disabled:opacity-50" :disabled="reissueSubmitting || temporaryPassword !== null" @click="showReissueConfirmation = true">
                    {{ reissueSubmitting ? 'Generando...' : 'Generar contraseña temporal' }}
                </button>
            </section>
        </div>
        <Modal :show="showReissueConfirmation" max-width="md" @close="showReissueConfirmation = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-[#00338D]">Confirmar contraseña temporal</h3>
                <p class="mt-3 text-sm text-slate-600">Se generará una contraseña temporal que sólo se mostrará una vez. Si ya existe una credencial inicial, dejará de ser válida. Si la cuenta ya no es elegible, la operación será rechazada.</p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600" @click="showReissueConfirmation = false">Cancelar</button>
                    <button type="button" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="reissueSubmitting" @click="reissue">Generar</button>
                </div>
            </div>
        </Modal>
        <TemporaryPasswordModal :show="temporaryPassword !== null" :password="temporaryPassword ?? ''" :mode="temporaryMode" :student-label="student?.name ?? form.name" @close="closeReceipt" />
    </AuthenticatedLayout>
</template>
