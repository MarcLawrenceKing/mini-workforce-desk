<script setup>
import { computed, onBeforeMount, ref } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { useAuth } from "../Composables/useAuth";
import Button from "primevue/button";
import Menu from "primevue/menu";

const { user, roles, isLoggedIn } = useAuth();

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

/* ---- role-driven navigation ------------------------------------------- */
const LINKS = {
    myAccount: { label: "My Account", href: "/my-account", icon: "pi pi-user" },
    users: { label: "Users", href: "/users", icon: "pi pi-users" },
    companies: {
        label: "Companies",
        href: "/companies",
        icon: "pi pi-building",
    },
    employees: {
        label: "Employees",
        href: "/employees",
        icon: "pi pi-id-card",
    },
    attendanceLogs: {
        label: "Attendance Logs",
        href: "/attendance-logs",
        icon: "pi pi-clock",
    },
    requests: { label: "Requests", href: "/requests", icon: "pi pi-inbox" },
};

const NAV_BY_ROLE = {
    admin: ["myAccount", "users", "companies", "employees"],
    company_admin: [
        "myAccount",
        "users",
        "companies",
        "employees",
        "requests",
    ],
    employee: ["myAccount", "attendanceLogs", "requests"],
};

// A user with more than one role gets the union, first-seen order preserved.
const navItems = computed(() => {
    if (!isLoggedIn.value) return [];

    const keys = roles.value.flatMap((role) => NAV_BY_ROLE[role] ?? []);

    return [...new Set(keys)].map((key) => LINKS[key]);
});

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
        <div class="app-shell app-header-bar">
            <Link href="/" class="flex items-center gap-2 font-semibold">
                <i class="pi pi-users" />
                Mini Workforce Desk
            </Link>

            <nav v-if="isLoggedIn" class="app-nav">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                >
                    <Button
                        :label="item.label"
                        :icon="item.icon"
                        severity="secondary"
                        text
                        size="small"
                    />
                </Link>
            </nav>

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
