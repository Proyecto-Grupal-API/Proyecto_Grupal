<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';

const boleto = ref(null);
const cargando = ref(true);

onMounted(async () => {
    try {
        const respuesta = await axios.get('/api/estudiante/mi-boleto');
        boleto.value = respuesta.data;
    } catch (error) {
        console.error("Error al cargar el boleto:", error);
    } finally {
        cargando.value = false;
    }
});

const formatearFecha = (fechaString) => {
    if (!fechaString) return { dia: '', mes: '', hora: '' };
    const fecha = new Date(fechaString);
    return {
        dia: fecha.toLocaleDateString('es-MX', { day: '2-digit' }),
        mes: fecha.toLocaleDateString('es-MX', { month: 'short' }).toUpperCase(),
        hora: fecha.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })
    };
};

const fechaFormateada = computed(() => formatearFecha(boleto.value?.fecha_hora_inicio));

// Usamos una API gratuita para generar el QR al vuelo usando el token de tu base de datos
const qrUrl = computed(() => {
    if (!boleto.value?.token_qr) return '';
    return `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${boleto.value.token_qr}`;
});
</script>

<template>
    <Head title="Mi Boleto - Campus Digital" />

    <!-- Fondo oscuro estilo app móvil -->
    <div class="min-h-screen bg-[#001a4d] flex flex-col items-center py-10 px-4 font-sans">
        
        <!-- Header simple -->
        <div class="w-full max-w-md flex justify-between items-center mb-8 text-white">
            <button class="text-2xl font-bold">&larr;</button>
            <h1 class="text-lg font-bold tracking-widest">MIS BOLETOS</h1>
            <div class="w-6"></div>
        </div>

        <div v-if="cargando" class="text-white animate-pulse font-bold mt-20">
            Generando pase de acceso...
        </div>

        <div v-else-if="boleto" class="w-full max-w-sm">
            
            <!-- DISEÑO DE BOLETO / TICKET -->
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden relative">
                
                <!-- Círculos para simular el recorte del boleto -->
                <div class="absolute top-[65%] -left-4 w-8 h-8 bg-[#001a4d] rounded-full z-10"></div>
                <div class="absolute top-[65%] -right-4 w-8 h-8 bg-[#001a4d] rounded-full z-10"></div>

                <!-- Mitad superior: Info del Evento -->
                <div class="p-8 bg-gradient-to-br from-[#002866] to-[#00378c] text-white text-center">
                    <span class="bg-white text-[#002866] text-[10px] font-bold px-3 py-1 rounded-full uppercase tracking-widest mb-4 inline-block">
                        Acceso General
                    </span>
                    <h2 class="text-2xl font-bold leading-tight mb-2">{{ boleto.titulo }}</h2>
                    <p class="text-blue-200 text-sm mb-6 flex items-center justify-center">
                        <span class="mr-1">📍</span> {{ boleto.ubicacion }}
                    </p>
                    
                    <div class="flex justify-center items-center space-x-6 bg-[#001a4d]/30 rounded-xl p-3">
                        <div class="text-center">
                            <p class="text-[10px] text-blue-300 uppercase">Día</p>
                            <p class="text-xl font-bold">{{ fechaFormateada.dia }}</p>
                        </div>
                        <div class="text-center border-l border-r border-blue-400/30 px-6">
                            <p class="text-[10px] text-blue-300 uppercase">Mes</p>
                            <p class="text-xl font-bold">{{ fechaFormateada.mes }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-[10px] text-blue-300 uppercase">Hora</p>
                            <p class="text-xl font-bold">{{ fechaFormateada.hora }}</p>
                        </div>
                    </div>
                </div>

                <!-- Línea punteada de recorte -->
                <div class="border-t-2 border-dashed border-gray-300 mx-4 mt-2 relative top-[65%] z-0"></div>

                <!-- Mitad inferior: El Código QR -->
                <div class="p-8 flex flex-col items-center bg-white">
                    <p class="text-xs text-gray-500 uppercase font-bold tracking-widest mb-4">Escanea en la entrada</p>
                    
                    <div class="p-3 border-4 border-gray-100 rounded-2xl bg-white shadow-sm mb-4">
                        <img :src="qrUrl" alt="Código QR de Acceso" class="w-48 h-48 object-contain">
                    </div>
                    
                    <p class="font-mono text-gray-400 text-xs tracking-[0.3em]">{{ boleto.token_qr }}</p>

                    <!-- Etiqueta de estado -->
                    <div class="mt-6 w-full text-center">
                        <span v-if="boleto.estado_asistencia === 'asistio'" class="bg-green-100 text-green-800 font-bold px-4 py-2 rounded-lg text-sm block">
                            ✅ ASISTENCIA REGISTRADA
                        </span>
                        <span v-else-if="boleto.estado_pago === 'pagado' || boleto.estado_pago === 'exento'" class="bg-blue-50 text-[#00378c] font-bold px-4 py-2 rounded-lg text-sm block border border-blue-100">
                            Boleto Pagado y Listo
                        </span>
                        <span v-else class="bg-yellow-100 text-yellow-800 font-bold px-4 py-2 rounded-lg text-sm block">
                            ⚠️ Pago Pendiente
                        </span>
                    </div>
                </div>
            </div>

            <!-- Botón de Apple Wallet / Google Wallet falso por diseño -->
            <button class="w-full mt-6 bg-black text-white font-bold py-3 rounded-xl flex items-center justify-center space-x-2 shadow-lg">
                <span>Añadir a Google Wallet</span>
            </button>

        </div>

        <div v-else class="text-white text-center mt-20">
            <p class="text-4xl mb-4">🎟️</p>
            <p class="font-bold">No tienes boletos activos</p>
            <p class="text-sm text-gray-400 mt-2">Los eventos a los que te inscribas aparecerán aquí.</p>
        </div>
    </div>
</template>