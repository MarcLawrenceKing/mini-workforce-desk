import "../css/app.css";
import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import AppLayout from "./Layouts/AppLayout.vue";
import AuthLayout from "./Layouts/AuthLayout.vue";
import PrimeVue from "primevue/config";
import ConfirmationService from "primevue/confirmationservice";
import Tooltip from "primevue/tooltip";
import Aura from "@primeuix/themes/aura";
import "primeicons/primeicons.css";

createInertiaApp({
    title: (title) => `${title} — Mini Workforce Desk`,
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob("./Pages/**/*.vue"),
        );

        // Auth screens get a bare centred layout; everything else gets the app chrome.
        page.default.layout ??= name.startsWith("Auth/")
            ? AuthLayout
            : AppLayout;

        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: {
                    preset: Aura,
                    options: {
                        darkModeSelector: ".dark",
                        cssLayer: {
                            name: "primevue",
                            order: "theme, base, components, utilities, primevue",
                        },
                    },
                },
            })
            .use(ConfirmationService)
            .directive("tooltip", Tooltip)
            .mount(el);
    },
    progress: { color: "#4f46e5" },
});
