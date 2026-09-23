<script setup>
import { ref, onBeforeUnmount, watch, nextTick } from "vue";
const props = defineProps({ eventoId: String, disabled: Boolean });
const emit = defineEmits(["token"]);
const video = ref(null),
    activo = ref(false),
    error = ref(""),
    leyendo = ref(false);
let flujo = null,
    timer = null,
    generacion = 0;

function detener() {
    generacion++;
    clearTimeout(timer);
    flujo?.getTracks().forEach((track) => track.stop());
    flujo = null;
    if (video.value) video.value.srcObject = null;
    activo.value = false;
}

function leerFuente(fuente, lector, canvas, maximo) {
    const ancho = fuente.videoWidth || fuente.naturalWidth;
    const alto = fuente.videoHeight || fuente.naturalHeight;
    if (!ancho || !alto) return null;
    const escala = Math.min(1, maximo / Math.max(ancho, alto));
    canvas.width = Math.max(1, Math.round(ancho * escala));
    canvas.height = Math.max(1, Math.round(alto * escala));
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    ctx.drawImage(fuente, 0, 0, canvas.width, canvas.height);
    const imagen = ctx.getImageData(0, 0, canvas.width, canvas.height);
    return lector(imagen.data, imagen.width, imagen.height);
}

async function camara() {
    detener();
    const turno = generacion;
    error.value = "";
    activo.value = true;
    try {
        if (!navigator.mediaDevices?.getUserMedia)
            throw new Error(
                "La cámara requiere HTTPS o localhost. Puedes cargar una imagen QR o escribir el código.",
            );
        const { default: lector } = await import("jsqr");
        if (turno !== generacion) return;
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: "environment" },
        });
        if (turno !== generacion) {
            stream.getTracks().forEach((t) => t.stop());
            return;
        }
        flujo = stream;
        await nextTick();
        if (turno !== generacion) return;
        video.value.srcObject = flujo;
        await video.value.play();
        const canvas = document.createElement("canvas");
        const escanear = () => {
            if (turno !== generacion || !video.value) return;
            try {
                const resultado = leerFuente(video.value, lector, canvas, 960);
                if (resultado) {
                    detener();
                    emit("token", resultado.data);
                    return;
                }
                timer = setTimeout(escanear, 250);
            } catch {
                error.value =
                    "No se pudo leer la cámara. Puedes cargar una imagen QR.";
                detener();
            }
        };
        escanear();
    } catch (e) {
        if (turno === generacion) {
            error.value =
                e.name === "NotAllowedError"
                    ? "No se autorizó la cámara. Puedes cargar una imagen QR o escribir el código."
                    : e.name === "NotFoundError"
                      ? "No se encontró una cámara. Puedes cargar una imagen QR o escribir el código."
                      : e.message || "No se pudo abrir la cámara.";
            detener();
        }
    }
}

async function leerImagen(event) {
    const archivo = event.target.files?.[0];
    if (!archivo) return;
    detener();
    const turno = generacion;
    leyendo.value = true;
    error.value = "";
    const url = URL.createObjectURL(archivo);
    try {
        const { default: lector } = await import("jsqr");
        const imagen = new Image();
        imagen.src = url;
        await imagen.decode();
        if (turno !== generacion) return;
        const resultado = leerFuente(
            imagen,
            lector,
            document.createElement("canvas"),
            2048,
        );
        if (!resultado) throw new Error("QR no encontrado");
        emit("token", resultado.data);
    } catch {
        if (turno === generacion)
            error.value = "No se encontró un QR legible en la imagen.";
    } finally {
        URL.revokeObjectURL(url);
        leyendo.value = false;
        event.target.value = "";
    }
}
watch(() => props.eventoId, detener);
watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) detener();
    },
);
onBeforeUnmount(detener);
</script>
<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                :disabled="disabled || leyendo"
                @click="activo ? detener() : camara()"
                class="rounded-lg border px-3 py-2 text-sm font-medium"
            >
                {{ activo ? "Detener cámara" : "Usar cámara" }}
            </button>
            <label
                class="rounded-lg border px-3 py-2 text-sm font-medium cursor-pointer"
                >{{ leyendo ? "Leyendo…" : "Leer imagen QR"
                }}<input
                    aria-label="Imagen del código QR"
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    :disabled="disabled || leyendo"
                    @change="leerImagen"
            /></label>
        </div>
        <video
            v-if="activo"
            ref="video"
            class="w-full rounded-lg bg-black"
            autoplay
            muted
            playsinline
            aria-label="Vista de la cámara"
        ></video>
        <p v-if="error" role="alert" class="text-sm text-red-700">
            {{ error }}
        </p>
    </div>
</template>
