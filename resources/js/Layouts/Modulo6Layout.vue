<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, onMounted } from 'vue';
import Panellateral from '@/Components/Panellateral.vue';
import axios from 'axios';

const props = defineProps({
    headerTitle: {
        type: String,
        default: 'Panel de Control'
    }
});

const page = usePage();
const currentUrl = computed(() => page.url);

const mostrarNotificaciones = ref(false);
const notificaciones = ref([]);

const cargarNotificaciones = async () => {
    try {
        const respuesta = await axios.get('/api/notificaciones');
        notificaciones.value = respuesta.data;
    } catch (error) {
        console.error("Error al cargar notificaciones:", error);
    }
};

onMounted(() => {
    cargarNotificaciones();
});

const marcarComoLeidas = async () => {
    try {
        await axios.put('/api/notificaciones/leer');
        await cargarNotificaciones(); 
    } catch (error) {
        console.error("Error al actualizar notificaciones:", error);
    }
};

// NUEVO: Dispara el DELETE a tu API
const eliminarLeidas = async () => {
    if (confirm("¿Estás seguro de que deseas limpiar todas las notificaciones leídas?")) {
        try {
            await axios.delete('/api/notificaciones/leidas');
            await cargarNotificaciones(); 
        } catch (error) {
            console.error("Error al eliminar notificaciones:", error);
        }
    }
};
</script>

<template>
    <div class="bg-gray-50 flex h-screen overflow-hidden font-sans">
        
        <aside class="w-64 bg-[#001a4d] text-white flex flex-col shadow-xl z-10">
            <div class="h-16 flex items-center justify-center border-b border-[#002866]">
                <h1 class="text-xl font-bold tracking-wider">CAMPUS DIGITAL</h1>
            </div>
            <nav class="flex-1 px-4 py-6 space-y-2">
                <Link href="/modulo6" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl === '/modulo6' ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">📊</span> Dashboard</Link>
                <Link href="/modulo6/asociacion" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl.startsWith('/modulo6/asociacion') ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">👥</span> Mi Asociación</Link>
                <Link href="/modulo6/eventos" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl.startsWith('/modulo6/eventos') ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">🎟️</span> Eventos y Check-in</Link>
                <Link href="/modulo6/becas" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl.startsWith('/modulo6/becas') ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">🎓</span> Becas y Apoyos</Link>
                <Link href="/modulo6/comunicacion" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl.startsWith('/modulo6/comunicacion') ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">📢</span> Comunicación</Link>
                <Link href="/modulo6/transparencia" :class="['flex items-center px-4 py-3 rounded-lg transition', currentUrl.startsWith('/modulo6/transparencia') ? 'bg-[#00378c] shadow-inner border-l-4 border-blue-400' : 'hover:bg-[#002866] text-blue-100']"><span class="mr-3">📊</span> Transparencia</Link>
            </nav>
        </aside>

        <main class="flex-1 flex flex-col h-screen overflow-y-auto">
            <header class="h-16 bg-white shadow-sm flex items-center justify-between px-8">
                <h2 class="text-xl font-semibold text-gray-800">{{ headerTitle }}</h2>
                <div class="flex items-center space-x-4">
                    
                    <slot name="headerActions"></slot>
                    
                    <button @click="mostrarNotificaciones = true" class="relative text-gray-400 hover:text-gray-600 transition">
                        <span class="text-xl">🔔</span>
                        <span v-if="notificaciones.some(n => !n.leida)" class="absolute top-0 right-0 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white"></span>
                    </button>

                    <div class="flex items-center space-x-2 border-l pl-4 border-gray-200">
                        <div class="w-8 h-8 bg-[#00378c] rounded-full flex items-center justify-center text-white font-bold">D</div>
                        <span class="text-sm font-medium text-gray-700">Daniel (Presidencia)</span>
                    </div>
                </div>
            </header>

            <div class="flex-1 relative">
                <slot />
                
                <Panellateral 
                    :show="mostrarNotificaciones" 
                    titulo="Centro de Notificaciones" 
                    @close="mostrarNotificaciones = false"
                >
                    <div class="space-y-4">
                        <div v-for="noti in notificaciones" :key="noti.id" 
                             :class="noti.leida ? 'bg-white border-gray-200' : 'bg-blue-50 border-blue-200'" 
                             class="p-4 rounded-lg border shadow-sm relative">
                            <div v-if="!noti.leida" class="absolute top-4 right-4 w-2 h-2 bg-blue-600 rounded-full"></div>
                            <p class="font-bold text-sm text-gray-800 pr-4">{{ noti.titulo }}</p>
                            <p class="text-xs text-gray-600 mt-1">{{ noti.detalle }}</p>
                            <p class="text-[10px] text-gray-400 mt-2 uppercase font-bold tracking-wider">{{ noti.tiempo }}</p>
                        </div>
                        
                        <div v-if="notificaciones.length === 0" class="text-center text-gray-500 text-sm py-4">
                            No tienes notificaciones recientes.
                        </div>
                    </div>
                    
                    <template #footer>
                        <div class="flex space-x-2 w-full">
                            <button @click="marcarComoLeidas" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-bold hover:bg-gray-200 transition">
                                Marcar leídas
                            </button>
                            <!-- BOTÓN PARA ELIMINAR LEÍDAS -->
                            <button @click="eliminarLeidas" title="Limpiar leídas" class="px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-bold hover:bg-red-100 transition">
                                🗑️
                            </button>
                        </div>
                    </template>
                </Panellateral>

            </div>
        </main>
        
    </div>
</template>