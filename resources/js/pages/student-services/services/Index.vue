<script setup lang="ts">
import StudentServicesLayout from '@/layouts/StudentServicesLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ServiceType =
    | 'printing'
    | 'scanning'
    | 'copy'
    | 'binding';

type OrderStatus =
    | 'pending'
    | 'quoted'
    | 'awaiting_payment'
    | 'paid'
    | 'processing'
    | 'ready'
    | 'delivered'
    | 'cancelled';

type PaymentStatus =
    | 'pending'
    | 'paid'
    | 'not_required';

interface ServiceOrder {
    id: string;
    folio: string;
    serviceType: ServiceType;
    fileName: string | null;
    quantity: number;
    colorMode: 'bw' | 'color' | null;
    paperSize: string | null;
    sides: 'single' | 'double' | null;
    observations: string;
    quotedAmount: number | null;
    paymentStatus: PaymentStatus;
    paymentReferenceId?: string | null;
    status: OrderStatus;
    requestedAt: string;
    paidAt?: string | null;
    processingAt?: string | null;
    readyAt?: string | null;
    deliveredAt?: string | null;
    cancelledAt?: string | null;
}

const props = defineProps<{
    orders: ServiceOrder[];
}>();

const orders = computed<ServiceOrder[]>(
    () => {
        return props.orders.map(
            (order) => ({
                ...order,

                requestedAt:
                    order.requestedAt
                        ? new Date(
                            order.requestedAt,
                        ).toLocaleString(
                            'es-MX',
                        )
                        : '—',
            }),
        );
    },
);

const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');

const showForm = ref(false);

const selectedOrder =
    ref<ServiceOrder | null>(null);

const showDetails = ref(false);

const serviceType =
    ref<ServiceType>('printing');

const quantity = ref(1);

const colorMode =
    ref<'bw' | 'color'>('bw');

const paperSize = ref('Carta');

const sides =
    ref<'single' | 'double'>(
        'single',
    );

const observations = ref('');

const selectedFileName = ref('');

const selectedFile =
    ref<File | null>(null);

const processingOrderId =
    ref<string | null>(null);

const pendingCount = computed(() =>
    orders.value.filter(
        (order) =>
            [
                'pending',
                'quoted',
                'awaiting_payment',
            ].includes(
                order.status,
            ),
    ).length,
);

const processingCount = computed(() =>
    orders.value.filter(
        (order) =>
            order.status ===
            'processing',
    ).length,
);

const readyCount = computed(() =>
    orders.value.filter(
        (order) =>
            order.status ===
            'ready',
    ).length,
);

const deliveredCount = computed(() =>
    orders.value.filter(
        (order) =>
            order.status ===
            'delivered',
    ).length,
);

const estimatedAmount =
    computed(() => {
        let total = 0;

        switch (
            serviceType.value
            ) {
            case 'printing':
                total =
                    quantity.value *
                    (
                        colorMode.value ===
                        'color'
                            ? 5
                            : 2
                    );
                break;

            case 'copy':
                total =
                    quantity.value *
                    (
                        colorMode.value ===
                        'color'
                            ? 4
                            : 1.5
                    );
                break;

            case 'scanning':
                total =
                    quantity.value * 3;
                break;

            case 'binding':
                total =
                    quantity.value * 45;
                break;
        }

        if (
            sides.value ===
            'double' &&
            [
                'printing',
                'copy',
            ].includes(
                serviceType.value,
            )
        ) {
            total *= 0.9;
        }

        return Number(
            total.toFixed(2),
        );
    });

const filteredOrders =
    computed(() => {
        const term =
            search.value
                .trim()
                .toLowerCase();

        return orders.value.filter(
            (order) => {
                if (
                    typeFilter.value &&
                    order.serviceType !==
                    typeFilter.value
                ) {
                    return false;
                }

                if (
                    statusFilter.value &&
                    order.status !==
                    statusFilter.value
                ) {
                    return false;
                }

                if (!term) {
                    return true;
                }

                return [
                    order.folio,
                    order.fileName ??
                    '',
                    order.observations,
                    serviceLabel(
                        order.serviceType,
                    ),
                ].some((value) =>
                    value
                        .toLowerCase()
                        .includes(term),
                );
            },
        );
    });

