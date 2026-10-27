<script setup>
defineProps({
  title:    { type: String, required: true },
  value:    { type: [String, Number], required: true },
  icon:     { type: String, default: '' },
  iconBg:   { type: String, default: '#EFF6FF' },
  iconColor:{ type: String, default: '#2878ED' },
  link:     { type: Boolean, default: false },
  subtitle: { type: String, default: '' },
})
defineEmits(['click'])
</script>

<template>
  <div class="stat-card" :class="{ clickable: link }" @click="link && $emit('click')">
    <div class="stat-left">
      <div class="stat-icon" :style="{ background: iconBg }">
        <span :style="{ color: iconColor }">{{ icon }}</span>
      </div>
      <div>
        <div class="stat-title">{{ title }}</div>
        <div class="stat-value">{{ value }}</div>
        <div v-if="subtitle" class="stat-subtitle">{{ subtitle }}</div>
      </div>
    </div>
    <div v-if="link" class="stat-arrow">›</div>
  </div>
</template>

<style scoped>
.stat-card {
  background: var(--cd-card-bg);
  border: 1px solid var(--cd-border);
  border-radius: var(--cd-card-radius);
  box-shadow: var(--cd-card-shadow);
  padding: 18px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  transition: box-shadow 0.15s;
}
.stat-card.clickable { cursor: pointer; }
.stat-card.clickable:hover { box-shadow: 0 4px 16px rgba(40,120,237,0.12); }

.stat-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.stat-title {
  font-size: 12px;
  color: var(--cd-text-secondary);
  font-weight: 500;
  margin-bottom: 3px;
}

.stat-value {
  font-size: 22px;
  font-weight: 700;
  color: var(--cd-text-primary);
  line-height: 1.2;
}

.stat-subtitle {
  font-size: 11px;
  color: var(--cd-text-muted);
  margin-top: 2px;
}

.stat-arrow {
  font-size: 20px;
  color: var(--cd-text-muted);
}
</style>
