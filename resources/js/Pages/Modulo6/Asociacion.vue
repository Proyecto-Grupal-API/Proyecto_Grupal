<script setup>
import { Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import PanelLateral from '@/Components/Panellateral.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';

const organizaciones = ref([]);
const miembros = ref([]); 
const roles = ref([]); 

const cargando = ref(true);
const mostrarPanelMiembro = ref(false);
const mostrarPanelPerfil = ref(false);
const mostrarPanelRol = ref(false);

const formMiembro = ref({ matricula: '', rol_interno: 'Estudiante Activo', fecha_inicio: '' });
const formPerfil = ref({ nombre: '', descripcion: '', email: '', telefono: '' });
const formRol = ref({ usuario_id: '', slug_rol: 'presidencia', fecha_inicio: '' });

const cargarDatos = async () => {
    try {
        const respuesta = await axios.get('/api/organizaciones');
        organizaciones.value = respuesta.data.data; 
        miembros.value = respuesta.data.miembros; 
        roles.value = respuesta.data.roles;
        
        if(organizaciones.value.length > 0) {
            const org = organizaciones.value[0];
            formPerfil.value = {
                nombre: org.nombre,
                descripcion: org.descripcion || '',
                email: org.email || '',
                telefono: org.telefono || ''
            };
        }
    } catch (error) {
        console.error("Error al conectar con la API:", error);
    }
};

onMounted(async () => {
    await cargarDatos();
    cargando.value = false;
});

const guardarMiembro = async () => {
    await axios.post('/api/organizaciones/miembros', formMiembro.value);
    mostrarPanelMiembro.value = false;
    formMiembro.value = { matricula: '', rol_interno: 'Estudiante Activo', fecha_inicio: '' };
    await cargarDatos();
};

const guardarPerfil = async () => {
    await axios.put('/api/organizaciones/perfil', formPerfil.value);
    mostrarPanelPerfil.value = false;
    await cargarDatos();
};

const guardarRol = async () => {
    await axios.post('/api/organizaciones/roles', formRol.value);
    mostrarPanelRol.value = false;
    formRol.value = { usuario_id: '', slug_rol: 'presidencia', fecha_inicio: '' };
    await cargarDatos();
};

const abrirPanelRol = (modoEdicion = false, rolActual = null) => {
    if(modoEdicion && rolActual) {
        formRol.value.slug_rol = rolActual.slug_rol;
        formRol.value.usuario_id = rolActual.usuario_id;
        formRol.value.fecha_inicio = rolActual.fecha_inicio ? rolActual.fecha_inicio.split('T')[0] : '';
    } else {
        formRol.value = { usuario_id: '', slug_rol: 'presidencia', fecha_inicio: '' };
    }
    mostrarPanelRol.value = true;
};

// NUEVO: Funciones para eliminar (Soft Delete)
const eliminarMiembro = async (id) => {
    if (confirm("¿Estás seguro de que deseas dar de baja a este miembro de la organización?")) {
        try {
            await axios.delete(`/api/organizaciones/miembros/${id}`);
            await cargarDatos();
        } catch (error) {
            console.error("Error eliminando miembro:", error);
        }
    }
};

const eliminarRol = async (id) => {
    if (confirm("¿Estás seguro de remover este cargo directivo?")) {
        try {
            await axios.delete(`/api/organizaciones/roles/${id}`);
            await cargarDatos();
        } catch (error) {
            console.error("Error eliminando rol:", error);
        }
    }
};
</script>

<template>
    <Head title="Mi Asociación - Campus Digital" />

    <Modulo6Layout headerTitle="Gestión de Organización">
        <div class="p-8 space-y-6">
            
            <div v-if="cargando" class="flex justify-center items-center py-12">
                <p class="text-[#00378c] font-bold animate-pulse">Conectando con SQL Server...</p>
            </div>

            <div v-else-if="organizaciones.length > 0" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex justify-between items-center">
                <div class="flex items-center space-x-6">
                    <div class="w-20 h-20 bg-blue-100 rounded-lg flex items-center justify-center text-[#00378c] text-3xl font-bold border-2 border-blue-200 uppercase">
                        {{ organizaciones[0].nombre ? organizaciones[0].nombre.substring(0, 2) : 'AS' }}
                    </div>
                    <div>
                        <div class="flex items-center space-x-3 mb-1">
                            <h1 class="text-2xl font-bold text-gray-800">{{ organizaciones[0].nombre || 'Sin Nombre' }}</h1>
                            <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-1 rounded-full border border-green-200">Activa</span>
                        </div>
                        <p class="text-gray-500">ID en Base de Datos: {{ organizaciones[0].id }}</p>
                        <p class="text-sm text-gray-600 mt-1 block">{{ organizaciones[0].descripcion || 'Sin descripción registrada' }}</p>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <button @click="mostrarPanelPerfil = true" class="bg-white border border-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg hover:bg-gray-50 transition">
                        Editar Perfil
                    </button>
                    <button @click="mostrarPanelMiembro = true" class="bg-[#00378c] text-white font-semibold py-2 px-4 rounded-lg hover:bg-[#002866] transition">
                        + Añadir Miembro
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- MESA DIRECTIVA -->
                <div class="col-span-1 space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Mesa Directiva (Roles)</h3>
                        
                        <div class="space-y-4">
                            <div v-for="rol in roles" :key="rol.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100">
                                <div>
                                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">{{ rol.slug_rol }}</p>
                                    <p class="text-sm font-semibold text-gray-800">Matrícula: {{ rol.usuario_id }}</p>
                                </div>
                                <div class="flex space-x-3">
                                    <button @click="abrirPanelRol(true, rol)" class="text-gray-400 hover:text-[#00378c] transition transform hover:scale-110">✏️</button>
                                    <!-- BOTÓN DE ELIMINAR ROL -->
                                    <button @click="eliminarRol(rol.id)" class="text-gray-400 hover:text-red-500 transition transform hover:scale-110" title="Remover cargo">🗑️</button>
                                </div>
                            </div>
                            
                            <div v-if="roles.length === 0" class="text-sm text-gray-500 text-center py-2">
                                No hay roles asignados.
                            </div>
                        </div>

                        <button @click="abrirPanelRol(false)" class="w-full mt-4 text-[#00378c] text-sm font-bold hover:underline">
                            + Asignar nuevo rol
                        </button>
                    </div>
                </div>

                <!-- DIRECTORIO DE MIEMBROS -->
                <div class="col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col">
                    <div class="flex justify-between items-center mb-4 border-b pb-2">
                        <h3 class="text-lg font-bold text-gray-800">Directorio de Miembros Activos</h3>
                    </div>
                    
                    <div class="flex-1 overflow-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Matrícula / Usuario ID</th>
                                    <th class="px-4 py-3 font-medium">Rol Interno</th>
                                    <th class="px-4 py-3 font-medium text-center">Estado</th>
                                    <th class="px-4 py-3 font-medium text-right">Acciones</th> <!-- Nueva columna -->
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="miembro in miembros" :key="miembro.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-semibold text-gray-800">Matrícula: {{ miembro.usuario_id }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ miembro.rol_interno }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span :class="miembro.estado === 'activo' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" class="text-xs font-bold px-2 py-1 rounded capitalize">
                                            {{ miembro.estado }}
                                        </span>
                                    </td>
                                    <!-- BOTÓN DE ELIMINAR MIEMBRO -->
                                    <td class="px-4 py-3 text-right">
                                        <button @click="eliminarMiembro(miembro.id)" class="text-gray-400 hover:text-red-500 transition" title="Dar de baja">
                                            🗑️
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="miembros.length === 0">
                                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">No hay miembros registrados.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <PanelLateral :show="mostrarPanelMiembro" titulo="Añadir Nuevo Miembro" @close="mostrarPanelMiembro = false">
            <form @submit.prevent="guardarMiembro" class="space-y-4">
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Matrícula / Número de Control</label><input v-model="formMiembro.matricula" type="number" required class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Rol Interno</label><select v-model="formMiembro.rol_interno" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"><option value="Estudiante Activo">Estudiante Activo</option><option value="Presidente">Presidente</option><option value="Tesorero">Tesorero</option><option value="Secretario">Secretario</option><option value="Vocal">Vocal</option></select></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Fecha de Ingreso</label><input v-model="formMiembro.fecha_inicio" type="date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"></div>
            </form>
            <template #footer><button @click="guardarMiembro" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866]">Registrar</button></template>
        </PanelLateral>

        <PanelLateral :show="mostrarPanelPerfil" titulo="Configuración de la Asociación" @close="mostrarPanelPerfil = false">
            <form @submit.prevent="guardarPerfil" class="space-y-4">
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Nombre Oficial</label><input v-model="formPerfil.nombre" type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Descripción</label><textarea v-model="formPerfil.descripcion" rows="4" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></textarea></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Correo Electrónico</label><input v-model="formPerfil.email" type="email" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></div>
            </form>
            <template #footer><button @click="guardarPerfil" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866]">Guardar Cambios</button></template>
        </PanelLateral>

        <PanelLateral :show="mostrarPanelRol" titulo="Gestión de Roles Directivos" @close="mostrarPanelRol = false">
            <form @submit.prevent="guardarRol" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Estudiante (Miembro Activo)</label>
                    <select v-model="formRol.usuario_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]">
                        <option value="" disabled>Selecciona un miembro...</option>
                        <option v-for="m in miembros" :key="m.id" :value="m.usuario_id">Matrícula: {{ m.usuario_id }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Cargo a Asignar</label>
                    <select v-model="formRol.slug_rol" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]">
                        <option value="presidencia">Presidencia</option>
                        <option value="vicepresidencia">Vicepresidencia</option>
                        <option value="tesoreria">Tesorería</option>
                        <option value="secretaria">Secretaría General</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fecha de Inicio de Cargo</label>
                    <input v-model="formRol.fecha_inicio" type="date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]">
                </div>
            </form>
            <template #footer><button @click="guardarRol" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866]">Asignar Cargo</button></template>
        </PanelLateral>

    </Modulo6Layout>
</template>