function serviceLabel(
    type: ServiceType,
): string {
    const labels: Record<
        ServiceType,
        string
    > = {
        printing: 'Impresión',
        scanning:
            'Digitalización',
        copy: 'Copias',
        binding: 'Engargolado',
    };

    return labels[type];
}

function statusLabel(
    status: OrderStatus,
): string {
    const labels: Record<
        OrderStatus,
        string
    > = {
        pending: 'Pendiente',
        quoted: 'Cotizada',
        awaiting_payment:
            'Esperando pago',
        paid: 'Pagada',
        processing: 'En proceso',
        ready: 'Lista',
        delivered: 'Entregada',
        cancelled: 'Cancelada',
    };

    return labels[status];
}

function paymentLabel(
    status: PaymentStatus,
): string {
    const labels: Record<
        PaymentStatus,
        string
    > = {
        pending: 'Pendiente',
        paid: 'Pagado',
        not_required:
            'No requerido',
    };

    return labels[status];
}

function colorLabel(
    value:
        | 'bw'
        | 'color'
        | null,
): string {
    if (value === 'bw') {
        return 'Blanco y negro';
    }

    if (value === 'color') {
        return 'Color';
    }

    return '—';
}

function sidesLabel(
    value:
        | 'single'
        | 'double'
        | null,
): string {
    if (value === 'single') {
        return 'Una cara';
    }

    if (value === 'double') {
        return 'Doble cara';
    }

    return '—';
}

function money(
    value: number | null,
): string {
    if (value === null) {
        return 'Pendiente';
    }

    return new Intl.NumberFormat(
        'es-MX',
        {
            style: 'currency',
            currency: 'MXN',
        },
    ).format(value);
}

function openForm() {
    serviceType.value =
        'printing';

    quantity.value = 1;

    colorMode.value = 'bw';

    paperSize.value =
        'Carta';

    sides.value =
        'single';

    observations.value = '';

    selectedFileName.value =
        '';

    selectedFile.value =
        null;

    showForm.value = true;
}

function closeForm() {
    showForm.value = false;

    selectedFileName.value =
        '';

    selectedFile.value =
        null;
}

function handleFile(
    event: Event,
) {
    const input =
        event.target as HTMLInputElement;

    const file =
        input.files?.[0] ??
        null;

    selectedFile.value =
        file;

    selectedFileName.value =
        file?.name ?? '';
}

function createOrder() {
    if (
        quantity.value < 1
    ) {
        window.alert(
            'La cantidad debe ser mayor a cero.',
        );

        return;
    }

    if (
        serviceType.value ===
        'printing' &&
        selectedFile.value ===
        null
    ) {
        window.alert(
            'Selecciona el archivo que deseas imprimir.',
        );

        return;
    }

    const appliesConfiguration =
        [
            'printing',
            'copy',
            'scanning',
        ].includes(
            serviceType.value,
        );

    const appliesSides =
        [
            'printing',
            'copy',
        ].includes(
            serviceType.value,
        );

    const form = useForm({
        service_type:
        serviceType.value,

        quantity:
        quantity.value,

        color_mode:
            appliesConfiguration
                ? colorMode.value
                : null,

        paper_size:
            appliesConfiguration
                ? paperSize.value
                : null,

        sides:
            appliesSides
                ? sides.value
                : null,

        observations:
            observations.value.trim(),

        file:
        selectedFile.value,
    });

    form.post(
        '/servicios-estudiante/servicios-impresiones',
        {
            preserveScroll:
                true,

            forceFormData:
                true,

            onSuccess: () => {
                closeForm();

                window.alert(
                    'Solicitud registrada correctamente.',
                );
            },

            onError: (
                errors,
            ) => {
                const message =
                    errors.order ??
                    errors.file ??
                    errors.service_type ??
                    errors.quantity ??
                    errors.color_mode ??
                    errors.paper_size ??
                    errors.sides ??
                    'No fue posible registrar la solicitud.';

                window.alert(
                    String(
                        message,
                    ),
                );
            },
        },
    );
}

function openDetails(
    order: ServiceOrder,
) {
    selectedOrder.value =
        order;

    showDetails.value =
        true;
}

function closeDetails() {
    selectedOrder.value =
        null;

    showDetails.value =
        false;
}

