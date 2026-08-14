<script setup>
import { computed, ref, watch } from "vue";
import { Head } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useApi } from "@/Composables/useApi";
import { useCrudDialog } from "../../Composables/useCrudDialog";
import { useTableSearch } from "../../Composables/useTableSearch";
import EntityFormDialog from "../../Components/EntityFormDialog.vue";
import Button from "primevue/button";
import Card from "primevue/card";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import IconField from "primevue/iconfield";
import InputIcon from "primevue/inputicon";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";

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

/*
 * Task 9 — this page now talks to the JSON API with Axios for everything except
 * the create/edit dialog, which stays on Inertia's useForm. The contrast is the
 * point: a full form is a page-shaped action, while approving or deleting one
 * row is a data-shaped one that shouldn't re-render the page.
 *
 * `rows` and `month` are local copies of the Inertia props, because an Axios
 * response has to be written somewhere — Inertia props are read-only. They
 * resync whenever Inertia does deliver a new page (e.g. after the dialog saves).
 */
const rows = ref([...props.logs]);
const month = ref(props.month);
const { loading, errors, message, call, clear } = useApi();
const busyId = ref(null);

watch(() => props.logs, (logs) => { rows.value = [...logs]; });
watch(() => props.month, (value) => { month.value = value; });

const statusOptions = computed(() => props.statuses.map((value) => ({
    value, label: value[0].toUpperCase() + value.slice(1),
})));
const visibleLogs = computed(() => rows.value.filter((log) =>
    (!employeeFilter.value || log.employee_id === employeeFilter.value)
    && (!statusFilter.value || log.status === statusFilter.value),
));
const monthLabel = computed(() => new Intl.DateTimeFormat(undefined, {
    month: "long", year: "numeric", timeZone: "UTC",
}).format(new Date(`${month.value}-01T00:00:00Z`)));

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

/** GET /api/time-logs?month=… — swaps the table's data, no page navigation. */
async function changeMonth(offset) {
    const date = new Date(`${month.value}-01T00:00:00Z`);
    date.setUTCMonth(date.getUTCMonth() + offset);
    const target = date.toISOString().slice(0, 7);

    try {
        const { data } = await call((api) => api.get("/time-logs", { params: { month: target } }));
        rows.value = data.data;
        month.value = data.meta.month;
    } catch {
        // useApi already put the reason in `message`; the old month stays on screen.
    }
}

/** PUT /api/time-logs/{id}/approve — patches the one row that changed. */
function approve(log) {
    confirm.require({
        header: "Approve attendance", message: `Approve ${log.employee.full_name}'s attendance for ${log.date}?`,
        icon: "pi pi-check-circle", acceptLabel: "Approve", rejectLabel: "Cancel",
        rejectProps: { severity: "secondary", text: true },
        accept: async () => {
            busyId.value = log.id;
            try {
                const { data } = await call((api) => api.put(`/time-logs/${log.id}/approve`));
                Object.assign(log, data.data);
            } catch {
                // 403/422 land in `message`; 401 already redirected to /login.
            } finally {
                busyId.value = null;
            }
        },
    });
}

/*
 * Reject — the 422 demonstration. The API requires a reason when the status is
 * `rejected`; submit without one and Laravel answers 422 with
 * { errors: { reject_reason: ["…"] } }, which useApi() puts in `errors` and the
 * dialog renders under the field. Inertia's useForm does this for you; with
 * Axios you wire it yourself, which is the whole point of the exercise.
 */
const rejecting = ref(null);
const rejectReason = ref("");

function openReject(log) {
    rejecting.value = log;
    rejectReason.value = log.reject_reason ?? "";
    clear();
}

async function submitReject() {
    const log = rejecting.value;

    try {
        const { data } = await call((api) => api.put(`/time-logs/${log.id}`, {
            employee_id: log.employee_id,
            date: log.date,
            time_in: log.time_in,
            time_out: log.time_out,
            notes: log.notes,
            status: "rejected",
            reject_reason: rejectReason.value,
        }));

        Object.assign(log, data.data);
        rejecting.value = null;
    } catch {
        // Dialog stays open with `errors.reject_reason` showing under the field.
    }
}

