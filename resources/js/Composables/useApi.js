import { ref } from "vue";
import api from "@/lib/api";

/**
 * Task 9 — the loading / error bookkeeping an Axios call needs, so no component
 * has to write it twice.
 *
 * Inertia's useForm hands you `processing` and `errors` for free; with raw Axios
 * you own them. That is the point of the exercise: this is what useForm has been
 * doing on your behalf all along.
 *
 * @example
 * const { loading, errors, message, call } = useApi();
 * const { data } = await call((api) => api.put(`/time-logs/${id}/approve`));
 */
export function useApi() {
    /** True while a request is in flight — bind to :loading / :disabled. */
    const loading = ref(false);
    /** 422 field errors, Laravel's shape: { time_out: ["must be after…"] }. */
    const errors = ref({});
    /** A one-line banner for the failures that aren't per-field (403, 404, 500). */
    const message = ref(null);

    /**
     * @param {(api: import("axios").AxiosInstance) => Promise<any>} fn
     * @returns {Promise<any>} the Axios response, or throws so the caller can
     *                         skip its success path.
     */
    async function call(fn) {
        loading.value = true;
        errors.value = {};
        message.value = null;

        try {
            return await fn(api);
        } catch (e) {
            const status = e.response?.status;

            if (status === 422) {
                // "I understood you, but the data is wrong" — belongs on the fields.
                errors.value = e.response.data.errors ?? {};
                message.value = Object.keys(e.response.data.errors ?? {}).length
                    ? null
                    : (e.response.data.message ?? "That data was rejected.");
            } else if (status === 403) {
                // "I know who you are, and no."
                message.value = e.response.data?.message || "You do not have permission to do that.";
            } else if (status === 404) {
                message.value = "That record no longer exists — refresh the page.";
            } else if (status !== 401 && status !== 419) {
                // 401 and 419 are already handled in the interceptor: one
                // redirects to /login, the other reloads. Flashing a banner at
                // someone who is being navigated away is just noise.
                message.value = e.response?.data?.message ?? "Something went wrong. Please try again.";
            }

            throw e;
        } finally {
            // Runs on success *and* failure, so a button can never stay stuck
            // in its spinner.
            loading.value = false;
        }
    }

    /** Dismiss the banner (the Message component's close button). */
    function clear() {
        errors.value = {};
        message.value = null;
    }

    return { loading, errors, message, call, clear };
}
