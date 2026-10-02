<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router } from '@inertiajs/vue3';

interface ServiceItem {
    module: string;
    title: string;
    description: string;
    icon: string;
    path: string;
}

const services: ServiceItem[] = [
    {
        module: '5.1',
        title: 'Biblioteca y catálogo',
        description:
            'Catálogo de libros, ejemplares, disponibilidad, préstamos, devoluciones y reservas.',
        icon: '▣',
        path: '/servicios-estudiante/biblioteca',
    },
    {
        module: '5.2',
        title: 'Multas de biblioteca',
        description:
            'Consulta de multas por atraso, daño o pérdida y seguimiento de pagos.',
        icon: '$',
        path: '/servicios-estudiante/biblioteca/multas',
    },
    {
        module: '5.3',
        title: 'Lockers',
        description:
            'Consulta de lockers por edificio, zona, tamaño, estado y periodo.',
        icon: '▤',
        path: '/servicios-estudiante/lockers',
    },
    {
        module: '5.4',
        title: 'Asignación de lockers',
        description:
            'Solicitudes, asignaciones, renovaciones y liberación de lockers.',
        icon: '▥',
        path: '/servicios-estudiante/lockers/solicitudes',
    },
    {
        module: '5.5',
        title: 'Reservación de instalaciones',
        description:
            'Reserva salas, laboratorios, auditorios, canchas y otros espacios del campus.',
        icon: '◷',
        path: '/servicios-estudiante/reservas',
    },
    {
        module: '5.6',
        title: 'Zonas de descanso',
        description:
            'Consulta y reserva cápsulas, sillones y espacios silenciosos.',
        icon: '◒',
        path: '/servicios-estudiante/zonas-descanso',
    },
    {
        module: '5.7',
        title: 'Renta de equipos',
        description:
            'Solicitud, entrega y devolución de equipos institucionales disponibles.',
        icon: '▦',
        path: '/servicios-estudiante/renta-equipos',
    },
    {
        module: '5.8',
        title: 'Servicios e impresiones',
        description:
            'Solicitudes de impresión, copias, digitalización, cotización y entrega.',
        icon: '▧',
        path: '/servicios-estudiante/servicios-impresiones',
    },
    {
        module: '5.9',
        title: 'Soporte e incidencias',
        description:
            'Registro y seguimiento de tickets, incidencias, prioridades y evidencias.',
        icon: '◇',
        path: '/servicios-estudiante/soporte',
    },
    {
        module: '5.10',
        title: 'Calendarios, cupos y reglas',
        description:
            'Administración de horarios, disponibilidad, capacidad, bloqueos y listas de espera.',
        icon: '▥',
        path: '/servicios-estudiante/calendarios-cupos',
    },
    {
        module: '5.11',
        title: 'Validación de servicios',
        description:
            'Validación QR/NFC para préstamos, accesos, entregas, recolecciones y devoluciones.',
        icon: '◎',
        path: '/servicios-estudiante/validacion-servicios',
    },
];

const openService = (service: ServiceItem) => {
    router.visit(service.path);
};
</script>

<template>
    <StudentServicesLayout
        title="Servicios"
        subtitle="Servicios disponibles para la comunidad estudiantil"
    >
        <section class="welcome">
            <div class="welcome-information">
                <span class="section-label">
                    CAMPUS DIGITAL
                </span>

                <h2>
                    Servicios al Estudiante
                </h2>

                <p>
                    Accede a los servicios universitarios administrados
                    por el Equipo 5 dentro de Campus Digital.
                </p>
            </div>

            <div class="module-summary">
                <span>
                    MÓDULOS
                </span>

                <strong>
                    11
                </strong>

                <small>
                    Equipo 5
                </small>
            </div>
        </section>

        <section class="services-section">
            <div class="section-header">
                <div>
                    <span class="section-label">
                        EQUIPO 5
                    </span>

                    <h3>
                        Servicios disponibles
                    </h3>

                    <p>
                        Selecciona el módulo que deseas consultar.
                    </p>
                </div>

                <span class="available-badge">
                    11 módulos
                </span>
            </div>

            <div class="services-grid">
                <article
                    v-for="service in services"
                    :key="service.module"
                    class="service-card"
                    @click="openService(service)"
                >
                    <div class="service-card-top">
                        <div class="service-icon">
                            {{ service.icon }}
                        </div>

                        <span class="module-badge">
                            {{ service.module }}
                        </span>
                    </div>

                    <div class="service-information">
                        <h4>
                            {{ service.title }}
                        </h4>

                        <p>
                            {{ service.description }}
                        </p>

                        <span class="open-link">
                            Abrir servicio
                            <span class="arrow">
                                →
                            </span>
                        </span>
                    </div>
                </article>
            </div>
        </section>
    </StudentServicesLayout>
