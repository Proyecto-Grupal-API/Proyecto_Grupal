<script setup>
defineProps({
  title:    { type: String, default: 'Sin datos' },
  message:  { type: String, default: 'No hay información disponible.' },
  icon:     { type: String, default: '📭' },
})
</script>

<template>
  <div class="empty-state">
    <div class="empty-icon">{{ icon }}</div>
    <div class="empty-title">{{ title }}</div>
    <div class="empty-message">{{ message }}</div>
    <slot />
  </div>
</template>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
  text-align: center;
}
.empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.7; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--cd-text-primary); margin-bottom: 6px; }
.empty-message { font-size: 13px; color: var(--cd-text-secondary); max-width: 320px; line-height: 1.6; }
</style>
