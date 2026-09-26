<script setup>
import { computed } from "vue";
const props = defineProps({ metricas: { type: Array, default: () => [] } });
const grupos = computed(() => [...new Set(props.metricas.map((m) => m.grupo))]);
const valor = (m) =>
    m.unidad === "centavos_mxn"
        ? (m.valor / 100).toLocaleString("es-MX", {
              style: "currency",
              currency: "MXN",
          })
        : Number(m.valor).toLocaleString("es-MX");
</script>
<template>
    <div class="space-y-6">
        <section v-for="grupo in grupos" :key="grupo">
            <h3 class="font-bold text-lg mb-3">{{ grupo }}</h3>
            <dl class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
                <div
                    v-for="m in metricas.filter((m) => m.grupo === grupo)"
                    :key="m.clave"
                    class="border rounded-lg p-4 min-w-0"
                >
                    <dt class="text-sm text-gray-600">{{ m.etiqueta }}</dt>
                    <dd
                        class="text-2xl font-semibold text-blue-950 break-words my-2"
                    >
                        {{ valor(m) }}
                    </dd>
                    <details class="text-xs text-gray-500">
                        <summary class="cursor-pointer">
                            Cómo se calcula
                        </summary>
                        <p class="mt-2">{{ m.criterio }}</p>
                    </details>
                </div>
            </dl>
        </section>
    </div>
</template>
