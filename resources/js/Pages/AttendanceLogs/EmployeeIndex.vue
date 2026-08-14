<script setup>
import { computed, ref, watch } from "vue";
import { Head, useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useApi } from "@/Composables/useApi";
import Button from "primevue/button";
import Card from "primevue/card";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import Message from "primevue/message";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";

const props = defineProps({
    logs: { type: Array, default: () => [] },
    month: { type: String, required: true },
    today: { type: String, required: true },
    todayLog: { type: Object, default: null },
    employee: { type: Object, default: null },
    summary: { type: Object, required: true },
});

const dialogVisible = ref(false);
const confirm = useConfirm();
const form = useForm({ notes: "" });

/*
 * Task 9 — browsing months now goes through the JSON API with Axios rather than
 * a full Inertia page visit. Check in / check out stay on Inertia: the API is
 * read-only for an employee (every write is a deliberate 403), and a form
 * submission is a page-shaped action anyway.
 *
 * Local copies, because Axios has to write its answer somewhere and Inertia
 * props are read-only.
 */
const rows = ref([...props.logs]);
const month = ref(props.month);
const workedMinutes = ref(props.summary.worked_minutes);
const { loading, message, call, clear } = useApi();

watch(() => props.logs, (logs) => { rows.value = [...logs]; });
watch(() => props.month, (value) => { month.value = value; });
watch(() => props.summary.worked_minutes, (value) => { workedMinutes.value = value; });

const monthLabel = computed(() =>
    new Intl.DateTimeFormat(undefined, { month: "long", year: "numeric", timeZone: "UTC" })
        .format(new Date(`${month.value}-01T00:00:00Z`)),
);
const workedHours = computed(() => {
    const minutes = workedMinutes.value;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
});
const expectedSalary = computed(() =>
    Math.round((workedMinutes.value / 60) * props.summary.rate_per_hr * 100) / 100,
);
const money = (value) => new Intl.NumberFormat(undefined, {
    style: "currency", currency: "PHP",
}).format(value);

/** GET /api/time-logs?month=… — the API already scopes this to my own logs. */
async function changeMonth(offset) {
    const date = new Date(`${month.value}-01T00:00:00Z`);
    date.setUTCMonth(date.getUTCMonth() + offset);
    const target = date.toISOString().slice(0, 7);

    try {
        const { data } = await call((api) => api.get("/time-logs", { params: { month: target } }));
        rows.value = data.data;
        month.value = data.meta.month;
        // The API totals the minutes, so the salary card recalculates for free.
        workedMinutes.value = data.meta.worked_minutes;
    } catch {
        // useApi filled `message`; the month on screen is unchanged.
    }
}

function openAttendance() {
    form.notes = props.todayLog?.notes ?? "";
    form.clearErrors();
    dialogVisible.value = true;
}

function ask(action) {
    const isIn = action === "in";
    confirm.require({
        header: `Confirm log ${action}`,
        message: `Confirm log ${action}? The current company time will be recorded.`,
        icon: "pi pi-clock",
        acceptLabel: `Log ${action}`,
        rejectLabel: "Cancel",
        rejectProps: { severity: "secondary", text: true },
        accept: () => {
            const options = { preserveScroll: true, onSuccess: () => form.clearErrors() };
            if (isIn) form.post("/attendance-logs/check-in", options);
            else form.put("/attendance-logs/check-out", options);
        },
    });
}

const statusSeverity = (status) => ({
    approved: "success", rejected: "danger", pending: "warn",
}[status] ?? "secondary");
</script>

<template>
    <Head title="Attendance Logs" />

    <div class="page">
        <div class="page-header">
            <div>
                <h1 class="page-title">Attendance Logs</h1>
                <p class="app-hint">Only days with an attendance record are shown.</p>
            </div>
            <Button label="Add attendance" icon="pi pi-plus" :disabled="!employee" @click="openAttendance" />
        </div>

        <Message v-if="!employee" severity="warn" :closable="false">
            Your user account must be linked to an employee record before you can log attendance.
        </Message>

        <!-- Anything the API refused (403 / 404 / 500). A 401 never reaches here: the interceptor sends you to /login. -->
        <Message v-if="message" severity="error" closable @close="clear()">{{ message }}</Message>

        <div class="attendance-summary">
            <Card>
                <template #title>Hrs worked this month</template>
                <template #content><strong class="summary-value">{{ workedHours }}</strong></template>
            </Card>
            <Card>
                <template #title>Expected salary this month</template>
                <template #content>
                    <strong class="summary-value">{{ money(expectedSalary) }}</strong>
                    <p class="app-hint">At {{ money(summary.rate_per_hr) }} per hour</p>
                </template>
            </Card>
        </div>

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
                <DataTable :value="rows" dataKey="id" responsiveLayout="scroll">
                    <template #empty><div class="empty-state"><i class="pi pi-calendar app-muted" /><p class="app-hint">No attendance recorded this month.</p></div></template>
                    <Column field="date" header="Date" sortable />
                    <Column field="time_in" header="Time in"><template #body="{ data }">{{ data.time_in ?? "Not logged in yet" }}</template></Column>
                    <Column field="time_out" header="Time out"><template #body="{ data }">{{ data.time_out ?? "Not logged out yet" }}</template></Column>
                    <Column field="duration" header="Duration"><template #body="{ data }">{{ data.duration }} hrs</template></Column>
                    <Column field="notes" header="Notes"><template #body="{ data }">{{ data.notes || "—" }}</template></Column>
                    <Column field="status" header="Status"><template #body="{ data }"><Tag :value="data.status" :severity="statusSeverity(data.status)" class="capitalize" /></template></Column>
                </DataTable>
            </template>
        </Card>

        <Dialog v-model:visible="dialogVisible" header="Today's attendance" modal :draggable="false" class="entity-dialog">
            <div class="stack">
                <dl class="detail-grid">
                    <div><dt class="app-hint">Employee</dt><dd>{{ employee?.full_name }}</dd></div>
                    <div><dt class="app-hint">Company date</dt><dd>{{ today }}</dd></div>
                </dl>

                <div class="check-row">
                    <div><strong>Time in</strong><p class="app-hint">{{ todayLog?.time_in ?? "Not logged in yet" }}</p></div>
                    <Button label="Check" icon="pi pi-sign-in" :disabled="!!todayLog?.time_in || form.processing" @click="ask('in')" />
                </div>
                <div class="check-row">
                    <div><strong>Time out</strong><p class="app-hint">{{ todayLog?.time_out ?? "Not logged out yet" }}</p></div>
                    <Button label="Check" icon="pi pi-sign-out" :disabled="!todayLog?.time_in || !!todayLog?.time_out || form.processing" @click="ask('out')" />
                </div>
                <div class="field">
                    <label for="attendance-notes" class="field-label">Notes</label>
                    <Textarea id="attendance-notes" v-model="form.notes" rows="3" :invalid="!!form.errors.notes" fluid />
                    <small v-if="form.errors.notes" class="field-error">{{ form.errors.notes }}</small>
                </div>
            </div>
        </Dialog>
    </div>
</template>
