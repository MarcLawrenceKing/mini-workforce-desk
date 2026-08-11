import { onUnmounted, ref } from "vue";

// Matches the md breakpoint the sidebar CSS uses, so the drawer takes over
// exactly where the sticky sidebar drops out.
const QUERY = "(max-width: 767.98px)";

/**
 * True while the viewport is below md. Used to render the mobile chrome (the
 * hamburger and the nav drawer) only where it belongs — CSS alone can't do it
 * here, since PrimeVue's cssLayer sits after `components` and wins on display.
 */
export function useIsMobile() {
    const mq = window.matchMedia(QUERY);
    const isMobile = ref(mq.matches);
    const update = (event) => (isMobile.value = event.matches);

    mq.addEventListener("change", update);
    onUnmounted(() => mq.removeEventListener("change", update));

    return isMobile;
}
