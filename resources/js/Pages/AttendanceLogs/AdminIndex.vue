<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useCrudDialog } from "../../Composables/useCrudDialog";
import { useTableSearch } from "../../Composables/useTableSearch";
import EntityFormDialog from "../../Components/EntityFormDialog.vue";
import Button from "primevue/button";
import Card from "primevue/card";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import IconField from "primevue/iconfield";
import InputIcon from "primevue/inputicon";
import InputText from "primevue/inputtext";
import Select from "primevue/select";
import Tag from "primevue/tag";

const props = defineProps({
    logs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    employees: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    approvers: { type: Array, default: () => [] },
});

const { filters } = useTableSearch();
const confirm = useConfirm();
const employeeFilter = ref(null);
const statusFilter = ref(null);

const statusOptions = computed(() => props.statuses.map((value) => ({
    value, label: value[0].toUpperCase() + value.slice(1),
})));
const visibleLogs = computed(() => props.logs.filter((log) =>
    (!employeeFilter.value || log.employee_id === employeeFilter.value)
    && (!statusFilter.value || log.status === statusFilter.value),
));
const monthLabel = computed(() => new Intl.DateTimeFormat(undefined, {
    month: "long", year: "numeric", timeZone: "UTC",
}).format(new Date(`${props.month}-01T00:00:00Z`)));

const { visible: dialogVisible, form, isEdit, open, submit } = useCrudDialog({
    resource: "attendance-logs",
    defaults: {
        employee_id: null, date: "", time_in: "", time_out: "", notes: "", status: "pending",
        approved_by: null, approved_at: "", reject_reason: "",
    },
    fill: (log) => ({
        employee_id: log.employee_id, date: log.date, time_in: log.time_in,
        time_out: log.time_out ?? "", notes: log.notes ?? "", status: log.status,
        approved_by: log.approved_by ?? null, approved_at: log.approved_at ?? "",
        reject_reason: log.reject_reason ?? "",
    }),
});
// The three approval fields only make sense for the status they belong to, so the
// dialog swaps them in and out as the status select changes.
const fields = computed(() => [
    { name: "employee_id", label: "Employee", type: "select", options: props.employees, optionLabel: "label", optionValue: "id", when: !isEdit.value },
    { name: "date", label: "Date", type: "date" },
    { name: "time_in", label: "Time in", type: "time" },
    { name: "time_out", label: "Time out", type: "time", help: "Leave blank if the employee has not logged out." },
    { name: "notes", label: "Notes", type: "textarea" },
    { name: "status", label: "Status", type: "select", options: statusOptions.value },
    { name: "approved_by", label: "Approved by", type: "select", options: props.approvers, optionLabel: "label", optionValue: "id", clearable: true, help: "Leave blank to record yourself as the approver.", when: form.status === "approved" },
    { name: "approved_at", label: "Approved at", type: "datetime-local", help: "Leave blank to stamp the current date and time.", when: form.status === "approved" },
    { name: "reject_reason", label: "Reject reason", type: "textarea", help: "Required — the employee sees why the log was rejected.", when: form.status === "rejected" },
]);

function changeMonth(offset) {
    const date = new Date(`${props.month}-01T00:00:00Z`);
    date.setUTCMonth(date.getUTCMonth() + offset);
    router.get("/attendance-logs", { month: date.toISOString().slice(0, 7) }, { preserveState: true, preserveScroll: true, replace: true });
}
function approve(log) {
    confirm.require({
        header: "Approve attendance", message: `Approve ${log.employee.full_name}'s attendance for ${log.date}?`,
        icon: "pi pi-check-circle", acceptLabel: "Approve", rejectLabel: "Cancel",
        rejectProps: { severity: "secondary", text: true },
        accept: () => router.put(`/attendance-logs/${log.id}/approve`, {}, { preserveScroll: true }),
    });
}
const severity = (status) => ({ approved: "success", rejected: "danger", pending: "warn" }[status]);
</script>

