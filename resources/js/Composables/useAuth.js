import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Thin reader over the `auth` prop shared by HandleInertiaRequests.
 * Nothing here is a security boundary — it only decides what to render.
 * Every one of these checks is enforced again server-side.
 */
export function useAuth() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user ?? null);
    const roles = computed(() => user.value?.roles ?? []);
    const permissions = computed(() => user.value?.permissions ?? []);
    const isLoggedIn = computed(() => user.value !== null);

    /** hasRole("admin") | hasRole("admin", "company_admin") -> OR */
    const hasRole = (...names) =>
        names.flat().some((name) => roles.value.includes(name));

    /** can("users.view") | can("users.view", "users.edit") -> OR */
    const can = (...names) =>
        names.flat().some((name) => permissions.value.includes(name));

    return { user, roles, permissions, isLoggedIn, hasRole, can };
}
