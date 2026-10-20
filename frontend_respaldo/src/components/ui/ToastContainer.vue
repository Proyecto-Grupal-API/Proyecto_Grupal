<script setup>
import { useToast } from '../../composables/useToast.js'
const { toasts } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="toast-container">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="`toast--${t.type}`"
        >
          <span class="toast-icon">
            {{ t.type === 'success' ? '✓' : t.type === 'error' ? '✕' : 'ℹ' }}
          </span>
          {{ t.message }}
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-container {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.toast {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 18px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 500;
  font-family: var(--cd-font);
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  min-width: 260px;
  max-width: 380px;
}

.toast--success { background: #fff; border-left: 4px solid #16a34a; color: #0f1923; }
.toast--error   { background: #fff; border-left: 4px solid #dc2626; color: #0f1923; }
.toast--info    { background: #fff; border-left: 4px solid #2878ED; color: #0f1923; }

.toast-icon {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}
.toast--success .toast-icon { background: #f0fdf4; color: #16a34a; }
.toast--error   .toast-icon { background: #fef2f2; color: #dc2626; }
.toast--info    .toast-icon { background: #eff6ff; color: #2878ED; }

.toast-enter-active { animation: slide-in 0.25s ease; }
.toast-leave-active { animation: slide-out 0.2s ease forwards; }

@keyframes slide-in {
  from { transform: translateX(100%); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}
@keyframes slide-out {
  from { transform: translateX(0);    opacity: 1; }
  to   { transform: translateX(100%); opacity: 0; }
}
</style>