<template>
    <Head title="Attendance Logs" />
    <div class="page">
        <div class="page-header">
            <div><h1 class="page-title">Attendance Logs</h1><p class="app-hint">Attendance records for employees in your company.</p></div>
            <Button label="Add attendance" icon="pi pi-plus" @click="open()" />
        </div>

        <Card>
            <template #title>
                <div class="month-picker">
                    <Button icon="pi pi-chevron-left" aria-label="Previous month" text rounded @click="changeMonth(-1)" />
                    <span>{{ monthLabel }}</span>
                    <Button icon="pi pi-chevron-right" aria-label="Next month" text rounded @click="changeMonth(1)" />
                </div>
            </template>
            <template #content>
                <DataTable :value="visibleLogs" dataKey="id" v-model:filters="filters" :globalFilterFields="['employee.full_name', 'employee.employee_no', 'notes', 'status', 'approved_by_name', 'reject_reason']" paginator :rows="10" :rowsPerPageOptions="[10, 25, 50]" removableSort responsiveLayout="scroll">
                    <template #header>
                        <div class="table-toolbar">
                            <IconField class="table-toolbar-search"><InputIcon class="pi pi-search" /><InputText v-model="filters.global.value" placeholder="Search employee, notes, status" fluid /></IconField>
                            <div class="attendance-filters">
                                <Select v-model="employeeFilter" :options="employees" optionLabel="label" optionValue="id" placeholder="All employees" showClear filter />
                                <Select v-model="statusFilter" :options="statusOptions" placeholder="All statuses" showClear />
                            </div>
                        </div>
                    </template>
                    <template #empty><div class="empty-state"><i class="pi pi-clock app-muted" /><p class="app-hint">No attendance logs match these filters.</p></div></template>
                    <Column field="employee.employee_no" header="Employee no." sortable />
                    <Column field="employee.full_name" header="Employee" sortable />
                    <Column field="date" header="Date" sortable />
                    <Column field="time_in" header="Time in"><template #body="{ data }">{{ data.time_in ?? "—" }}</template></Column>
                    <Column field="time_out" header="Time out"><template #body="{ data }">{{ data.time_out ?? "—" }}</template></Column>
                    <Column field="duration" header="Duration"><template #body="{ data }">{{ data.duration }} hrs</template></Column>
                    <Column field="status" header="Status" sortable><template #body="{ data }"><Tag :value="data.status" :severity="severity(data.status)" class="capitalize" /></template></Column>
                    <Column header="Approval">
                        <template #body="{ data }">
                            <div v-if="data.status === 'approved'">
                                {{ data.approved_by_name ?? "—" }}
                                <small v-if="data.approved_at_label" class="app-hint block">{{ data.approved_at_label }}</small>
                            </div>
                            <span v-else-if="data.status === 'rejected'">{{ data.reject_reason || "—" }}</span>
                            <span v-else>—</span>
                        </template>
                    </Column>
                    <Column field="notes" header="Notes"><template #body="{ data }">{{ data.notes || "—" }}</template></Column>
                    <Column header="" style="width: 8rem"><template #body="{ data }"><div class="row-actions"><Button v-if="data.status === 'pending' && data.time_in && data.time_out" icon="pi pi-check" aria-label="Approve" v-tooltip.top="'Approve'" severity="success" text rounded size="small" @click="approve(data)" /><Button icon="pi pi-pencil" aria-label="Edit" v-tooltip.top="'Edit'" text rounded size="small" @click="open(data)" /></div></template></Column>
                </DataTable>
            </template>
        </Card>

        <EntityFormDialog v-model:visible="dialogVisible" :title="isEdit ? 'Edit attendance' : 'Add attendance'" :submit-label="isEdit ? 'Save changes' : 'Create attendance'" :fields="fields" :form="form" @submit="submit" />
    </div>
</template>
