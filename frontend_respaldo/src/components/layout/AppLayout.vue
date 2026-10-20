<script setup>
import AppSidebar from './AppSidebar.vue'
import AppTopBar from './AppTopBar.vue'
import ToastContainer from '../ui/ToastContainer.vue'
</script>

<template>
  <div class="app-shell">
    <AppSidebar />
    <div class="app-main">
      <AppTopBar />
      <main class="app-content">
        <slot />
      </main>
    </div>
    <ToastContainer />
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  min-height: 100vh;
  background: var(--cd-page-bg);
}

.app-main {
  flex: 1;
  margin-left: var(--cd-sidebar-width);
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.app-content {
  margin-top: var(--cd-topbar-height);
  padding: 24px;
  flex: 1;
  min-height: calc(100vh - var(--cd-topbar-height));
}

@media (max-width: 768px) {
  .app-main { margin-left: 0; }
  .app-content { padding: 16px; }
}
</style>
