<script setup>
import AppHeader from "../Components/AppHeader.vue";
import FlashMessages from "../Components/FlashMessages.vue";
</script>

<template>
    <AppHeader />

    <main class="auth-shell">
        <div class="auth-card stack">
            <FlashMessages />
            <slot />
        </div>
    </main>
</template>
