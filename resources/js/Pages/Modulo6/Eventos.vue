<script setup>
import { Head } from '@inertiajs/vue3';
import Modulo6Layout from '@/Layouts/Modulo6Layout.vue';
import PanelLateral from '@/Components/Panellateral.vue';
import { ref, onMounted } from 'vue';
import axios from 'axios';

const eventos = ref([]);
const eventoSeleccionado = ref(null); // Aquí guardaremos el evento que estamos viendo actualmente
const cargando = ref(true);
const mostrarPanelCrear = ref(false); 

const tokenEscaneado = ref('');
const mensajeScanner = ref('');
const tipoMensaje = ref(''); 

const formEvento = ref({
    titulo: '', descripcion: '', ubicacion: '', 
    fecha_hora_inicio: '', fecha_hora_fin: '', costo: 0, capacidad: 100
});

const cargarDatos = async () => {
    try {
        const respuesta = await axios.get('/api/eventos');
        eventos.value = respuesta.data.eventos;
        
        // Si hay eventos, seleccionamos el primero por defecto o actualizamos el que ya estaba seleccionado
        if(eventos.value.length > 0) {
            if(!eventoSeleccionado.value) {
                eventoSeleccionado.value = eventos.value[0];
            } else {
                eventoSeleccionado.value = eventos.value.find(e => e.id === eventoSeleccionado.value.id) || eventos.value[0];
            }
        } else {
            eventoSeleccionado.value = null;
        }
    } catch (error) {
        console.error("Error al cargar eventos:", error);
    }
};

onMounted(async () => {
    await cargarDatos();
    cargando.value = false;
});

const formatearFecha = (fechaString) => {
    if (!fechaString) return '';
    const opciones = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(fechaString).toLocaleDateString('es-MX', opciones);
};

const guardarEvento = async () => {
    try {
        await axios.post('/api/eventos', formEvento.value);
        mostrarPanelCrear.value = false;
        formEvento.value = { titulo: '', descripcion: '', ubicacion: '', fecha_hora_inicio: '', fecha_hora_fin: '', costo: 0, capacidad: 100 };
        await cargarDatos();
    } catch (error) {
        alert("Fallo en SQL Server: " + (error.response?.data?.error || "Error desconocido"));
    }
};

const procesarCheckin = async () => {
    if(!tokenEscaneado.value.trim() || !eventoSeleccionado.value) return;
    
    try {
        // Ahora enviamos el token Y el ID del evento que estamos gestionando
        const respuesta = await axios.post('/api/eventos/checkin', { 
            token_qr: tokenEscaneado.value,
            evento_id: eventoSeleccionado.value.id
        });
        mensajeScanner.value = respuesta.data.message;
        tipoMensaje.value = 'success';
        await cargarDatos(); 
    } catch (error) {
        mensajeScanner.value = error.response?.data?.error || "Error al procesar el pase";
        tipoMensaje.value = 'error';
    } finally {
        tokenEscaneado.value = ''; 
        setTimeout(() => { mensajeScanner.value = ''; }, 4000);
    }
};
</script>

