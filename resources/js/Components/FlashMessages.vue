<script setup>
import { watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import Toast from "primevue/toast";
import { useToast } from "primevue/usetoast";

const page = usePage();
const toast = useToast();

watch(
    () => page.props.flash,
    (flash = {}) => {
        if (flash.success) {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: flash.success,
                life: 4000,
            });
        }

        if (flash.error) {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: flash.error,
                life: 6000,
            });
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Toast position="top-right" />
</template>
