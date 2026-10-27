<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import {ref,onMounted} from 'vue'
import {api} from '@/Platform/services/api.js'
defineOptions({layout:PlatformLayout})
const stats=ref(null)
onMounted(async()=>{try{stats.value=await api.analytics.getProfessorAnalytics()}catch{}})
</script><template><div class="page"><h1 class="page-title">Analítica docente</h1><p>Información registrada del programa de recompensas.</p><div v-if="stats" class="card" style="padding:24px"><p>Canjes entregados: {{stats.total_validations}}</p><p>Entregados este mes: {{stats.validations_month}}</p><p>Campañas académicas propias: {{stats.academic_campaigns}}</p></div><p v-else>Cargando información…</p><p>La segmentación por grupo requiere el catálogo académico de E1.</p></div></template>