<template>
    <Head title="Eventos y Check-in - Campus Digital" />

    <Modulo6Layout headerTitle="Gestión de Eventos y Asistencia">
        <template #headerActions>
            <button @click="mostrarPanelCrear = true" class="bg-[#00378c] text-white font-semibold py-1.5 px-4 rounded-lg hover:bg-[#002866] transition">
                + Crear Evento
            </button>
        </template>

        <div class="p-8 space-y-6">
            <div v-if="cargando" class="flex justify-center py-12">
                <p class="text-[#00378c] font-bold animate-pulse">Cargando eventos desde SQL Server...</p>
            </div>

            <div v-else-if="eventos.length > 0">
                
                <!-- NUEVO: SELECTOR DE EVENTOS (PESTAÑAS) -->
                <div class="flex space-x-3 overflow-x-auto pb-4 mb-2">
                    <button 
                        v-for="evt in eventos" 
                        :key="evt.id" 
                        @click="eventoSeleccionado = evt"
                        :class="eventoSeleccionado.id === evt.id ? 'bg-[#00378c] text-white ring-2 ring-blue-300' : 'bg-white text-gray-600 border border-gray-300 hover:bg-gray-50'"
                        class="px-5 py-2.5 rounded-full text-sm font-bold shadow-sm whitespace-nowrap transition-all"
                    >
                        {{ evt.titulo }}
                    </button>
                </div>

                <div v-if="eventoSeleccionado" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Tarjeta del Evento -->
                    <div class="col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <span class="bg-red-100 text-red-800 text-xs font-bold px-2.5 py-1 rounded-full animate-pulse mr-2">EN GESTIÓN</span>
                                <h3 class="text-2xl font-bold text-gray-800 inline-block">{{ eventoSeleccionado.titulo }}</h3>
                                <p class="text-gray-500 mt-1">📍 {{ eventoSeleccionado.ubicacion }} | 📅 {{ formatearFecha(eventoSeleccionado.fecha_hora_inicio) }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-gray-500 font-bold uppercase">Costo</p>
                                <p class="text-xl font-bold text-[#00378c]">
                                    {{ parseFloat(eventoSeleccionado.costo) === 0 ? 'GRATIS' : '$' + eventoSeleccionado.costo + ' MXN' }}
                                </p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-600 mb-6">{{ eventoSeleccionado.descripcion }}</p>

                        <!-- Barra de Asistencia -->
                        <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex justify-between text-sm text-gray-700 mb-2 font-semibold">
                                <span>Asistencia Total: {{ eventoSeleccionado.stats.asistieron }} de {{ eventoSeleccionado.stats.capacidad }} estudiantes</span>
                                <span>{{ Math.round((eventoSeleccionado.stats.asistieron / eventoSeleccionado.stats.capacidad) * 100) }}% Capacidad</span>
                            </div>
                            <div class="w-full bg-gray-300 rounded-full h-3">
                                <div class="bg-[#00378c] h-3 rounded-full transition-all duration-1000" :style="{ width: (eventoSeleccionado.stats.asistieron / eventoSeleccionado.stats.capacidad) * 100 + '%' }"></div>
                            </div>
                        </div>
                    </div>

                    <!-- MÓDULO DE ESCÁNER DINÁMICO -->
                    <div class="col-span-1 bg-gray-900 rounded-xl shadow-sm border border-gray-800 p-6 flex flex-col items-center justify-center text-center relative overflow-hidden">
                        <div class="absolute inset-0 opacity-10 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCI+PGNpcmNsZSBjeD0iMSIgY3k9IjEiIHI9IjEiIGZpbGw9IiNmZmYiLz48L3N2Zz4=')]"></div>
                        
                        <div class="z-10 w-20 h-20 bg-gray-800 border-4 border-[#00378c] border-dashed rounded-xl flex items-center justify-center mb-4 transition-transform duration-300" :class="{'scale-110 border-green-500': tipoMensaje === 'success', 'scale-110 border-red-500': tipoMensaje === 'error'}">
                            <span class="text-4xl">📷</span>
                        </div>
                        <h3 class="text-white font-bold text-lg z-10">Escáner Activo</h3>
                        <p class="text-gray-400 text-xs mt-1 mb-4 z-10">Acerca el QR o teclea el token manual</p>
                        
                        <form @submit.prevent="procesarCheckin" class="z-10 w-full relative">
                            <input 
                                v-model="tokenEscaneado" 
                                type="text" 
                                placeholder="Ej. QR-1234-ABC" 
                                class="w-full bg-gray-800 border border-gray-700 text-white rounded-lg px-4 py-3 text-center tracking-widest font-mono text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 mb-3"
                                autocomplete="off"
                            >
                            <button type="submit" class="bg-[#00378c] hover:bg-[#002866] text-white font-bold py-2.5 px-6 rounded-lg w-full transition shadow-md">
                                Validar Acceso
                            </button>
                        </form>

                        <div v-if="mensajeScanner" :class="tipoMensaje === 'success' ? 'bg-green-500/20 text-green-400 border-green-500/50' : 'bg-red-500/20 text-red-400 border-red-500/50'" class="z-10 w-full mt-4 p-3 rounded-lg border text-sm font-bold shadow-lg">
                            {{ mensajeScanner }}
                        </div>
                    </div>

                    <!-- Tabla de Lista -->
                    <div class="col-span-1 lg:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex justify-between items-center mb-4 border-b pb-4">
                            <h3 class="text-lg font-bold text-gray-800">Lista de Inscritos a: {{ eventoSeleccionado.titulo }} ({{ eventoSeleccionado.stats.total }})</h3>
                        </div>
                        <div class="overflow-auto">
                            <table class="min-w-full text-sm text-left">
                                <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Boleto (Token)</th>
                                        <th class="px-4 py-3 font-medium">ID Usuario</th>
                                        <th class="px-4 py-3 font-medium">Pago</th>
                                        <th class="px-4 py-3 font-medium text-right">Asistencia</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="inscrito in eventoSeleccionado.inscritos" :key="inscrito.id" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-500">#{{ inscrito.token_qr || 'SIN-TOKEN' }}</td>
                                        <td class="px-4 py-3 font-semibold text-gray-800">Usuario ID: {{ inscrito.usuario_id }}</td>
                                        <td class="px-4 py-3">
                                            <span :class="inscrito.estado_pago === 'pagado' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" class="text-xs px-2 py-1 rounded capitalize">
                                                {{ inscrito.estado_pago }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <span v-if="inscrito.estado_asistencia === 'asistio'" class="bg-blue-100 text-[#00378c] text-xs font-bold px-2 py-1 rounded inline-flex items-center">
                                                ✅ Asistió
                                            </span>
                                            <span v-else class="bg-gray-100 text-gray-600 text-xs font-bold px-2 py-1 rounded inline-flex items-center capitalize">
                                                ⏳ {{ inscrito.estado_asistencia }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="eventoSeleccionado.inscritos.length === 0">
                                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">No hay inscritos aún.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div v-else class="bg-yellow-50 border border-yellow-200 p-6 rounded-xl text-yellow-800 font-semibold text-center">
                No hay eventos activos publicados. ¡Crea uno nuevo!
            </div>
        </div>

        <PanelLateral :show="mostrarPanelCrear" titulo="Crear Nuevo Evento" @close="mostrarPanelCrear = false">
            <form @submit.prevent="guardarEvento" class="space-y-4">
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Título del Evento</label><input v-model="formEvento.titulo" required type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Descripción</label><textarea v-model="formEvento.descripcion" required rows="3" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></textarea></div>
                <div><label class="block text-sm font-bold text-gray-700 mb-1">Ubicación</label><input v-model="formEvento.ubicacion" required type="text" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:border-[#00378c]"></div>
                <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-bold text-gray-700 mb-1">Fecha Inicio</label><input v-model="formEvento.fecha_hora_inicio" required type="datetime-local" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"></div><div><label class="block text-sm font-bold text-gray-700 mb-1">Fecha Fin</label><input v-model="formEvento.fecha_hora_fin" required type="datetime-local" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"></div></div>
                <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-bold text-gray-700 mb-1">Costo (MXN)</label><input v-model="formEvento.costo" type="number" min="0" step="0.5" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"></div><div><label class="block text-sm font-bold text-gray-700 mb-1">Aforo Máximo</label><input v-model="formEvento.capacidad" type="number" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:border-[#00378c]"></div></div>
            </form>
            <template #footer><button @click="guardarEvento" class="px-4 py-2 bg-[#00378c] rounded-lg text-sm font-bold text-white hover:bg-[#002866]">Guardar Evento</button></template>
        </PanelLateral>

    </Modulo6Layout>
</template>