</template>

<style scoped>
.welcome {
    padding: 27px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;
    border: 1px solid #dfe5ee;
    border-radius: 12px;
    background: white;
}

.welcome-information {
    min-width: 0;
}

.section-label {
    display: block;
    margin-bottom: 8px;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.welcome h2 {
    margin: 0;
    color: #25324a;
    font-size: 24px;
    font-weight: 700;
}

.welcome p {
    max-width: 650px;
    margin: 8px 0 0;
    color: #8290a5;
    font-size: 12px;
    line-height: 1.6;
}

.module-summary {
    min-width: 145px;
    padding: 15px 18px;
    flex-shrink: 0;
    border: 1px solid #dce5f1;
    border-radius: 10px;
    background: #f4f8fd;
}

.module-summary span {
    display: block;
    color: #73839a;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.1em;
}

.module-summary strong {
    display: block;
    margin-top: 4px;
    color: #315a9f;
    font-size: 27px;
    font-weight: 800;
}

.module-summary small {
    color: #91a0b3;
    font-size: 8px;
}

.services-section {
    margin-top: 20px;
}

.section-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}

.section-header .section-label {
    margin-bottom: 5px;
}

.section-header h3 {
    margin: 0;
    color: #2c394f;
    font-size: 16px;
    font-weight: 700;
}

.section-header p {
    margin: 4px 0 0;
    color: #8b97a9;
    font-size: 11px;
}

.available-badge {
    padding: 6px 9px;
    border-radius: 7px;
    background: #eaf1fc;
    color: #315a9f;
    font-size: 9px;
    font-weight: 800;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(
        auto-fit,
        minmax(270px, 1fr)
    );
    gap: 15px;
}

.service-card {
    min-height: 180px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
    cursor: pointer;
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease,
        border-color 0.15s ease;
}

.service-card:hover {
    transform: translateY(-2px);
    border-color: #b6c9e6;
    box-shadow:
        0 8px 22px
        rgba(31, 61, 108, 0.08);
}

.service-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 15px;
}

.service-icon {
    width: 45px;
    height: 45px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 9px;
    background: #eaf1fb;
    color: #315fa8;
    font-size: 18px;
    font-weight: 800;
}

.module-badge {
    padding: 5px 8px;
    border-radius: 6px;
    background: #f1f4f8;
    color: #76869b;
    font-size: 8px;
    font-weight: 800;
}

.service-information {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.service-information h4 {
    margin: 14px 0 0;
    color: #2c394f;
    font-size: 14px;
    font-weight: 700;
}

.service-information p {
    margin: 7px 0 0;
    color: #7d899c;
    font-size: 10px;
    line-height: 1.55;
}

.open-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: fit-content;
    margin-top: auto;
    padding-top: 14px;
    color: #315fa8;
    font-size: 10px;
    font-weight: 700;
}

.arrow {
    transition:
        transform 0.15s ease;
}

.service-card:hover .arrow {
    transform: translateX(3px);
}

@media (max-width: 850px) {
    .services-grid {
        grid-template-columns:
            repeat(
                auto-fit,
                minmax(230px, 1fr)
            );
    }
}

@media (max-width: 650px) {
    .services-grid {
        grid-template-columns: 1fr;
    }

    .welcome {
        padding: 22px;
        align-items: flex-start;
        flex-direction: column;
    }

    .module-summary {
        width: 100%;
        box-sizing: border-box;
    }

    .section-header {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
