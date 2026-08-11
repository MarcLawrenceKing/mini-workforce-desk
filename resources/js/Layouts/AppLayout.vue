<script setup>
import { ref, watch } from "vue";
import AppHeader from "../Components/AppHeader.vue";
import AppNav from "../Components/AppNav.vue";
import FlashMessages from "../Components/FlashMessages.vue";
import { useAuth } from "../Composables/useAuth";
import { useIsMobile } from "../Composables/useIsMobile";
import Drawer from "primevue/drawer";
import ConfirmDialog from "primevue/confirmdialog";

const { isLoggedIn } = useAuth();

// Desktop gets the sticky sidebar; below the md breakpoint the same nav is
// rendered inside this drawer instead.
const isMobile = useIsMobile();
const drawerOpen = ref(false);

// Resizing up to desktop hands navigation back to the sidebar.
watch(isMobile, (mobile) => {
    if (!mobile) drawerOpen.value = false;
});
</script>

<template>
    <AppHeader :show-menu-toggle="isMobile" @toggle-menu="drawerOpen = true" />

    <div class="app-body">
        <aside v-if="isLoggedIn" class="app-sidebar">
            <AppNav />
        </aside>

        <main class="app-main">
            <div class="app-flash">
                <FlashMessages />
            </div>

            <slot />
        </main>
    </div>

    <Drawer
        v-if="isMobile"
        v-model:visible="drawerOpen"
        header="Menu"
        class="app-drawer"
    >
        <AppNav @navigate="drawerOpen = false" />
    </Drawer>

    <!-- Backs every useConfirm() call — the delete confirmations, mainly. -->
    <ConfirmDialog />
</template>
