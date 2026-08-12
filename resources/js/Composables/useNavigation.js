import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useAuth } from "./useAuth";

const LINKS = {
    dashboard: { label: "Dashboard", href: "/dashboard", icon: "pi pi-chart-bar" },
    myAccount: { label: "My Account", href: "/my-account", icon: "pi pi-user" },
    users: { label: "Users", href: "/users", icon: "pi pi-users" },
    companies: { label: "Companies", href: "/companies", icon: "pi pi-building" },
    employees: { label: "Employees", href: "/employees", icon: "pi pi-id-card" },
    attendanceLogs: {
        label: "Attendance Logs",
        href: "/attendance-logs",
        icon: "pi pi-clock",
    },
    requests: { label: "Requests", href: "/requests", icon: "pi pi-inbox" },
};

const NAV_BY_ROLE = {
    admin: ["dashboard", "myAccount", "users", "companies", "employees"],
    company_admin: ["myAccount", "users", "companies", "employees", "attendanceLogs", "requests"],
    employee: ["myAccount", "attendanceLogs", "requests"],
};

/**
 * Role-driven navigation, shared by the sidebar and the mobile drawer. Nothing
 * here is a security boundary — every destination is gated again server-side.
 */
export function useNavigation() {
    const { roles, isLoggedIn } = useAuth();
    const page = usePage();

    // A user with more than one role gets the union, first-seen order preserved.
    const navItems = computed(() => {
        if (!isLoggedIn.value) return [];

        const keys = roles.value.flatMap((role) => NAV_BY_ROLE[role] ?? []);

        return [...new Set(keys)].map((key) => LINKS[key]);
    });

    const isActive = (href) => page.url.split("?")[0].startsWith(href);

    return { navItems, isActive };
}
