import axios from "axios";

export function useReservasEventos() {
    const solicitudes = new Map();
    return (accion, eventoId, generacion = 0) => {
        const intento = `${accion}:${eventoId}:${generacion}`;
        if (!solicitudes.has(intento)) solicitudes.set(intento, crypto.randomUUID());
        const data = { clave_solicitud: solicitudes.get(intento), generacion };
        const url = `/api/eventos/${eventoId}/inscripcion`;
        return accion === "inscribir" ? axios.post(url, data) : axios.delete(url, { data });
    };
}
