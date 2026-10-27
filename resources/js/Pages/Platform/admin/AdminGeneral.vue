<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
import {ref,onMounted} from 'vue'
import {Link} from '@inertiajs/vue3'
import {api} from '@/Platform/services/api.js'
defineOptions({layout:PlatformLayout})
const catalogs=ref([])
onMounted(async()=>{try{const result=await api.catalogs.list();catalogs.value=result.data || result}catch{}})
</script><template><div class="page"><h1 class="page-title">Administración general</h1><p>Catálogos guardados del programa de recompensas.</p><div v-if="!catalogs.length" class="card" style="padding:24px">No hay catálogos registrados.</div><article v-for="item in catalogs" :key="item._id" class="card" style="padding:24px"><h2>{{item.name || item.key}}</h2><pre>{{item.items || item.values}}</pre></article><Link href="/recompensas/admin/configuracion" class="btn btn-primary">Configuración y versiones</Link></div></template>