<script setup>
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useRole } from './composables/useRole.js'
import AppLayout from './components/layout/AppLayout.vue'

const { currentRole, initAuth } = useRole()

onMounted(async () => {
  await initAuth()
})
</script>

<template>
  <AppLayout>
    <RouterView />
  </AppLayout>
</template>
