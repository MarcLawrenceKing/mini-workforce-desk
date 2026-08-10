<script setup>
import { onBeforeMount, ref } from "vue";
import Button from "primevue/button";

const isDark = ref(false);

function applyTheme(dark) {
    isDark.value = dark;
    document.documentElement.classList.toggle("dark", dark);
    localStorage.setItem("theme", dark ? "dark" : "light");
}

onBeforeMount(() => {
    const stored = localStorage.getItem("theme");

    applyTheme(
        stored
            ? stored === "dark"
            : window.matchMedia("(prefers-color-scheme: dark)").matches,
    );
});
</script>

<template>
    <header class="app-header">
        <div class="app-shell flex items-center justify-between py-3">
            <span class="flex items-center gap-2 font-semibold">
                <i class="pi pi-users" />
                Mini Workforce Desk
            </span>

            <Button
                :icon="isDark ? 'pi pi-sun' : 'pi pi-moon'"
                :aria-label="
                    isDark ? 'Switch to light mode' : 'Switch to dark mode'
                "
                severity="secondary"
                text
                rounded
                @click="applyTheme(!isDark)"
            />
        </div>
    </header>
</template>
