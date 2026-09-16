<script setup>
import { Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import PanelLateral from '@/Components/Panellateral.vue'; // Importación corregida
import { ref, onMounted } from 'vue';
import axios from 'axios';

const datos = ref(null);
const cargando = ref(true);

// Control del panel lateral
const mostrarPanelReporte = ref(false);

// Formulario para el nuevo reporte
const formReporte = ref({
    titulo: '',
    descripcion: '',
    fecha_inicio: '',
    fecha_fin: ''
});

onMounted(async () => {
    try {
        const respuesta = await axios.get('/api/transparencia');
        datos.value = respuesta.data;
    } catch (error) {
        console.error("Error al cargar transparencia", error);
    } finally {
        cargando.value = false;
    }
});

const formatearFecha = (fechaString) => {
    if (!fechaString) return '';
    const opciones = { day: 'numeric', month: 'long', year: 'numeric' };
    return new Date(fechaString).toLocaleDateString('es-MX', opciones);
};

// Función para procesar el guardado
const generarReporte = () => {
    console.log("Datos del reporte:", formReporte.value);
    alert("¡Aquí se calcularán los datos agregados y se enviarán vía POST!");
};
</script>

<template>
    <Head title="Transparencia - Campus Digital" />

    <Modulo6Layout headerTitle="Transparencia y Rendición de Cuentas">
        
        <template #headerActions>
            <!-- Botón conectado al panel -->
            <button @click="mostrarPanelReporte = true" class="bg-[#00378c] text-white font-semibold py-1.5 px-4 rounded-lg hover:bg-[#002866] transition flex items-center">
                <span class="mr-2">📄</span> Generar Nuevo Reporte
            </button>
        </template>

        <div class="p-8 space-y-6">
            
            <div v-if="cargando" class="flex justify-center py-12">
                <p class="text-[#00378c] font-bold animate-pulse">Cargando reportes públicos...</p>
            </div>

            <div v-else-if="datos" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- Reportes Públicos -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Reportes Públicos Publicados</h3>
                    <p class="text-sm text-gray-500 mb-6">Información estadística generada a partir de los datos agregados. Los datos personales de los estudiantes se mantienen ocultos.</p>
                    
                    <div class="space-y-6">
                        
                        <div v-for="reporte in datos.reportes" :key="reporte.id" class="border border-gray-200 rounded-lg p-5 bg-gray-50 relative">
                            <div class="absolute top-4 right-4 text-gray-400 hover:text-[#00378c] cursor-pointer">⬇️ PDF</div>
                            <h4 class="font-bold text-lg text-[#002866]">{{ reporte.titulo }}</h4>
                            <p class="text-xs text-gray-500 mb-4">Publicado el {{ formatearFecha(reporte.publicado_en) }}</p>
                            
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-center">
                                <div v-for="(valor, llave) in reporte.datos" :key="llave" class="bg-white p-3 rounded shadow-sm border border-gray-100">
                                    <p class="text-xs text-gray-500 font-bold uppercase">{{ llave }}</p>
                                    <p class="text-xl font-bold text-gray-800">{{ valor }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="datos.reportes.length === 0" class="text-center text-gray-500 text-sm">
                            No hay reportes publicados.
                        </div>
                    </div>
                </div>

                <!-- Resultados de Votaciones -->
                <div class="space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-center mb-4 border-b pb-2">
                            <h3 class="text-lg font-bold text-gray-800">Resultados de Votaciones</h3>
                            <span class="bg-gray-100 text-gray-600 text-xs font-bold px-2 py-1 rounded">Histórico</span>
                        </div>
                        
                        <div v-if="datos.eleccion">
                            <div class="mb-2">
                                <h4 class="font-bold text-gray-800">{{ datos.eleccion.titulo }}</h4>
                                <p class="text-xs text-gray-500">Participación: {{ datos.totalVotos }} votos emitidos ({{ datos.eleccion.criterios_votantes }})</p>
                            </div>
                            
                            <div v-for="(resultado, index) in datos.resultados" :key="resultado.id" class="mt-4">
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="font-semibold text-gray-700">{{ resultado.planilla }}</span>
                                    <span :class="index === 0 ? 'text-[#00378c]' : 'text-gray-500'" class="font-bold">
                                        {{ resultado.votos }} votos ({{ resultado.porcentaje }}%)
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3">
                                    <div :class="index === 0 ? 'bg-[#00378c]' : 'bg-gray-400'" class="h-3 rounded-full transition-all duration-1000" :style="{ width: resultado.porcentaje + '%' }"></div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center text-gray-500 text-sm py-4">
                            No hay elecciones registradas.
                        </div>

                        <div class="mt-6 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-[#002866] flex items-start">
                            <span class="mr-2 text-lg">🛡️</span>
                            <p><strong>Auditoría Electoral:</strong> El sistema garantizó un voto por estudiante mediante la llave única de la tabla de votos.</p>
                        </div>
                    </div>

                    <!-- Módulo de Auditoría -->
                    <div class="bg-gray-900 rounded-xl shadow-sm border border-gray-800 p-6 text-white">
                        <h3 class="font-bold text-lg mb-2">Registro de Auditoría (Logs)</h3>
                        <p class="text-gray-400 text-sm mb-4">Todas las asignaciones de becas y recargas han sido firmadas criptográficamente.</p>
                        <div class="bg-gray-800 rounded p-3 font-mono text-xs text-green-400">
                            > [18:04:22] CONEXIÓN API - ESTADO: ESTABLE<br>
                            > [17:30:10] MÓDULO 6 - VISTAS: 100% DINÁMICAS<br>
                            > [16:15:05] DATA GRIP - SINCRONIZADO
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PANEL LATERAL PARA NUEVO REPORTE -->
        <PanelLateral 
            :show="mostrarPanelReporte" 
            titulo="Generar Reporte de Transparencia" 
            @close="mostrarPanelReporte = false"
        >
            <form @submit.prevent="generarReporte" class="space-y-4">
                <div class="bg-blue-50 text-[#002866] p-3 rounded-lg text-sm border border-blue-100 mb-4 flex items-start">
                    <span class="mr-2">💡</span>
                    El sistema calculará automáticamente los fondos, eventos y becas entregadas dentro del periodo seleccionado.
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Título del Reporte</label>
                    <input v-model="formReporte.titulo" type="text" placeholder="Ej. Reporte Semestral Enero-Junio" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Descripción / Notas Adicionales</label>
                    <textarea v-model="formReporte.descripcion" rows="3" placeholder="Contexto sobre los gastos e ingresos..." class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Periodo de Inicio</label>
                        <input v-model="formReporte.fecha_inicio" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Periodo de Fin</label>
                        <input v-model="formReporte.fecha_fin" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                    </div>
                </div>
            </form>
            
            <template #footer>
                <button @click="generarReporte" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866] transition shadow-sm">
                    Calcular y Publicar
                </button>
            </template>
        </PanelLateral>

    </Modulo6Layout>
</template>