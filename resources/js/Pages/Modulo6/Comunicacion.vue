<script setup>
import { Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';

const datos = ref(null);
const cargando = ref(true);

onMounted(async () => {
    try {
        const respuesta = await axios.get('/api/comunicacion');
        datos.value = respuesta.data;
    } catch (error) {
        console.error("Error al cargar la comunicación desde la API", error);
    } finally {
        cargando.value = false;
    }
});
</script>

<template>
    <Head title="Comunicación - Campus Digital" />

    <Modulo6Layout headerTitle="Centro de Mensajería y Campañas">
        
        <div class="p-8 space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Redactar Nueva Campaña (Se mantiene como UI de Input) -->
                <div class="col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Redactar Nueva Campaña</h3>
                    
                    <form class="space-y-4" @submit.prevent="">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre Interno (No visible)</label>
                                <input type="text" placeholder="Ej. Invitación Hackathon 2026" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Audiencia Objetivo (Segmentación)</label>
                                <select class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-[#00378c] bg-blue-50 text-[#00378c] font-semibold border-blue-200">
                                    <option>🎯 Todos los alumnos de Sistemas</option>
                                    <option>Solo Miembros Activos</option>
                                    <option>Alumnos de 1er Semestre</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Asunto del Mensaje</label>
                            <input type="text" placeholder="¡No te pierdas el evento del año!" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cuerpo del Mensaje</label>
                            <textarea rows="4" placeholder="Escribe el contenido aquí..." class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]"></textarea>
                        </div>
                        
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex items-center space-x-4">
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-500 uppercase">Texto del Botón (Opcional)</label>
                                <input type="text" placeholder="Ej. Inscríbete aquí" class="w-full mt-1 border border-gray-300 rounded md px-3 py-1.5 text-sm focus:border-[#00378c] focus:ring-1 focus:ring-[#00378c]">
                            </div>
                            <div class="flex-1">
                                <label class="block text-xs font-bold text-gray-500 uppercase">Enlace (URI Acción)</label>
                                <input type="text" placeholder="/eventos/hackathon-2026" class="w-full mt-1 border border-gray-300 rounded md px-3 py-1.5 text-sm font-mono text-[#00378c] bg-blue-50 focus:border-[#00378c]">
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-2">
                            <button type="button" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-50">Guardar Borrador</button>
                            <button type="button" class="px-5 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866] flex items-center">
                                <span class="mr-2">🚀</span> Enviar Campaña Ahora
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Historial y Métricas -->
                <div class="col-span-1 space-y-6">
                    
                    <!-- Estado de carga para el panel derecho -->
                    <div v-if="cargando" class="flex justify-center items-center h-full">
                        <p class="text-[#00378c] font-bold animate-pulse">Cargando métricas...</p>
                    </div>

                    <div v-else-if="datos">
                        <!-- Widget de Encuestas -->
                        <div class="bg-gray-900 rounded-xl shadow-sm border border-gray-800 p-6 text-white relative overflow-hidden mb-6">
                            <div class="absolute -right-4 -top-4 opacity-20 text-6xl">📊</div>
                            <h3 class="font-bold text-lg relative z-10 mb-1">Módulo de Encuestas</h3>
                            <p class="text-gray-400 text-sm mb-4 relative z-10">Tienes {{ datos.encuestasActivas }} encuestas activas.</p>
                            <button class="w-full bg-[#00378c] text-white font-bold py-2 rounded-lg text-sm hover:bg-[#002866] transition relative z-10">
                                Crear Nueva Encuesta
                            </button>
                        </div>

                        <!-- Historial Dinámico -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Últimos Envíos</h3>
                            
                            <div class="space-y-4">
                                <div v-for="campana in datos.campanas" :key="campana.id" class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                                    <div class="flex justify-between items-start mb-2">
                                        <h4 class="font-bold text-sm text-gray-800">{{ campana.nombre }}</h4>
                                        <span :class="{
                                            'bg-green-100 text-green-800': campana.estado === 'enviada',
                                            'bg-yellow-100 text-yellow-800': campana.estado === 'borrador',
                                            'bg-gray-100 text-gray-800': campana.estado === 'programada'
                                        }" class="text-[10px] font-bold px-2 py-0.5 rounded uppercase">
                                            {{ campana.estado }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 mb-2">A: {{ campana.criterios_audiencia }}</p>
                                    
                                    <!-- Barra de lectura -->
                                    <div class="flex justify-between text-xs text-gray-600 mb-1 font-semibold">
                                        <span>Leídos ({{ campana.total_leidos }}/{{ campana.total_enviados }})</span>
                                        <span class="text-[#00378c]">{{ campana.tasa_lectura }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                        <div class="bg-[#00378c] h-1.5 rounded-full transition-all duration-1000" :style="{ width: campana.tasa_lectura + '%' }"></div>
                                    </div>
                                </div>
                                
                                <div v-if="datos.campanas.length === 0" class="text-sm text-gray-500 text-center py-4">
                                    No hay campañas registradas.
                                </div>
                            </div>
                            <button v-if="datos.campanas.length > 0" class="w-full mt-4 text-center text-sm font-semibold text-[#00378c] hover:underline">Ver todo el historial</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modulo6Layout>
</template>