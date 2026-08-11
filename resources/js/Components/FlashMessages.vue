<script setup>
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import Message from "primevue/message";

const page = usePage();

const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div v-if="flash.success || flash.error" class="stack">
        <Message v-if="flash.success" severity="success" :closable="true">
            {{ flash.success }}
        </Message>
        <Message v-if="flash.error" severity="error" :closable="true">
            {{ flash.error }}
        </Message>
    </div>
</template>
