<script setup>
import AppHeader from "../Components/AppHeader.vue";
import FlashMessages from "../Components/FlashMessages.vue";
</script>

<template>
    <AppHeader />
    <FlashMessages />

    <main class="auth-shell">
        <div class="auth-card stack">
            <slot />
        </div>
    </main>
</template>
