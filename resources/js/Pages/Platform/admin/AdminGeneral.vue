<script setup>
import PlatformLayout from '@/Layouts/PlatformLayout.vue'
defineOptions({ layout: PlatformLayout })
import { ref, computed, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/Platform/composables/useToast.js'
import { useRole } from '@/Platform/composables/useRole.js'
import { api } from '@/Platform/services/api.js'

const { success, error } = useToast()
const { hasPermission } = useRole()
const canManage = computed(() => hasPermission('catalogs.manage'))
const apiMessage = (err, fallback) => Object.values(err.response?.data?.errors || {}).flat().join(' ') || err.response?.data?.message || err.response?.data?.error || fallback

const catalogs = ref([])
const isLoading = ref(true)
const isSubmitting = ref(false)
const showModal = ref(false)
const form = ref({ name: '', code: '', description: '', items: '' })

async function load() {
  try {
    isLoading.value = true
    const res = await api.catalogs.list()
    catalogs.value = (Array.isArray(res) ? res : (res.data || [])).map(c => ({ ...c, id: c._id || c.id }))
  } catch (err) {
    console.warn('No se pudo actualizar la información de Recompensas.', err)
  } finally {
    isLoading.value = false
  }
}

async function submit() {
  const items = form.value.items.split('\n').map(x => x.trim()).filter(Boolean)
  if (!form.value.name.trim() || !form.value.code.trim() || !items.length) { error('Captura nombre, código y al menos un elemento.'); return }
  try {
    isSubmitting.value = true
    await api.catalogs.create({ name: form.value.name.trim(), code: form.value.code.trim().toUpperCase(), description: form.value.description.trim() || null, items })
    success(`Catálogo "${form.value.name.trim()}" creado.`)
    showModal.value = false
    form.value = { name: '', code: '', description: '', items: '' }
    await load()
  } catch (err) {
    error(apiMessage(err, 'No se pudo crear el catálogo.'))
  } finally {
    isSubmitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="phrow">
      <div class="ph">
        <h1 class="page-title">7.7 Administración general</h1>
        <div class="page-subtitle">Panel de administración</div>
        <p class="page-desc">Catálogos maestros del programa: tipos de negocio, categorías, estados y otros valores de referencia.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <Link href="/recompensas/admin/configuracion" class="btn btn-secondary">Configuración y versiones</Link>
        <button v-if="canManage" class="btn btn-primary" @click="showModal = true">＋ Nuevo catálogo</button>
      </div>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="cd-table">
          <thead><tr><th>Catálogo</th><th>Código</th><th>Descripción</th><th>Elementos</th></tr></thead>
          <tbody>
            <tr v-for="c in catalogs" :key="c.id">
              <td style="font-weight:600">{{ c.name }}</td>
              <td><code style="font-size:12px">{{ c.code }}</code></td>
              <td style="font-size:12px;color:var(--cd-text-muted)">{{ c.description || '—' }}</td>
              <td>
                <span v-for="(item, i) in (c.items || [])" :key="i" class="chip">{{ typeof item === 'object' ? (item.label || item.name || JSON.stringify(item)) : item }}</span>
              </td>
            </tr>
            <tr v-if="!isLoading && !catalogs.length"><td colspan="4" style="text-align:center;color:var(--cd-text-muted);padding:24px">No hay catálogos registrados.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal card" role="dialog" aria-labelledby="catalog-title">
        <div class="modal-header">
          <h3 id="catalog-title" class="modal-title">Nuevo catálogo</h3>
          <button class="modal-close" aria-label="Cerrar" @click="showModal = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="submit">
          <div class="form-row">
            <div class="form-group">
              <label class="cd-label" for="catalog-name">Nombre</label>
              <input id="catalog-name" v-model="form.name" required maxlength="255" class="cd-input" placeholder="Ej: Tipos de negocio" />
            </div>
            <div class="form-group">
              <label class="cd-label" for="catalog-code">Código único</label>
              <input id="catalog-code" v-model="form.code" required maxlength="50" class="cd-input" style="text-transform:uppercase" placeholder="Ej: BUSINESS_TYPES" />
            </div>
          </div>
          <div class="form-group">
            <label class="cd-label" for="catalog-description">Descripción</label>
            <input id="catalog-description" v-model="form.description" class="cd-input" />
          </div>
          <div class="form-group">
            <label class="cd-label" for="catalog-items">Elementos (uno por línea)</label>
            <textarea id="catalog-items" v-model="form.items" rows="5" required class="cd-input" placeholder="Negocio de estudiante&#10;Asociación estudiantil&#10;Tienda institucional"></textarea>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="isSubmitting">{{ isSubmitting ? 'Guardando...' : 'Crear Catálogo' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.phrow{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.ph{display:flex;flex-direction:column;gap:2px;}
.card-header{padding:14px 16px 0;}
.card-title{font-size:15px;font-weight:700;margin:0;}
.chip{display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:600;}
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 999; }
.modal { width: 100%; max-width: 540px; max-height: 92vh; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.modal-title { font-size: 16px; font-weight: 700; margin: 0; }
.modal-close { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--cd-text-muted); }
.modal-body { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.cd-label { font-size: 12px; font-weight: 600; color: var(--cd-text-secondary); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.form-hint { font-size: 11px; color: var(--cd-text-muted); }
</style>
