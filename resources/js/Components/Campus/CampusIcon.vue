<script setup>
defineProps({ name: { type: String, required: true } });

const paths = {
    home: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3V10.5Z',
    user: 'M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z',
    shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z',
    devices: 'M4 4h16v12H4zM2 20h20M9 16l-1 4m7-4 1 4',
    qr: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM21 14v3m-4 4h4v-2m-7 2v-2',
    card: 'M3 5h18v14H3zM3 9h18M7 15h4',
    students: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m14-14a4 4 0 0 1 0 8m6 6v-2a4 4 0 0 0-3-3.87M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
    logout: 'M10 17l5-5-5-5m5 5H3m9-9h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7',
    menu: 'M4 7h16M4 12h16M4 17h16',
    close: 'M5 5l14 14M19 5 5 19',
};
</script>

<template>
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path :d="paths[name] ?? paths.home" />
    </svg>
</template>
