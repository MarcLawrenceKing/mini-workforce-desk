<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { io } from "socket.io-client";
import Button from "primevue/button";
import Card from "primevue/card";
import InputText from "primevue/inputtext";

const props = defineProps({
    kpis: {
        type: Object,
        default: () => ({ employees: 0, companies: 0, users: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({ from: "", to: "" }),
    },
});

const range = reactive({
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
});

const liveUsersCount = ref(props.kpis.users);
const socketConnected = ref(false);
let socket;

watch(
    () => props.kpis.users,
    (users) => {
        liveUsersCount.value = users;
    },
);

onMounted(() => {
    socket = io(import.meta.env.VITE_SOCKET_URL ?? "http://127.0.0.1:3001");

    socket.on("connect", () => {
        socketConnected.value = true;
    });

    socket.on("disconnect", () => {
        socketConnected.value = false;
    });

    socket.on("users.kpi.updated", ({ users }) => {
        if (Number.isInteger(users) && users >= 0) {
            liveUsersCount.value = users;
        }
    });
});

onBeforeUnmount(() => socket?.disconnect());

function cardValue(key) {
    return key === "users" ? liveUsersCount.value : props.kpis[key];
}

const cards = [
    { key: "employees", label: "Employee Count", icon: "pi pi-id-card" },
    { key: "companies", label: "Companies Count", icon: "pi pi-building" },
    { key: "users", label: "Users Count", icon: "pi pi-users" },
];

function applyFilters() {
    router.get("/dashboard", range, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    range.from = "";
    range.to = "";
    applyFilters();
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="page">
        <div class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p class="app-hint">An overview of your workforce records.</p>
            </div>
        </div>

        <Card>
            <template #title>Creation date range</template>
            <template #content>
                <form class="dashboard-filters" @submit.prevent="applyFilters">
                    <label class="field">
                        <span class="field-label">From</span>
                        <InputText v-model="range.from" type="date" :max="range.to || undefined" />
                    </label>

                    <label class="field">
                        <span class="field-label">To</span>
                        <InputText v-model="range.to" type="date" :min="range.from || undefined" />
                    </label>

                    <div class="dashboard-filter-actions">
                        <Button type="submit" label="Apply" icon="pi pi-filter" />
                        <Button
                            type="button"
                            label="Clear"
                            severity="secondary"
                            text
                            @click="clearFilters"
                        />
                    </div>
                </form>
                <p class="app-hint dashboard-filter-note">
                    The range applies to employee and company creation dates.
                </p>
            </template>
        </Card>

        <section class="kpi-grid" aria-label="Key performance indicators">
            <Card v-for="card in cards" :key="card.key" class="kpi-card">
                <template #content>
                    <div class="kpi-content">
                        <div>
                            <p class="app-hint">{{ card.label }}</p>
                            <p class="kpi-value">{{ cardValue(card.key) }}</p>
                            <small v-if="card.key === 'users'" class="app-hint">
                                {{ socketConnected ? "Live" : "Realtime offline" }}
                            </small>
                        </div>
                        <i :class="[card.icon, 'kpi-icon']" aria-hidden="true" />
                    </div>
                </template>
            </Card>
        </section>
    </div>
</template>
