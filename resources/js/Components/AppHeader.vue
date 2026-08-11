<script setup>
import { onBeforeMount, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useAuth } from "../Composables/useAuth";
import Button from "primevue/button";
import Menu from "primevue/menu";

/**
 * The topbar. Navigation itself lives in the sidebar (AppNav) — this keeps the
 * brand, the mobile menu trigger, the theme toggle and the account menu.
 */
defineProps({
    // False on the auth screens, which have no sidebar to open.
    showMenuToggle: { type: Boolean, default: false },
});

defineEmits(["toggle-menu"]);

const { user, isLoggedIn } = useAuth();

/* ---- theme toggle ----------------------------------------------------- */
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

/* ---- account menu ------------------------------------------------------ */
const userMenu = ref(null);
const userMenuItems = [
    {
        label: "My Account",
        icon: "pi pi-user",
        command: () => router.get("/my-account"),
    },
    { separator: true },
    {
        label: "Log out",
        icon: "pi pi-sign-out",
        command: () => router.post("/logout"),
    },
];
</script>

<template>
    <header class="app-header">
        <div class="app-header-bar">
            <div class="flex items-center gap-2">
                <Button
                    v-if="showMenuToggle && isLoggedIn"
                    class="app-menu-toggle"
                    icon="pi pi-bars"
                    aria-label="Open navigation"
                    severity="secondary"
                    text
                    rounded
                    @click="$emit('toggle-menu')"
                />

                <Link href="/" class="flex items-center gap-2 font-semibold">
                    <i class="pi pi-users" />
                    Mini Workforce Desk
                </Link>
            </div>

            <div class="flex items-center gap-1">
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

                <template v-if="isLoggedIn">
                    <Button
                        :label="user.name"
                        icon="pi pi-user"
                        severity="secondary"
                        text
                        size="small"
                        aria-haspopup="true"
                        aria-controls="user-menu"
                        @click="userMenu.toggle($event)"
                    />
                    <Menu
                        id="user-menu"
                        ref="userMenu"
                        :model="userMenuItems"
                        popup
                    />
                </template>

                <Link v-else href="/login">
                    <Button label="Log in" icon="pi pi-sign-in" size="small" />
                </Link>
            </div>
        </div>
    </header>
</template>
