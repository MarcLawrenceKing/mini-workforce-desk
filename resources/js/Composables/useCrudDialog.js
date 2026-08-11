import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";

/**
 * The state behind EntityFormDialog: one Inertia form reused for both create
 * and edit, so a page never has to keep two forms in sync.
 *
 * @param {object}   config
 * @param {string}   config.resource  URL segment, e.g. "employees"
 * @param {object}   config.defaults  Blank form values (also the create state)
 * @param {Function} config.fill      Row -> form values, for edit mode
 */
export function useCrudDialog({ resource, defaults, fill = (row) => row }) {
    const visible = ref(false);
    const record = ref(null);
    const form = useForm({ ...defaults });

    const isEdit = computed(() => record.value !== null);

    /** open() -> create, open(row) -> edit. */
    function open(row = null) {
        record.value = row;

        // defaults() then reset() is what actually swaps the values over; it
        // also means a cancelled edit doesn't leak into the next open().
        form.defaults({ ...defaults, ...(row ? fill(row) : {}) });
        form.reset();
        form.clearErrors();

        visible.value = true;
    }

    function submit() {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                visible.value = false;
            },
        };

        if (isEdit.value) {
            form.put(`/${resource}/${record.value.id}`, options);
        } else {
            form.post(`/${resource}`, options);
        }
    }

    return { visible, record, form, isEdit, open, submit };
}