function simulatePayment(
    order: ServiceOrder,
) {
    if (
        order.status !==
        'awaiting_payment' ||
        order.paymentStatus !==
        'pending'
    ) {
        return;
    }

    const confirmed =
        window.confirm(
            `¿Registrar el pago de ${money(
                order.quotedAmount,
            )} para ${order.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    const paymentReference =
        `SIM-${Date.now()}`;

    processingOrderId.value =
        order.id;

    router.patch(
        `/servicios-estudiante/servicios-impresiones/${order.id}/pagar`,
        {
            payment_reference_id:
            paymentReference,
        },
        {
            preserveScroll:
                true,

            onSuccess: () => {
                window.alert(
                    'Pago registrado correctamente.',
                );
            },

            onError: (
                errors,
            ) => {
                window.alert(
                    String(
                        errors.order ??
                        errors.payment_reference_id ??
                        'No fue posible registrar el pago.',
                    ),
                );
            },

            onFinish: () => {
                processingOrderId.value =
                    null;
            },
        },
    );
}

function cancelOrder(
    order: ServiceOrder,
) {
    if (
        [
            'processing',
            'ready',
            'delivered',
        ].includes(
            order.status,
        )
    ) {
        window.alert(
            'Esta solicitud ya no puede cancelarse.',
        );

        return;
    }

    const confirmed =
        window.confirm(
            `¿Cancelar la solicitud ${order.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    processingOrderId.value =
        order.id;

    router.patch(
        `/servicios-estudiante/servicios-impresiones/${order.id}/cancelar`,
        {},
        {
            preserveScroll:
                true,

            onSuccess: () => {
                window.alert(
                    'Solicitud cancelada correctamente.',
                );
            },

            onError: (
                errors,
            ) => {
                window.alert(
                    String(
                        errors.order ??
                        'No fue posible cancelar la solicitud.',
                    ),
                );
            },

            onFinish: () => {
                processingOrderId.value =
                    null;
            },
        },
    );
}

function markDelivered(
    order: ServiceOrder,
) {
    if (
        order.status !==
        'ready'
    ) {
        return;
    }

    const confirmed =
        window.confirm(
            `¿Confirmar que recogiste la solicitud ${order.folio}?`,
        );

    if (!confirmed) {
        return;
    }

    processingOrderId.value =
        order.id;

    router.patch(
        `/servicios-estudiante/servicios-impresiones/${order.id}/entregar`,
        {},
        {
            preserveScroll:
                true,

            onSuccess: () => {
                window.alert(
                    'Servicio entregado correctamente.',
                );
            },

            onError: (
                errors,
            ) => {
                window.alert(
                    String(
                        errors.order ??
                        'No fue posible registrar la entrega.',
                    ),
                );
            },

            onFinish: () => {
                processingOrderId.value =
                    null;
            },
        },
    );
}
</script>

<template>
    <StudentServicesLayout
        title="Servicios e impresiones"
        subtitle="Solicitudes de impresión, copias y servicios del campus"
    >
        <section class="hero">
            <div>
                <span
                    class="hero-label"
                >
                    SERVICIOS · MÓDULO
                    5.8
                </span>

                <h2>
                    Servicios e
                    impresiones
                </h2>

                <p>
                    Solicita impresiones,
                    digitalizaciones, copias
                    y otros servicios.
                    Consulta la cotización,
                    pago y estado de
                    entrega.
                </p>
            </div>

            <div
                class="hero-total"
            >
                <span>
                    Solicitudes
                </span>

                <strong>
                    {{ orders.length }}
                </strong>

                <small>
                    registradas
                </small>
            </div>
        </section>

        <section
            class="stats-grid"
        >
            <article
                class="stat-card"
            >
                <span>
                    Pendientes
                </span>

                <strong>
                    {{ pendingCount }}
                </strong>

                <small>
                    Cotización o pago
                </small>
            </article>

            <article
                class="stat-card"
            >
                <span>
                    En proceso
                </span>

                <strong>
                    {{
                        processingCount
                    }}
                </strong>

                <small>
                    Preparándose
                </small>
            </article>

            <article
                class="stat-card"
            >
                <span>
                    Listas
                </span>

                <strong>
                    {{ readyCount }}
                </strong>

                <small>
                    Disponibles para
                    recoger
                </small>
            </article>

            <article
                class="stat-card"
            >
                <span>
                    Entregadas
                </span>

                <strong>
                    {{
                        deliveredCount
                    }}
                </strong>

                <small>
                    Servicios finalizados
                </small>
            </article>
        </section>

        <section
            class="service-options"
        >
            <article>
                <div
                    class="service-icon"
                >
                    IM
                </div>

                <div>
                    <strong>
                        Impresiones
                    </strong>

                    <p>
                        Blanco y negro o
                        color, carta u
                        oficio.
                    </p>
                </div>
            </article>

            <article>
                <div
                    class="service-icon"
                >
                    CP
                </div>

                <div>
                    <strong>
                        Copias
                    </strong>

                    <p>
                        Copiado rápido de
                        documentos.
                    </p>
                </div>
            </article>

            <article>
                <div
                    class="service-icon"
                >
                    DG
                </div>

                <div>
                    <strong>
                        Digitalización
                    </strong>

                    <p>
                        Conversión de
                        documentos físicos
                        a PDF.
                    </p>
                </div>
            </article>

            <article>
                <div
                    class="service-icon"
                >
                    EN
                </div>

                <div>
                    <strong>
                        Engargolado
                    </strong>

                    <p>
                        Acabado para
                        reportes y
                        proyectos.
                    </p>
                </div>
            </article>
        </section>

        <section
            class="content-panel"
        >
            <div
                class="panel-header"
            >
                <div>
                    <span
                        class="panel-label"
                    >
                        SOLICITUDES
                    </span>

                    <h3>
                        Mis servicios
                    </h3>

                    <p>
                        Consulta el estado
                        de tus solicitudes
                        y servicios
                        pendientes.
                    </p>
                </div>

                <button
                    type="button"
                    class="primary-button"
                    @click="openForm"
                >
                    + Nueva solicitud
                </button>
            </div>

            <section
                v-if="showForm"
                class="form-panel"
            >
                <div
                    class="form-header"
                >
                    <div>
                        <span
                            class="panel-label"
                        >
                            NUEVO SERVICIO
                        </span>

                        <h3>
                            Crear solicitud
                        </h3>

                        <p>
                            Configura las
                            opciones del
                            servicio.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="closeForm"
                    >
                        ×
                    </button>
                </div>

                <div
                    class="order-form"
                >
                    <div
                        class="form-grid"
                    >
                        <div
                            class="form-field"
                        >
                            <label>
                                Tipo de
                                servicio
                                <span>
                                    *
                                </span>
                            </label>

                            <select
                                v-model="
                                    serviceType
                                "
                            >
                                <option
                                    value="printing"
                                >
                                    Impresión
                                </option>

                                <option
                                    value="copy"
                                >
                                    Copias
                                </option>

                                <option
                                    value="scanning"
                                >
                                    Digitalización
                                </option>

                                <option
                                    value="binding"
                                >
                                    Engargolado
                                </option>
                            </select>
                        </div>

                        <div
                            class="form-field"
                        >
                            <label>
                                Cantidad
                                <span>
                                    *
                                </span>
                            </label>

                            <input
                                v-model.number="
                                    quantity
                                "
                                type="number"
                                min="1"
                            />
                        </div>

                        <div
                            v-if="
                                serviceType ===
                                'printing'
                            "
                            class="form-field full"
                        >
                            <label>
                                Archivo
                                <span>
                                    *
                                </span>
                            </label>

                            <input
                                type="file"
                                accept=".pdf,.doc,.docx,.png,.jpg,.jpeg"
                                @change="
                                    handleFile
                                "
                            />

                            <small
                                v-if="
                                    selectedFileName
                                "
                                class="file-name"
                            >
                                Archivo:
                                {{
                                    selectedFileName
                                }}
                            </small>
                        </div>

                        <template
                            v-if="
                                [
                                    'printing',
                                    'copy',
                                    'scanning',
                                ].includes(
                                    serviceType,
                                )
                            "
                        >
                            <div
                                class="form-field"
                            >
                                <label>
                                    Tipo de
                                    impresión
                                </label>

                                <select
                                    v-model="
                                        colorMode
                                    "
                                >
                                    <option
                                        value="bw"
                                    >
                                        Blanco y
                                        negro
                                    </option>

                                    <option
                                        value="color"
                                    >
                                        Color
                                    </option>
                                </select>
                            </div>

                            <div
                                class="form-field"
                            >
                                <label>
                                    Tamaño de
                                    papel
                                </label>

                                <select
                                    v-model="
                                        paperSize
                                    "
                                >
                                    <option>
                                        Carta
                                    </option>

                                    <option>
                                        Oficio
                                    </option>

                                    <option>
                                        A4
                                    </option>
                                </select>
                            </div>
                        </template>

                        <div
                            v-if="
                                [
                                    'printing',
                                    'copy',
                                ].includes(
                                    serviceType,
                                )
                            "
                            class="form-field"
                        >
                            <label>
                                Caras
                            </label>

                            <select
                                v-model="
                                    sides
                                "
                            >
                                <option
                                    value="single"
                                >
                                    Una cara
                                </option>

                                <option
                                    value="double"
                                >
                                    Doble cara
                                </option>
                            </select>
                        </div>

                        <div
                            class="form-field full"
                        >
                            <label>
                                Observaciones
                            </label>

                            <textarea
                                v-model="
                                    observations
                                "
                                rows="3"
                                placeholder="Indicaciones adicionales..."
                            />
                        </div>
                    </div>

                    <div
                        class="quote-box"
                    >
                        <div>
                            <span>
                                Cotización
                                estimada
                            </span>

                            <small>
                                El monto
                                definitivo
                                será
                                calculado
                                nuevamente
                                por el
                                servidor.
                            </small>
                        </div>

                        <strong>
                            {{
                                money(
                                    estimatedAmount,
                                )
                            }}
                        </strong>
                    </div>

                    <div
                        class="information-box"
                    >
                        El registro de pago
                        actual es
                        provisional para
                        probar el flujo del
                        módulo. La
                        integración
                        monetaria
                        definitiva se hará
                        posteriormente con
                        el Equipo 2.
                    </div>

                    <div
                        class="form-actions"
                    >
                        <button
                            type="button"
                            class="secondary-button"
                            @click="
                                closeForm
                            "
                        >
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                createOrder
                            "
                        >
                            Crear solicitud
                        </button>
                    </div>
                </div>
            </section>

            <div class="filters">
                <div
                    class="search-field"
                >
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por folio, archivo o servicio..."
                    />
                </div>

                <select
                    v-model="
                        typeFilter
                    "
                >
                    <option value="">
                        Todos los
                        servicios
                    </option>

                    <option
                        value="printing"
                    >
                        Impresión
                    </option>

                    <option
                        value="copy"
                    >
                        Copias
                    </option>

                    <option
                        value="scanning"
                    >
                        Digitalización
                    </option>

                    <option
                        value="binding"
                    >
                        Engargolado
                    </option>
                </select>

                <select
                    v-model="
                        statusFilter
                    "
                >
                    <option value="">
                        Todos los estados
                    </option>

                    <option
                        value="pending"
                    >
                        Pendiente
                    </option>

                    <option
                        value="awaiting_payment"
                    >
                        Esperando pago
                    </option>

                    <option
                        value="processing"
                    >
                        En proceso
                    </option>

                    <option
                        value="ready"
                    >
                        Lista
                    </option>

                    <option
                        value="delivered"
                    >
                        Entregada
                    </option>

                    <option
                        value="cancelled"
                    >
                        Cancelada
                    </option>
                </select>
            </div>

            <div
                v-if="
                    filteredOrders.length >
                    0
                "
                class="table-container"
            >
                <table>
                    <thead>
                    <tr>
                        <th>
                            Folio
                        </th>

                        <th>
                            Servicio
                        </th>

                        <th>
                            Archivo
                        </th>

                        <th>
                            Cantidad
                        </th>

                        <th>
                            Cotización
                        </th>

                        <th>
                            Pago
                        </th>

                        <th>
                            Estado
                        </th>

                        <th>
                            Acciones
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr
                        v-for="
                                order in
                                filteredOrders
                            "
                        :key="
                                order.id
                            "
                    >
                        <td>
                            <strong
                                class="folio"
                            >
                                {{
                                    order.folio
                                }}
                            </strong>

                            <small
                                class="date"
                            >
                                {{
                                    order.requestedAt
                                }}
                            </small>
                        </td>

                        <td>
                            {{
                                serviceLabel(
                                    order.serviceType,
                                )
                            }}
                        </td>

                        <td>
                            {{
                                order.fileName ??
                                '—'
                            }}
                        </td>

                        <td>
                            {{
                                order.quantity
                            }}
                        </td>

                        <td>
                            <strong>
                                {{
                                    money(
                                        order.quotedAmount,
                                    )
                                }}
                            </strong>
                        </td>

                        <td>
                                <span
                                    class="payment"
                                    :class="
                                        `payment-${order.paymentStatus}`
                                    "
                                >
                                    {{
                                        paymentLabel(
                                            order.paymentStatus,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                                <span
                                    class="status"
                                    :class="
                                        `status-${order.status}`
                                    "
                                >
                                    {{
                                        statusLabel(
                                            order.status,
                                        )
                                    }}
                                </span>
                        </td>

                        <td>
                            <div
                                class="actions"
                            >
                                <button
                                    type="button"
                                    class="action-button details"
                                    @click="
                                            openDetails(
                                                order,
                                            )
                                        "
                                >
                                    Ver
                                </button>

                                <button
                                    v-if="
                                            order.status ===
                                                'awaiting_payment' &&
                                            order.paymentStatus ===
                                                'pending'
                                        "
                                    type="button"
                                    class="action-button pay"
                                    :disabled="
                                            processingOrderId ===
                                            order.id
                                        "
                                    @click="
                                            simulatePayment(
                                                order,
                                            )
                                        "
                                >
                                    Pagar
                                </button>

                                <button
                                    v-if="
                                            order.status ===
                                            'ready'
                                        "
                                    type="button"
                                    class="action-button deliver"
                                    :disabled="
                                            processingOrderId ===
                                            order.id
                                        "
                                    @click="
                                            markDelivered(
                                                order,
                                            )
                                        "
                                >
                                    Recoger
                                </button>

                                <button
                                    v-if="
                                            [
                                                'pending',
                                                'quoted',
                                                'awaiting_payment',
                                            ].includes(
                                                order.status,
                                            )
                                        "
                                    type="button"
                                    class="action-button cancel"
                                    :disabled="
                                            processingOrderId ===
                                            order.id
                                        "
                                    @click="
                                            cancelOrder(
                                                order,
                                            )
                                        "
                                >
                                    Cancelar
                                </button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-else
                class="empty-state"
            >
                <h3>
                    No hay solicitudes
                </h3>

                <p>
                    Registra un nuevo
                    servicio o modifica
                    los filtros.
                </p>
            </div>
        </section>

        <div
            v-if="
                showDetails &&
                selectedOrder
            "
            class="modal-backdrop"
            @click.self="
                closeDetails
            "
        >
            <section
                class="modal"
            >
                <div
                    class="modal-header"
                >
                    <div>
                        <span
                            class="panel-label"
                        >
                            DETALLE DEL
                            SERVICIO
                        </span>

                        <h3>
                            {{
                                selectedOrder.folio
                            }}
                        </h3>

                        <p>
                            {{
                                serviceLabel(
                                    selectedOrder.serviceType,
                                )
                            }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="close-button"
                        @click="
                            closeDetails
                        "
                    >
                        ×
                    </button>
                </div>

                <div
                    class="modal-body"
                >
                    <div
                        class="detail-grid"
                    >
                        <div>
                            <span>
                                Archivo
                            </span>

                            <strong>
                                {{
                                    selectedOrder.fileName ??
                                    'No aplica'
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Cantidad
                            </span>

                            <strong>
                                {{
                                    selectedOrder.quantity
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Color
                            </span>

                            <strong>
                                {{
                                    colorLabel(
                                        selectedOrder.colorMode,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Papel
                            </span>

                            <strong>
                                {{
                                    selectedOrder.paperSize ??
                                    '—'
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Caras
                            </span>

                            <strong>
                                {{
                                    sidesLabel(
                                        selectedOrder.sides,
                                    )
                                }}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Cotización
                            </span>

                            <strong>
                                {{
                                    money(
                                        selectedOrder.quotedAmount,
                                    )
                                }}
                            </strong>
                        </div>
                    </div>

                    <div
                        class="observations"
                    >
                        <span>
                            Observaciones
                        </span>

                        <p>
                            {{
                                selectedOrder.observations ||
                                'Sin observaciones.'
                            }}
                        </p>
                    </div>

                    <div
                        class="modal-actions"
                    >
                        <button
                            type="button"
                            class="primary-button"
                            @click="
                                closeDetails
                            "
                        >
                            Cerrar
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </StudentServicesLayout>
</template>

<style scoped>
.hero {
    padding: 24px 27px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-radius: 12px;
    background: #2f5eb6;
    color: white;
}

.hero-label,
.panel-label {
    display: block;
    margin-bottom: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.12em;
}

.hero-label {
    color: #b8cdf0;
}

.hero h2 {
    margin: 0;
    font-size: 23px;
}

.hero p {
    max-width: 650px;
    margin: 7px 0 0;
    color: #dce8fa;
    font-size: 12px;
    line-height: 1.6;
}

.hero-total {
    min-width: 170px;
    padding: 16px 19px;
    border-radius: 10px;
    background:
        rgba(255, 255, 255, 0.1);
}

.hero-total span {
    display: block;
    color: #d8e4f8;
    font-size: 9px;
}

.hero-total strong {
    display: block;
    margin-top: 4px;
    font-size: 28px;
}

.hero-total small {
    color: #bfd2ef;
    font-size: 8px;
}

.stats-grid {
    margin-top: 18px;
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);
    gap: 13px;
}

.stat-card {
    padding: 17px 18px;
    border: 1px solid #dfe5ee;
    border-radius: 10px;
    background: white;
}

.stat-card span {
    color: #73839a;
    font-size: 10px;
    font-weight: 700;
}

.stat-card strong {
    display: block;
    margin-top: 6px;
    color: #25324a;
    font-size: 24px;
}

.stat-card small {
    display: block;
    margin-top: 4px;
    color: #97a3b5;
    font-size: 9px;
}

.service-options {
    margin-top: 18px;
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);
    gap: 12px;
}

.service-options article {
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid #dfe5ee;
    border-radius: 9px;
    background: white;
}

.service-icon {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border-radius: 8px;
    background: #e9f1fc;
    color: #315fa6;
    font-size: 9px;
    font-weight: 900;
}

.service-options strong {
    color: #405069;
    font-size: 10px;
}

.service-options p {
    margin: 3px 0 0;
    color: #8a97a9;
    font-size: 8px;
}

.content-panel {
    margin-top: 18px;
    overflow: hidden;
    border: 1px solid #dfe5ee;
    border-radius: 11px;
    background: white;
}

.panel-header,
.form-header,
.modal-header {
    padding: 18px 21px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border-bottom:
        1px solid #e5e9ef;
}

.panel-label {
    color: #315a9f;
}

.panel-header h3,
.form-header h3,
.modal-header h3 {
    margin: 0;
    color: #25324a;
    font-size: 16px;
}

.panel-header p,
.form-header p,
.modal-header p {
    margin: 5px 0 0;
    color: #8794a7;
    font-size: 11px;
}

.primary-button,
.secondary-button {
    min-height: 38px;
    padding: 0 15px;
    border-radius: 7px;
    font: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.primary-button {
    border: 1px solid #2c63b7;
    background: #2c63b7;
    color: white;
}

.secondary-button {
    border: 1px solid #d6dee9;
    background: white;
    color: #55667d;
}

.form-panel {
    border-bottom:
        1px solid #e5e9ef;
    background: #fafcff;
}

.close-button {
    width: 34px;
    height: 34px;
    border: 1px solid #d8e0ea;
    border-radius: 7px;
    background: white;
    color: #657389;
    font-size: 20px;
    cursor: pointer;
}

.order-form,
.modal-body {
    padding: 21px;
}

.form-grid {
    display: grid;
    grid-template-columns:
        repeat(2, 1fr);
    gap: 17px 19px;
}

.form-field.full {
    grid-column: 1 / -1;
}

.form-field label {
    display: block;
    margin-bottom: 6px;
    color: #45556c;
    font-size: 10px;
    font-weight: 700;
}

.form-field label span {
    color: #bc4545;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    outline: none;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 11px;
}

.form-field textarea {
    resize: vertical;
}

.file-name {
    display: block;
    margin-top: 5px;
    color: #315fa6;
    font-size: 9px;
}

.quote-box {
    margin-top: 18px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid #d4e1f2;
    border-radius: 8px;
    background: #eef4fc;
}

.quote-box span {
    display: block;
    color: #566a85;
    font-size: 10px;
    font-weight: 800;
}

.quote-box small {
    display: block;
    margin-top: 3px;
    color: #8190a5;
    font-size: 8px;
}

.quote-box strong {
    color: #315fa6;
    font-size: 18px;
}

.information-box {
    margin-top: 15px;
    padding: 11px 13px;
    border: 1px solid #d5e1f1;
    border-radius: 7px;
    background: #f4f7fc;
    color: #657690;
    font-size: 9px;
}

.form-actions,
.modal-actions {
    margin-top: 19px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top:
        1px solid #e5e9ef;
}

.filters {
    padding: 14px 21px;
    display: grid;
    grid-template-columns:
        minmax(230px, 2fr)
        190px
        190px;
    gap: 10px;
    border-bottom:
        1px solid #e5e9ef;
    background: #fafcff;
}

.filters input,
.filters select {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 11px;
    border: 1px solid #d4dde8;
    border-radius: 7px;
    background: white;
    color: #25324a;
    font: inherit;
    font-size: 10px;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    padding: 12px 14px;
    background: #f4f7fb;
    color: #6f7f96;
    text-align: left;
    font-size: 9px;
    white-space: nowrap;
}

td {
    padding: 14px;
    border-top:
        1px solid #e9edf3;
    color: #5c6980;
    font-size: 10px;
}

.folio {
    display: block;
    color: #285aa6;
}

.date {
    display: block;
    margin-top: 3px;
    color: #99a4b4;
    font-size: 8px;
}

.status,
.payment {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 800;
    white-space: nowrap;
}

.status-pending,
.status-awaiting_payment,
.payment-pending {
    background: #fff3d7;
    color: #946510;
}

.status-processing,
.status-paid,
.payment-paid {
    background: #e8f0fc;
    color: #315fa6;
}

.status-ready {
    background: #e4f6ec;
    color: #217a4e;
}

.status-delivered {
    background: #edf1f5;
    color: #5f6e82;
}

.status-cancelled {
    background: #f4e7e8;
    color: #9c4c55;
}

.payment-not_required {
    background: #edf1f5;
    color: #6d7989;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 160px;
}

.action-button {
    min-height: 29px;
    padding: 0 8px;
    border-radius: 6px;
    font: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.action-button:disabled {
    cursor: default;
    opacity: 0.5;
}

.action-button.details {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.pay {
    border: 1px solid #c3e2d1;
    background: #eaf7f0;
    color: #26734c;
}

.action-button.deliver {
    border: 1px solid #c8d8ee;
    background: #edf3fc;
    color: #2c5c9f;
}

.action-button.cancel {
    border: 1px solid #e6c9cd;
    background: #fbebed;
    color: #9d4650;
}

.empty-state {
    padding: 55px 25px;
    text-align: center;
}

.empty-state h3 {
    margin: 0;
    color: #334056;
    font-size: 15px;
}

.empty-state p {
    margin: 6px 0 0;
    color: #8d99aa;
    font-size: 11px;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    padding: 20px;
    background:
        rgba(18, 29, 47, 0.5);
}

.modal {
    width:
        min(560px, 100%);
    overflow: hidden;
    border-radius: 11px;
    background: white;
}

.detail-grid {
    display: grid;
    grid-template-columns:
        repeat(2, 1fr);
    gap: 10px;
}

.detail-grid div {
    padding: 12px;
    border-radius: 7px;
    background: #f6f8fb;
}

.detail-grid span,
.observations span {
    display: block;
    color: #8b98aa;
    font-size: 8px;
}

.detail-grid strong {
    display: block;
    margin-top: 4px;
    color: #405069;
    font-size: 10px;
}

.observations {
    margin-top: 13px;
    padding: 13px;
    border: 1px solid #e2e7ee;
    border-radius: 7px;
}

.observations p {
    margin: 5px 0 0;
    color: #66778e;
    font-size: 10px;
}

@media (max-width: 950px) {
    .stats-grid,
    .service-options {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .filters {
        grid-template-columns:
            1fr;
    }
}

@media (max-width: 700px) {
    .hero,
    .panel-header,
    .form-header,
    .modal-header {
        align-items:
            flex-start;
        flex-direction:
            column;
    }

    .form-grid,
    .detail-grid {
        grid-template-columns:
            1fr;
    }

    .form-field.full {
        grid-column: auto;
    }

    .hero-total {
        width: 100%;
        box-sizing:
            border-box;
    }
}

@media (max-width: 520px) {
    .stats-grid,
    .service-options {
        grid-template-columns:
            1fr;
    }
}
</style>
