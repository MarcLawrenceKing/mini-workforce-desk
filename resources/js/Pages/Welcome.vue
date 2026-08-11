<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Button from "primevue/button";

defineProps({
    title: { type: String, required: true },
    description: { type: String, required: true },
});
</script>

<template>
    <Head :title="title" />

    <main class="page py-16">
        <p class="app-muted text-xs font-semibold tracking-widest uppercase">
            Laravel · Inertia · Vue
        </p>

        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            {{ title }}
        </h1>

        <p class="app-muted max-w-2xl text-lg leading-relaxed">
            {{ description }}
        </p>

        <div class="pt-2">
            <Link href="/task2">
                <Button
                    label="View staff"
                    icon="pi pi-arrow-right"
                    icon-pos="right"
                />
            </Link>
        </div>
    </main>
</template>
