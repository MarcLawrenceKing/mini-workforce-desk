import { ref } from "vue";
import { FilterMatchMode } from "@primevue/core/api";

/**
 * The global-search filter every index DataTable binds to. Kept in one place so
 * the three lists behave identically.
 */
export function useTableSearch() {
    const filters = ref({
        global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    });

    const clear = () => {
        filters.value.global.value = null;
    };

    return { filters, clear };
}
