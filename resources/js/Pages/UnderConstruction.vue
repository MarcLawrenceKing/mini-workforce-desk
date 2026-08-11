<script setup>
import { Head } from "@inertiajs/vue3";
import Card from "primevue/card";

defineProps({
    title: { type: String, required: true },
    blurb: { type: String, default: "" },
});
</script>

<template>
    <Head :title="title" />

    <div class="page">
        <h1 class="page-title">{{ title }}</h1>

        <Card>
            <template #content>
                <div class="empty-state">
                    <i class="pi pi-wrench app-muted" style="font-size: 2rem" />
                    <p class="text-lg font-medium">Under construction</p>
                    <p class="app-hint max-w-md">{{ blurb }}</p>
                </div>
            </template>
        </Card>
    </div>
</template>
