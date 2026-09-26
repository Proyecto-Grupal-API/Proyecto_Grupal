<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
const props = defineProps({ students: Object, filters: Object, campuses: Array, statuses: Array, statistics: Object, canImportStudents: Boolean, importErrors: { type: Array, default: () => [] } });
const search = ref(props.filters.search ?? ''); const status = ref(props.filters.status ?? ''); const campus = ref(props.filters.campus ?? ''); let timer;
watch([search, status, campus], () => { clearTimeout(timer); timer = setTimeout(() => router.get('/students', { search: search.value || undefined, status: status.value || undefined, campus: campus.value || undefined }, { preserveState: true, replace: true }), 300); });
const importForm = useForm({ file: null });
const importFileInput = ref(null);
const showImportErrors = ref(true);
function selectImportFile(event) {
    importForm.file = event.target.files?.[0] ?? null;
    importForm.clearErrors();
    showImportErrors.value = false;
}
function submitImport() {
    if (importForm.processing) return;
    if (!importForm.file) {
        importForm.setError('file', 'Selecciona un archivo CSV para importar.');
        return;
    }
    importForm.post('/students/import', {
        forceFormData: true,
        preserveScroll: true,
        onError: () => { showImportErrors.value = true; },
        onSuccess: () => {
            importForm.reset('file');
            showImportErrors.value = false;
            if (importFileInput.value) importFileInput.value.value = '';
        },
    });
}
</script>
<template>
    <Head title="Cuentas y perfiles" />
    <AuthenticatedLayout><template #header><div class="flex items-center justify-between"><div><p class="text-xs font-bold uppercase tracking-widest text-[#0284C7]">Módulo 1.1</p><h2 class="text-2xl font-bold text-[#00338D]">Cuentas y perfiles</h2></div><Link href="/students/create" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-semibold text-white">Nuevo estudiante</Link></div></template>
        <div class="min-h-screen bg-[#F5F8FC] px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <div class="grid gap-4 md:grid-cols-4">
                    <div v-for="item in [['Total', statistics.total], ['Activos', statistics.active], ['Incompletos', statistics.incomplete], ['Actualizados', statistics.recent]]" :key="item[0]" class="rounded-xl bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">{{ item[0] }}</p><strong class="mt-2 block text-2xl text-[#00338D]">{{ item[1] }}</strong>
                    </div>
                </div>
                <section v-if="canImportStudents" class="rounded-2xl bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-[#00338D]">Importar estudiantes</h3>
                    <p class="mt-1 text-sm text-slate-600">Selecciona un CSV con los encabezados de la plantilla de estudiantes. El archivo se valida antes de importar.</p>
                    <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitImport">
                        <label class="block text-sm font-semibold text-slate-700">
                            Archivo CSV
                            <input ref="importFileInput" type="file" accept=".csv,.txt,text/csv,text/plain" class="mt-1 block w-full text-sm" :disabled="importForm.processing" @change="selectImportFile" />
                        </label>
                        <button type="submit" class="rounded-lg bg-[#00338D] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="importForm.processing || !importForm.file">
                            {{ importForm.processing ? 'Importando...' : 'Importar CSV' }}
                        </button>
                    </form>
                    <div v-if="showImportErrors && importErrors.length" role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                        <p class="font-semibold">Corrige el archivo y vuelve a intentarlo:</p>
                        <ul class="mt-1 list-disc pl-5"><li v-for="(error, index) in importErrors" :key="index">{{ error }}</li></ul>
                    </div>
                    <p v-else-if="importForm.errors.file" role="alert" class="mt-3 text-sm text-red-700">{{ importForm.errors.file }}</p>
                </section>
                <section class="rounded-2xl bg-white p-5 shadow-sm"><div class="mb-5 grid gap-3 md:grid-cols-[1fr_220px_220px]"><input v-model="search" placeholder="Buscar nombre, matrícula o correo" class="rounded-lg border-slate-300" /><select v-model="status" class="rounded-lg border-slate-300"><option value="">Todos los estatus</option><option v-for="item in statuses" :key="item.value" :value="item.value">{{ item.label }}</option></select><select v-model="campus" class="rounded-lg border-slate-300"><option value="">Todos los campus</option><option v-for="item in campuses" :key="item.id" :value="item.id">{{ item.name }}</option></select></div><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="border-b text-xs uppercase text-slate-500"><tr><th class="p-3">Estudiante</th><th class="p-3">Matrícula</th><th class="p-3">Campus</th><th class="p-3">Carrera</th><th class="p-3">Estatus</th><th /></tr></thead><tbody><tr v-for="profile in students.data" :key="profile.id" class="border-b last:border-0"><td class="p-3"><strong>{{ profile.user?.name }}</strong><small class="block text-slate-500">{{ profile.user?.email }}</small></td><td class="p-3">{{ profile.enrollment_number }}</td><td class="p-3">{{ profile.campus?.name }}</td><td class="p-3">{{ profile.academic_program?.name }}</td><td class="p-3">{{ profile.academic_status?.label ?? profile.academic_status }}</td><td class="p-3"><Link :href="`/students/${profile.user_id}/edit`" class="font-semibold text-[#0284C7]">Editar</Link></td></tr><tr v-if="!students.data.length"><td colspan="6" class="p-8 text-center text-slate-500">No hay perfiles registrados.</td></tr></tbody></table></div><div class="mt-5 flex flex-wrap gap-2"><Link v-for="link in students.links" :key="link.label" :href="link.url ?? ''" v-html="link.label" class="rounded border px-3 py-1 text-sm" :class="{ 'bg-[#00338D] text-white': link.active, 'pointer-events-none opacity-40': !link.url }" /></div></section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
