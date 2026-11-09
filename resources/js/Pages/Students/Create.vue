<script setup>
import StudentForm from '@/Components/StudentForm.vue';
defineProps({ campuses: Array, statuses: Array, contactChannels: Array });
</script>
<template><StudentForm :campuses="campuses" :statuses="statuses" :contact-channels="contactChannels" /></template>
