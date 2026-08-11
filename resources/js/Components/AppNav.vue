<script setup>
import { Link } from "@inertiajs/vue3";
import { useNavigation } from "../Composables/useNavigation";

/**
 * The link list itself. Rendered twice: once in the sticky sidebar on desktop,
 * once inside the drawer on small screens.
 */
defineEmits(["navigate"]);

const { navItems, isActive } = useNavigation();
</script>

<template>
    <nav class="app-nav">
        <Link
            v-for="item in navItems"
            :key="item.href"
            :href="item.href"
            class="app-nav-item"
            :class="{ 'is-active': isActive(item.href) }"
            @click="$emit('navigate')"
        >
            <i :class="item.icon" />
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>
