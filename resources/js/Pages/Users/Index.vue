<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import { useAuth } from "../../Composables/useAuth";
import { useCrudDialog } from "../../Composables/useCrudDialog";
import { useTableSearch } from "../../Composables/useTableSearch";
import EntityFormDialog from "../../Components/EntityFormDialog.vue";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from "primevue/button";
import Tag from "primevue/tag";
import IconField from "primevue/iconfield";
import InputIcon from "primevue/inputicon";
import InputText from "primevue/inputtext";

const props = defineProps({
    users: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    assignableRoles: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
const { filters } = useTableSearch();

const ROLE_LABELS = {
    company_admin: "Company Administrator",
    employee: "Employee",
};

const roleOptions = computed(() =>
    props.assignableRoles.map((role) => ({
        label: ROLE_LABELS[role] ?? role,
        value: role,
    })),
);

const {
    visible: dialogVisible,
    form,
    isEdit,
    open,
    submit,
} = useCrudDialog({
    resource: "users",
    defaults: {
        name: "",
        email: "",
        username: "",
        company_id: null,
        role: "employee",
        password: "",
        password_confirmation: "",
        is_disabled: false,
    },
    fill: (user) => ({
        name: user.name,
        email: user.email,
        username: user.username,
        company_id: user.company?.id ?? null,
        // The list carries display names; the form posts the machine name.
        role: user.role,
        is_disabled: user.is_disabled,
    }),
});

/**
 * The schema EntityFormDialog renders. `when: false` is how a field is dropped
 * for a mode or a role — the server enforces the same shape either way.
 */
const fields = computed(() => [
    { name: "name", label: "Full name", type: "text" },
    { name: "email", label: "Email", type: "email" },
    {
        name: "username",
        label: "Username",
        type: "text",
        help: "Letters, numbers, dashes and underscores.",
    },
    {
        name: "company_id",
        label: "Company",
        type: "select",
        options: props.companies,
        optionLabel: "name",
        optionValue: "id",
        placeholder: "Select a company",
        clearable: true,
        // A company_admin can only ever create inside its own company, and the
        // update endpoint doesn't accept a company move at all.
        when: props.can.assignAnyCompany && !isEdit.value,
    },
    {
        name: "role",
        label: "Role",
        type: "select",
        options: roleOptions.value,
        help: "The administrator role is never assignable here.",
    },
    {
        name: "password",
        label: "Password",
        type: "password",
        when: !isEdit.value,
    },
    {
        name: "password_confirmation",
        label: "Confirm password",
        type: "password",
        feedback: false,
        when: !isEdit.value,
    },
    {
        name: "is_disabled",
        label: "Account disabled",
        type: "switch",
        help: "A disabled account cannot log in and is signed out mid-session.",
        when: isEdit.value,
    },
]);
</script>

<template>
    <Head title="Users" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Users</h1>

            <Button
                v-if="can.create"
                label="Add user"
                icon="pi pi-plus"
                @click="open()"
            />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only users in your own company.
        </p>

        <Card>
            <template #content>
                <DataTable
                    :value="users"
                    dataKey="id"
                    v-model:filters="filters"
                    :globalFilterFields="[
                        'name',
                        'email',
                        'username',
                        'company.name',
                    ]"
                    paginator
                    :rows="10"
                    :rowsPerPageOptions="[10, 25, 50]"
                    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
                    currentPageReportTemplate="{first}–{last} of {totalRecords}"
                    removableSort
                    responsiveLayout="scroll"
                >
                    <template #header>
                        <div class="table-toolbar">
                            <IconField class="table-toolbar-search">
                                <InputIcon class="pi pi-search" />
                                <InputText
                                    v-model="filters.global.value"
                                    placeholder="Search name, email, username"
                                    fluid
                                />
                            </IconField>
                        </div>
                    </template>

                    <template #empty>
                        <div class="empty-state">
                            <i class="pi pi-users app-muted" />
                            <p class="app-hint">No users match your search.</p>
                        </div>
                    </template>

                    <Column field="name" header="Name" sortable />
                    <Column field="email" header="Email" sortable />
                    <Column field="username" header="Username" sortable />
                    <Column field="company.name" header="Company" sortable>
                        <template #body="{ data }">
                            {{ data.company?.name ?? "—" }}
                        </template>
                    </Column>
                    <Column header="Roles">
                        <template #body="{ data }">
                            <div class="tag-list">
                                <Tag
                                    v-for="role in data.roles"
                                    :key="role"
                                    :value="role"
                                    severity="info"
                                />
                            </div>
                        </template>
                    </Column>
                    <Column field="is_disabled" header="Status" sortable>
                        <template #body="{ data }">
                            <Tag
                                :value="data.is_disabled ? 'Disabled' : 'Active'"
                                :severity="
                                    data.is_disabled ? 'danger' : 'success'
                                "
                            />
                        </template>
                    </Column>
                    <Column header="" style="width: 6rem">
                        <template #body="{ data }">
                            <!-- The administrator account is not editable here:
                                 syncRoles() would strip the only admin role. -->
                            <Button
                                v-if="can.edit && !data.is_admin"
                                icon="pi pi-pencil"
                                aria-label="Edit user"
                                v-tooltip.top="'Edit'"
                                text
                                rounded
                                size="small"
                                @click="open(data)"
                            />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <EntityFormDialog
            v-model:visible="dialogVisible"
            :title="isEdit ? 'Edit user' : 'Add user'"
            :subtitle="
                isEdit
                    ? 'Leave the account enabled unless you want to block sign-in.'
                    : 'The new account can sign in as soon as it is created.'
            "
            :submit-label="isEdit ? 'Save changes' : 'Create user'"
            :fields="fields"
            :form="form"
            @submit="submit"
        />
    </div>
</template>
