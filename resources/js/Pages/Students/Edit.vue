<script setup>
import StudentForm from '@/Components/StudentForm.vue';
defineProps({ student: Object, campuses: Array, statuses: Array, contactChannels: Array });
</script>
<template><StudentForm :student="student" :campuses="campuses" :statuses="statuses" :contact-channels="contactChannels" /></template>
