<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
function submit() {
    const options = { forceFormData: true, preserveScroll: true };
    props.student ? form.patch(`/students/${props.student.id}`, options) : form.post('/students', options);
}
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
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5"><Link href="/students" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600">Cancelar</Link><button type="submit" class="rounded-lg bg-[#00338D] px-5 py-2 text-sm font-semibold text-white" :disabled="form.processing">Guardar perfil</button></div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
