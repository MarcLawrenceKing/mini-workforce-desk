import axios from "axios";
import { router } from "@inertiajs/vue3";

/**
 * Task 9 — the one configured Axios instance every component imports.
 *
 * Authentication is the browser's session cookie, not a token. You logged in at
 * /login, Laravel set an HttpOnly session cookie, and the browser attaches it to
 * every same-origin request on its own. Nothing here reads or stores a token:
 * anything JavaScript can read, an XSS bug can steal, and an HttpOnly cookie is
 * the one credential JavaScript cannot touch.
 *
 * Bearer tokens exist only for clients with no browser session — Postman, curl,
 * a future mobile app. See routes/api.php and README "API (Task 9)".
 */
const api = axios.create({
    baseURL: "/api",

    // ── These two lines ARE the authentication ────────────────────────────
    withCredentials: true, // send cookies, including the session cookie
    withXSRFToken: true, // read the XSRF-TOKEN cookie, resend as X-XSRF-TOKEN

    headers: {
        Accept: "application/json", // "answer with JSON, never an HTML page"
        "X-Requested-With": "XMLHttpRequest", // flips Laravel's expectsJson() to true
    },
});

/**
 * The auth failures, handled once for the whole app instead of in every
 * component. 403 and 422 are deliberately NOT handled here — what to say about
 * them is a per-screen decision, so they travel on to useApi().
 */
api.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;

        // 401 — "who are you?" The session expired, or you logged out in
        // another tab. Nothing to explain; send them to the login page.
        if (status === 401) {
            router.visit("/login");
        }

        // 419 — the CSRF token expired. A reload mints a fresh one.
        if (status === 419) {
            window.location.reload();
        }

        return Promise.reject(error);
    },
);

export default api;
