<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';

const estadisticas = ref(null);
const cargando = ref(true);

onMounted(async () => {
    try {
        const respuesta = await axios.get('/api/dashboard');
        estadisticas.value = respuesta.data;
    } catch (error) {
        console.error("Error cargando el dashboard", error);
    } finally {
        cargando.value = false;
    }
});
</script>

<template>
    <Head title="Dashboard - Campus Digital" />

    <Modulo6Layout headerTitle="Panel de Control">
        <div class="p-8 space-y-6">
            
            <!-- Estado de carga -->
            <div v-if="cargando" class="flex justify-center items-center py-12">
                <p class="text-[#00378c] font-bold animate-pulse">Cargando métricas desde DataGrip (SQL Server)...</p>
            </div>

            <!-- Contenido Real -->
            <div v-else-if="estadisticas">
                
                <!-- Tarjetas de Métricas -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-blue-100 text-blue-600 rounded-lg text-2xl">👥</div>
                        <div>
                            <p class="text-sm text-gray-500">Miembros Activos</p>
                            <p class="text-2xl font-bold text-gray-800">{{ estadisticas.miembrosActivos }}</p>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-green-100 text-green-600 rounded-lg text-2xl">💰</div>
                        <div>
                            <p class="text-sm text-gray-500">Caja Disponible</p>
                            <p class="text-2xl font-bold text-gray-800">${{ estadisticas.cajaDisponible }}</p>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-purple-100 text-purple-600 rounded-lg text-2xl">🎟️</div>
                        <div>
                            <p class="text-sm text-gray-500">Eventos Activos</p>
                            <p class="text-2xl font-bold text-gray-800">{{ estadisticas.eventosActivos }}</p>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-orange-100 text-orange-600 rounded-lg text-2xl">📄</div>
                        <div>
                            <p class="text-sm text-gray-500">Becas Pendientes</p>
                            <p class="text-2xl font-bold text-gray-800">{{ estadisticas.becasPendientes }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- Próximo Evento -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Próximo Evento</h3>
                        
                        <div v-if="estadisticas.proximoEvento" class="border border-gray-200 rounded-lg p-4 bg-gray-50 mb-4">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <h4 class="font-bold text-[#002866]">{{ estadisticas.proximoEvento.nombre }}</h4>
                                    <p class="text-sm text-gray-500">📍 {{ estadisticas.proximoEvento.lugar }}</p>
                                </div>
                                <span class="bg-blue-100 text-[#00378c] text-xs font-semibold px-2.5 py-0.5 rounded">{{ estadisticas.proximoEvento.fecha }}</span>
                            </div>
                            <div class="mt-4">
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Asistencia (0/{{ estadisticas.proximoEvento.asistencia_total }})</span>
                                    <span>0%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-[#00378c] h-2 rounded-full" style="width: 0%"></div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-gray-500 text-sm py-4">No hay eventos programados.</div>
                        
                        <button class="w-full bg-[#00378c] hover:bg-[#002866] text-white font-bold py-3 px-4 rounded-lg flex justify-center items-center transition disabled:opacity-50">
                            <span class="mr-2">📷</span> Abrir Escáner QR / NFC
                        </button>
                    </div>

                    <!-- Solicitudes de Beca (DINÁMICAS) -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-gray-800">Revisión de Becas</h3>
                            <Link href="/modulo6/becas" class="text-sm text-[#00378c] hover:underline font-semibold">Ver todas</Link>
                        </div>
                        
                        <ul class="divide-y divide-gray-100">
                            <li v-for="beca in estadisticas.ultimasBecas" :key="beca.id" class="py-3 flex justify-between items-center">
                                <div>
                                    <p class="font-semibold text-gray-800">{{ beca.beca }}</p>
                                    <!-- Simulamos el nombre del alumno con el ID por ahora -->
                                    <p class="text-sm text-gray-500">Alumno ID: {{ beca.usuario_id }}</p>
                                </div>
                                <span :class="{
                                    'bg-yellow-100 text-yellow-800': beca.estado === 'pendiente',
                                    'bg-green-100 text-green-800': beca.estado === 'aprobada' || beca.estado === 'otorgada',
                                    'bg-gray-100 text-gray-800': beca.estado !== 'pendiente' && beca.estado !== 'aprobada'
                                }" class="text-xs font-semibold px-2.5 py-0.5 rounded capitalize">
                                    {{ beca.estado.replace('_', ' ') }}
                                </span>
                            </li>
                            <li v-if="estadisticas.ultimasBecas.length === 0" class="py-3 text-sm text-gray-500 text-center">
                                No hay solicitudes recientes.
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>
    </Modulo6Layout>
</template>