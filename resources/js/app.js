import "../css/app.css";
import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import AppLayout from "./Layouts/AppLayout.vue";
import PrimeVue from "primevue/config";
import Aura from "@primeuix/themes/aura";
import "primeicons/primeicons.css";
import Button from "primevue/button";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Tag from "primevue/tag";

createInertiaApp({
    title: (title) => `${title} — Mini Workforce Desk`,
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob("./Pages/**/*.vue"),
        );

        // Every page gets the shared header unless it declares its own layout.
        page.default.layout ??= AppLayout;

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
            .mount(el);
    },
    progress: { color: "#4f46e5" },
});
