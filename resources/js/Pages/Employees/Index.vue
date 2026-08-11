<script setup>
import { computed, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
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
import ToggleSwitch from "primevue/toggleswitch";

const props = defineProps({
    employees: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    linkableUsers: { type: Array, default: () => [] },
    trashed: { type: Boolean, default: false },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
const { filters } = useTableSearch();
const confirm = useConfirm();

/* ---- deleted-rows toggle ---------------------------------------------- */
const showDeleted = ref(props.trashed);

watch(showDeleted, (value) => {
    router.get(
        "/employees",
        value ? { trashed: 1 } : {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
});

/* ---- create / edit ----------------------------------------------------- */
const {
    visible: dialogVisible,
    form,
    record,
    isEdit,
    open,
    submit,
} = useCrudDialog({
    resource: "employees",
    defaults: {
        company_id: null,
        employee_no: "",
        first_name: "",
        middle_name: "",
        last_name: "",
        user_id: null,
    },
    fill: (employee) => ({
        company_id: employee.company_id,
        employee_no: employee.employee_no,
        first_name: employee.first_name,
        middle_name: employee.middle_name,
        last_name: employee.last_name,
        user_id: employee.user_id,
    }),
});

/**
 * Only unlinked accounts are offered. The row's own account isn't in that list
 * — it is already linked — so merge it back in, otherwise the select would open
 * blank on an employee that has one.
 */
const userOptions = computed(() => {
    const current = record.value?.user;

    const options = props.linkableUsers.map((user) => ({
        label: `${user.name} (${user.email})`,
        value: user.id,
    }));

    if (current && !options.some((option) => option.value === current.id)) {
        options.unshift({
            label: `${current.name} (${current.email})`,
            value: current.id,
        });
    }

    return options;
});

const fields = computed(() => [
    {
        name: "company_id",
        label: "Company",
        type: "select",
        options: props.companies,
        optionLabel: "name",
        optionValue: "id",
        placeholder: "Select a company",
        // A company_admin is pinned to its own company; an admin has none of its
        // own, so it has to choose. The endpoint doesn't accept a company move.
        when: props.can.assignAnyCompany && !isEdit.value,
    },
    { name: "employee_no", label: "Employee no.", type: "text" },
    { name: "first_name", label: "First name", type: "text" },
    { name: "middle_name", label: "Middle name", type: "text" },
    { name: "last_name", label: "Last name", type: "text" },
    {
        name: "user_id",
        label: "Linked account",
        type: "select",
        options: userOptions.value,
        placeholder: "No account linked",
        clearable: true,
        help: "Links this record to a login. Only unlinked accounts are listed.",
    },
]);

/* ---- soft delete / restore --------------------------------------------- */
function confirmDelete(employee) {
    confirm.require({
        header: "Delete employee",
        message: `Delete ${employee.full_name}? This is a soft delete — the record is hidden but can be restored.`,
        icon: "pi pi-exclamation-triangle",
        acceptLabel: "Delete",
        acceptProps: { severity: "danger" },
        rejectLabel: "Cancel",
        rejectProps: { severity: "secondary", text: true },
        accept: () =>
            router.delete(`/employees/${employee.id}`, { preserveScroll: true }),
    });
}

function restore(employee) {
    router.put(`/employees/${employee.id}/restore`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Employees" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Employees</h1>

            <Button
                v-if="can.create"
                label="Add employee"
                icon="pi pi-plus"
                @click="open()"
            />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only employees in your own company.
        </p>

        <Card>
            <template #content>
                <DataTable
                    :value="employees"
                    dataKey="id"
                    v-model:filters="filters"
                    :globalFilterFields="[
                        'employee_no',
                        'full_name',
                        'company.name',
                        'user.email',
                    ]"
                    :rowClass="
                        (data) => (data.deleted_at ? 'row-deleted' : null)
                    "
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
                                    placeholder="Search number, name, account"
                                    fluid
                                />
                            </IconField>

                            <div v-if="can.delete" class="field-inline">
                                <ToggleSwitch
                                    v-model="showDeleted"
                                    inputId="show-deleted"
                                />
                                <label for="show-deleted" class="field-label">
                                    Show deleted
                                </label>
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        <div class="empty-state">
                            <i class="pi pi-id-card app-muted" />
                            <p class="app-hint">
                                No employees match your search.
                            </p>
                        </div>
                    </template>

                    <Column field="employee_no" header="Employee no." sortable />
                    <Column field="full_name" header="Name" sortable />
                    <Column field="company.name" header="Company" sortable>
                        <template #body="{ data }">
                            {{ data.company?.name ?? "—" }}
                        </template>
                    </Column>
                    <Column field="user.email" header="Linked account" sortable>
                        <template #body="{ data }">
                            {{ data.user?.email ?? "—" }}
                        </template>
                    </Column>
                    <Column v-if="showDeleted" header="Status">
                        <template #body="{ data }">
                            <Tag
                                :value="data.deleted_at ? 'Deleted' : 'Active'"
                                :severity="
                                    data.deleted_at ? 'danger' : 'success'
                                "
                            />
                        </template>
                    </Column>
                    <Column header="" style="width: 8rem">
                        <template #body="{ data }">
                            <div class="row-actions">
                                <template v-if="data.deleted_at">
                                    <Button
                                        v-if="can.delete"
                                        icon="pi pi-undo"
                                        aria-label="Restore employee"
                                        v-tooltip.top="'Restore'"
                                        text
                                        rounded
                                        size="small"
                                        @click="restore(data)"
                                    />
                                </template>
                                <template v-else>
                                    <Button
                                        v-if="can.edit"
                                        icon="pi pi-pencil"
                                        aria-label="Edit employee"
                                        v-tooltip.top="'Edit'"
                                        text
                                        rounded
                                        size="small"
                                        @click="open(data)"
                                    />
                                    <Button
                                        v-if="can.delete"
                                        icon="pi pi-trash"
                                        aria-label="Delete employee"
                                        v-tooltip.top="'Delete'"
                                        severity="danger"
                                        text
                                        rounded
                                        size="small"
                                        @click="confirmDelete(data)"
                                    />
                                </template>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <EntityFormDialog
            v-model:visible="dialogVisible"
            :title="isEdit ? 'Edit employee' : 'Add employee'"
            :submit-label="isEdit ? 'Save changes' : 'Create employee'"
            :fields="fields"
            :form="form"
            @submit="submit"
        />
    </div>
</template>
