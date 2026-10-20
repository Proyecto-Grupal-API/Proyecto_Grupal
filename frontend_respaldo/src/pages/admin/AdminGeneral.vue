<script setup>
const categories = [
  { name: "Tipos de puntos", items: ["Puntos estándar","Puntos bono","Puntos educativos","Puntos campaña"] },
  { name: "Parámetros globales", items: ["Tasa base","Multiplicadores","Límites diarios","Expiraciones"] },
  { name: "Catálogos del sistema", items: ["Tipos de negocio","Categorías de recompensa","Fuentes de puntos","Tipos de canje"] },
  { name: "Configuración general", items: ["Moneda del sistema","Zona horaria","Idioma","Notificaciones"] },
]
</script>
<template>
  <div class="page">
    <div class="ph"><h1 class="page-title">Administración general</h1><div class="page-subtitle">Panel de administración</div><p class="page-desc">Gestiona categorías, tipos y parámetros globales del sistema.</p></div>
    <div class="grid-2">
      <div v-for="cat in categories" :key="cat.name" class="card cat-card">
        <div class="cat-title">{{ cat.name }}</div>
        <div class="cat-items">
          <div v-for="item in cat.items" :key="item" class="cat-item">
            <span>{{ item }}</span>
            <button class="btn btn-ghost btn-sm">Editar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<style scoped>
.page{display:flex;flex-direction:column;gap:20px;}
.ph{display:flex;flex-direction:column;gap:2px;}
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;}
.cat-card{padding:18px;}
.cat-title{font-size:14px;font-weight:700;color:var(--cd-text-primary);margin-bottom:12px;}
.cat-items{display:flex;flex-direction:column;gap:6px;}
.cat-item{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--cd-border-light);font-size:13px;color:var(--cd-text-secondary);}
.cat-item:last-child{border-bottom:none;}
@media(max-width:900px){.grid-2{grid-template-columns:1fr;}}
</style>
