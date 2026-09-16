<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import PanelLateral from '@/Components/Panellateral.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';

const datos = ref(null);
const cargando = ref(true);

// Variable para controlar el panel de la nueva convocatoria
const mostrarPanelBeca = ref(false);

// Objeto para el formulario
const formBeca = ref({
    titulo: '',
    descripcion: '',
    tipo_beneficio_id: '1', // 1: Monetaria, 2: Servicio
    monto: '',
    fecha_inicio: '',
    fecha_fin: '',
    total_espacios: ''
});

onMounted(async () => {
    try {
        const respuesta = await axios.get('/api/becas');
        datos.value = respuesta.data;
    } catch (error) {
        console.error("Error al cargar becas desde la API", error);
    } finally {
        cargando.value = false;
    }
});

const formatearFechaCierre = (fechaString) => {
    if (!fechaString) return '';
    const opciones = { day: 'numeric', month: 'long', year: 'numeric' };
    return new Date(fechaString).toLocaleDateString('es-MX', opciones);
};

// Función para simular el guardado por ahora
const guardarBeca = () => {
    console.log("Datos de la convocatoria:", formBeca.value);
    alert("¡Aquí enviaremos la convocatoria a la API vía POST!");
};
</script>

<template>
    <Head title="Becas y Apoyos - Campus Digital" />

    <Modulo6Layout headerTitle="Gestión de Becas y Apoyos">
        
        <template #headerActions>
            <!-- Conectamos el botón para abrir el panel -->
            <button @click="mostrarPanelBeca = true" class="bg-[#00378c] text-white font-semibold py-1.5 px-4 rounded-lg hover:bg-[#002866] transition">
                + Nueva Convocatoria
            </button>
        </template>

        <div class="p-8 space-y-6">
            
            <div v-if="cargando" class="flex justify-center py-12">
                <p class="text-[#00378c] font-bold animate-pulse">Cargando becas desde SQL Server...</p>
            </div>

            <div v-else-if="datos">
                <!-- Convocatorias Activas -->
                <h3 class="text-lg font-bold text-gray-800">Convocatorias Activas</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                    
                    <div v-for="convocatoria in datos.convocatorias" :key="convocatoria.id" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 relative overflow-hidden">
                        <div :class="convocatoria.es_monetario ? 'bg-blue-500' : 'bg-purple-500'" class="absolute top-0 right-0 text-white text-xs font-bold px-3 py-1 rounded-bl-lg uppercase">
                            BENEFICIO {{ convocatoria.es_monetario ? 'MONETARIO' : 'DE SERVICIO' }}
                        </div>
                        
                        <h4 class="text-xl font-bold text-gray-800 mt-2">{{ convocatoria.titulo }}</h4>
                        <p class="text-sm text-gray-500 mt-1">{{ convocatoria.descripcion }}</p>
                        
                        <div class="mt-4 flex items-center justify-between text-sm">
                            <span class="font-semibold text-gray-700">Espacios: {{ convocatoria.espacios_ocupados }} / {{ convocatoria.total_espacios || 'Ilimitados' }}</span>
                            <span :class="convocatoria.es_monetario ? 'text-blue-600' : 'text-purple-600'" class="font-bold">
                                {{ convocatoria.porcentaje }}%
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1 mb-4">
                            <div :class="convocatoria.es_monetario ? 'bg-blue-500' : 'bg-purple-500'" class="h-2 rounded-full transition-all duration-1000" :style="{ width: convocatoria.porcentaje + '%' }"></div>
                        </div>
                        
                        <div class="flex justify-between items-center border-t pt-4 mt-4">
                            <span class="text-xs text-gray-400">Cierra: {{ formatearFechaCierre(convocatoria.fecha_fin) }}</span>
                            <button class="text-sm font-semibold text-[#00378c] hover:underline">Ver detalles</button>
                        </div>
                    </div>

                    <div v-if="datos.convocatorias.length === 0" class="col-span-full bg-yellow-50 p-6 rounded-lg text-yellow-800 text-center text-sm font-semibold">
                        No hay convocatorias activas en este momento.
                    </div>
                </div>

                <!-- Panel de Dictaminación de Solicitudes -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
                    <div class="flex justify-between items-center mb-4 border-b pb-4">
                        <h3 class="text-lg font-bold text-gray-800">Panel de Dictaminación (Solicitudes)</h3>
                        <div class="flex space-x-3">
                            <select class="border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-[#00378c]">
                                <option>Todos los estados</option>
                                <option>Pendientes</option>
                                <option>Aprobadas</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="overflow-auto">
                        <table class="min-w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Estudiante</th>
                                    <th class="px-4 py-3 font-medium">Convocatoria</th>
                                    <th class="px-4 py-3 font-medium text-center">Documentos</th>
                                    <th class="px-4 py-3 font-medium">Estado</th>
                                    <th class="px-4 py-3 font-medium text-right">Acción (Dictamen)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="solicitud in datos.solicitudes" :key="solicitud.id" :class="{'bg-gray-50/50': solicitud.estado !== 'pendiente'}" class="hover:bg-gray-50">
                                    <td class="px-4 py-4">
                                        <p class="font-semibold text-gray-800">Alumno ID: {{ solicitud.usuario_id }}</p>
                                        <p class="text-xs text-gray-500">Datos protegidos</p>
                                    </td>
                                    <td class="px-4 py-4 text-gray-600 font-medium">{{ solicitud.convocatoria }}</td>
                                    
                                    <td class="px-4 py-4 text-center">
                                        <button v-if="solicitud.requiere_documentos" class="text-blue-600 hover:underline font-semibold">
                                            📄 Revisar
                                        </button>
                                        <span v-else class="text-gray-400 text-xs">No aplica</span>
                                    </td>
                                    
                                    <td class="px-4 py-4">
                                        <span :class="{
                                            'bg-yellow-100 text-yellow-800': solicitud.estado === 'pendiente' || solicitud.estado === 'en_revision',
                                            'bg-green-100 text-green-800': solicitud.estado === 'aprobada' || solicitud.estado === 'otorgada',
                                            'bg-red-100 text-red-800': solicitud.estado === 'rechazada'
                                        }" class="text-xs px-2.5 py-1 font-bold rounded capitalize">
                                            {{ solicitud.estado.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    
                                    <td class="px-4 py-4 text-right">
                                        <div v-if="solicitud.estado === 'pendiente' || solicitud.estado === 'en_revision'" class="space-x-2">
                                            <button class="bg-red-50 text-red-600 hover:bg-red-100 px-3 py-1.5 rounded text-xs font-bold transition">Rechazar</button>
                                            <button class="bg-[#00378c] text-white hover:bg-[#002866] px-3 py-1.5 rounded text-xs font-bold transition shadow-sm">Aprobar</button>
                                        </div>
                                        <span v-else-if="solicitud.estado === 'aprobada'" class="text-xs text-gray-500 font-semibold flex items-center justify-end">
                                            🔗 Beneficio Activo
                                        </span>
                                        <span v-else class="text-xs text-gray-400 font-semibold">
                                            Cerrada
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="datos.solicitudes.length === 0">
                                    <td colspan="5" class="px-4 py-6 text-center text-gray-500">No hay solicitudes registradas.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- PANEL LATERAL PARA NUEVA BECA -->
        <PanelLateral 
            :show="mostrarPanelBeca" 
            titulo="Lanzar Nueva Convocatoria" 
            @close="mostrarPanelBeca = false"
        >
            <form @submit.prevent="guardarBeca" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Título de la Convocatoria</label>
                    <input v-model="formBeca.titulo" type="text" placeholder="Ej. Beca de Materiales Básicos" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Descripción Breve</label>
                    <textarea v-model="formBeca.descripcion" rows="3" placeholder="Explica de qué trata el apoyo..." class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Tipo de Beneficio</label>
                        <select v-model="formBeca.tipo_beneficio_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                            <option value="1">Monetario (Dinero)</option>
                            <option value="2">Servicio (Locker, etc.)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Monto (Si aplica)</label>
                        <input v-model="formBeca.monto" type="number" min="0" placeholder="$0.00" :disabled="formBeca.tipo_beneficio_id === '2'" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c] disabled:bg-gray-100">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Fecha de Inicio</label>
                        <input v-model="formBeca.fecha_inicio" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Fecha Límite</label>
                        <input v-model="formBeca.fecha_fin" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Total de Espacios Disponibles</label>
                    <input v-model="formBeca.total_espacios" type="number" min="1" placeholder="Ej. 50" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                </div>
            </form>
            
            <template #footer>
                <button @click="guardarBeca" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866] transition shadow-sm">
                    Publicar Convocatoria
                </button>
            </template>
        </PanelLateral>

    </Modulo6Layout>
</template>