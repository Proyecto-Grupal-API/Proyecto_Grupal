<script setup>
import CampusBrand from "@/Components/CampusBrand.vue";
import { Link } from "@inertiajs/vue3";
</script>
<template>
    <div class="campus-auth">
        <header class="campus-auth-header">
            <Link href="/"><CampusBrand /></Link>
        </header>
        <main class="campus-auth-main">
            <div class="campus-auth-intro">
                <span>BIENVENIDO A TU CAMPUS</span>
                <h1>Campus Digital</h1>
                <p>Tu comunidad, en un solo lugar.</p>
            </div>
            <div class="campus-auth-card"><slot /></div>
            <p class="campus-auth-caption">
                Asociaciones · Eventos · Becas · Comunidad
            </p>
        </main>
    </div>
</template>