/** DELETE /api/time-logs/{id} — 204, then drop the row from the table. */
function remove(log) {
    confirm.require({
        header: "Delete attendance", message: `Delete ${log.employee.full_name}'s attendance for ${log.date}? This cannot be undone.`,
        icon: "pi pi-exclamation-triangle", acceptLabel: "Delete", rejectLabel: "Cancel",
        acceptProps: { severity: "danger" },
        rejectProps: { severity: "secondary", text: true },
        accept: async () => {
            busyId.value = log.id;
            try {
                await call((api) => api.delete(`/time-logs/${log.id}`));
                rows.value = rows.value.filter((row) => row.id !== log.id);
            } catch {
                // Left on screen deliberately — it wasn't deleted.
            } finally {
                busyId.value = null;
            }
        },
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

        <!-- Whatever the API refused: 403 no permission, 404 gone, 422 rule broken. -->
        <Message v-if="message" severity="error" closable @close="clear()">{{ message }}</Message>

        <Card>
            <template #title>
                <div class="month-picker">
                    <Button icon="pi pi-chevron-left" aria-label="Previous month" text rounded :disabled="loading" @click="changeMonth(-1)" />
                    <span>{{ monthLabel }}</span>
                    <Button icon="pi pi-chevron-right" aria-label="Next month" text rounded :disabled="loading" @click="changeMonth(1)" />
                    <i v-if="loading" class="pi pi-spin pi-spinner app-muted" aria-label="Loading" />
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
                    <!-- Approve and Delete go through Axios (loading spinner per row); Edit still opens the Inertia form dialog. -->
                    <Column header="" style="width: 10rem">
                        <template #body="{ data }">
                            <div class="row-actions">
                                <Button v-if="data.status === 'pending' && data.time_in && data.time_out" icon="pi pi-check" aria-label="Approve" v-tooltip.top="'Approve'" severity="success" text rounded size="small" :loading="busyId === data.id" :disabled="loading" @click="approve(data)" />
                                <Button v-if="data.status === 'pending'" icon="pi pi-times" aria-label="Reject" v-tooltip.top="'Reject'" severity="warn" text rounded size="small" :disabled="loading" @click="openReject(data)" />
                                <Button icon="pi pi-pencil" aria-label="Edit" v-tooltip.top="'Edit'" text rounded size="small" :disabled="loading" @click="open(data)" />
                                <Button icon="pi pi-trash" aria-label="Delete" v-tooltip.top="'Delete'" severity="danger" text rounded size="small" :loading="busyId === data.id" :disabled="loading" @click="remove(data)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <EntityFormDialog v-model:visible="dialogVisible" :title="isEdit ? 'Edit attendance' : 'Add attendance'" :submit-label="isEdit ? 'Save changes' : 'Create attendance'" :fields="fields" :form="form" @submit="submit" />

        <!-- Axios, not Inertia: `loading` drives the button, `errors.reject_reason` comes straight from the API's 422 body. -->
        <Dialog :visible="rejecting !== null" header="Reject attendance" modal :draggable="false" class="entity-dialog" @update:visible="rejecting = null">
            <div class="stack">
                <p class="app-hint">
                    Rejecting {{ rejecting?.employee?.full_name }}'s attendance for {{ rejecting?.date }}.
                    The employee sees the reason you give here.
                </p>
                <div class="field">
                    <label for="reject-reason" class="field-label">Reason</label>
                    <Textarea id="reject-reason" v-model="rejectReason" rows="3" :invalid="!!errors.reject_reason" fluid />
                    <small v-if="errors.reject_reason" class="field-error">{{ errors.reject_reason[0] }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" text :disabled="loading" @click="rejecting = null" />
                <Button label="Reject" severity="danger" :loading="loading" @click="submitReject()" />
            </template>
        </Dialog>
    </div>
</template>
