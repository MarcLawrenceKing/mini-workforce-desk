<script setup>
import { reactive } from "vue";
import { Head, router } from "@inertiajs/vue3";
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
                            <p class="kpi-value">{{ kpis[card.key] }}</p>
                        </div>
                        <i :class="[card.icon, 'kpi-icon']" aria-hidden="true" />
                    </div>
                </template>
            </Card>
        </section>
    </div>
</template>
