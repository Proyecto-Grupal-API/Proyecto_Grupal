<script setup>
defineProps({
  show:    { type: Boolean, required: true },
  title:   { type: String, default: 'Confirmar acción' },
  message: { type: String, default: '¿Estás seguro?' },
  confirmLabel: { type: String, default: 'Confirmar' },
  cancelLabel:  { type: String, default: 'Cancelar' },
  danger:  { type: Boolean, default: false },
})
defineEmits(['confirm', 'cancel'])
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="modal-overlay" @click.self="$emit('cancel')">
        <div class="modal-box">
          <div class="modal-header">
            <h3 class="modal-title">{{ title }}</h3>
            <button class="modal-close" @click="$emit('cancel')">✕</button>
          </div>
          <div class="modal-body">
            <p>{{ message }}</p>
            <slot />
          </div>
          <div class="modal-footer">
            <button class="btn btn-ghost" @click="$emit('cancel')">{{ cancelLabel }}</button>
            <button
              class="btn"
              :class="danger ? 'btn-danger' : 'btn-primary'"
              @click="$emit('confirm')"
            >{{ confirmLabel }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(15,25,60,0.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 24px;
}
.modal-box {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 20px 24px 0;
}
.modal-title { font-size: 16px; font-weight: 700; color: var(--cd-text-primary); margin: 0; }
.modal-close {
  width: 28px; height: 28px; border-radius: 6px; border: none;
  background: #f0f3f9; color: var(--cd-text-secondary);
  cursor: pointer; font-size: 12px;
  display: flex; align-items: center; justify-content: center;
}
.modal-close:hover { background: #e2e8f5; }
.modal-body { padding: 16px 24px; color: var(--cd-text-secondary); font-size: 14px; line-height: 1.6; }
.modal-footer {
  padding: 0 24px 20px;
  display: flex; gap: 10px; justify-content: flex-end;
}
.btn-danger { background: #dc2626; color: #fff; }
.btn-danger:hover { background: #b91c1c; }

.modal-enter-active, .modal-leave-active { transition: opacity 0.2s; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-active .modal-box { animation: pop-in 0.2s ease; }
@keyframes pop-in {
  from { transform: scale(0.95) translateY(10px); }
  to   { transform: scale(1)    translateY(0); }
}
</style>
