<script setup>
import { computed } from "vue";
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

defineProps({
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
const { filters } = useTableSearch();
const confirm = useConfirm();

const {
    visible: dialogVisible,
    form,
    isEdit,
    open,
    submit,
} = useCrudDialog({
    resource: "companies",
    defaults: { name: "", is_active: true },
    fill: (company) => ({ name: company.name, is_active: company.is_active }),
});

const fields = computed(() => [
    { name: "name", label: "Company name", type: "text" },
    {
        name: "is_active",
        label: "Active",
        type: "switch",
        help: "Inactive companies stay on record but are flagged in every list.",
    },
]);

function confirmDelete(company) {
    confirm.require({
        header: "Delete company",
        message: `Delete “${company.name}”? This cannot be undone, and only works while the company has no users or employees left.`,
        icon: "pi pi-exclamation-triangle",
        acceptLabel: "Delete",
        acceptProps: { severity: "danger" },
        rejectLabel: "Cancel",
        rejectProps: { severity: "secondary", text: true },
        accept: () =>
            router.delete(`/companies/${company.id}`, { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Companies" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Companies</h1>

            <!-- hidden for company_admin: no companies.create permission -->
            <Button
                v-if="can.create"
                label="Add company"
                icon="pi pi-plus"
                @click="open()"
            />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only your own company.
        </p>

        <Card>
            <template #content>
                <DataTable
                    :value="companies"
                    dataKey="id"
                    v-model:filters="filters"
                    :globalFilterFields="['name']"
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
                                    placeholder="Search companies"
                                    fluid
                                />
                            </IconField>
                        </div>
                    </template>

                    <template #empty>
                        <div class="empty-state">
                            <i class="pi pi-building app-muted" />
                            <p class="app-hint">No companies match your search.</p>
                        </div>
                    </template>

                    <Column field="name" header="Name" sortable />
                    <Column field="users_count" header="Users" sortable />
                    <Column field="employees_count" header="Employees" sortable />
                    <Column field="is_active" header="Status" sortable>
                        <template #body="{ data }">
                            <Tag
                                :value="data.is_active ? 'Active' : 'Inactive'"
                                :severity="
                                    data.is_active ? 'success' : 'secondary'
                                "
                            />
                        </template>
                    </Column>
                    <Column header="" style="width: 8rem">
                        <template #body="{ data }">
                            <div class="row-actions">
                                <Button
                                    v-if="can.edit"
                                    icon="pi pi-pencil"
                                    aria-label="Edit company"
                                    v-tooltip.top="'Edit'"
                                    text
                                    rounded
                                    size="small"
                                    @click="open(data)"
                                />
                                <Button
                                    v-if="can.delete"
                                    icon="pi pi-trash"
                                    aria-label="Delete company"
                                    v-tooltip.top="'Delete'"
                                    severity="danger"
                                    text
                                    rounded
                                    size="small"
                                    @click="confirmDelete(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <EntityFormDialog
            v-model:visible="dialogVisible"
            :title="isEdit ? 'Edit company' : 'Add company'"
            :submit-label="isEdit ? 'Save changes' : 'Create company'"
            :fields="fields"
            :form="form"
            @submit="submit"
        />
    </div>
</template>
