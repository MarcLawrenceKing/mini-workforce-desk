<script setup>
import { computed, ref } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
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

const monthLabel = computed(() =>
    new Intl.DateTimeFormat(undefined, { month: "long", year: "numeric", timeZone: "UTC" })
        .format(new Date(`${props.month}-01T00:00:00Z`)),
);
const workedHours = computed(() => {
    const minutes = props.summary.worked_minutes;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
});
const money = (value) => new Intl.NumberFormat(undefined, {
    style: "currency", currency: "PHP",
}).format(value);

function changeMonth(offset) {
    const date = new Date(`${props.month}-01T00:00:00Z`);
    date.setUTCMonth(date.getUTCMonth() + offset);
    router.get("/attendance-logs", { month: date.toISOString().slice(0, 7) }, {
        preserveState: true, preserveScroll: true, replace: true,
    });
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

        <div class="attendance-summary">
            <Card>
                <template #title>Hrs worked this month</template>
                <template #content><strong class="summary-value">{{ workedHours }}</strong></template>
            </Card>
            <Card>
                <template #title>Expected salary this month</template>
                <template #content>
                    <strong class="summary-value">{{ money(summary.expected_salary) }}</strong>
                    <p class="app-hint">At {{ money(summary.rate_per_hr) }} per hour</p>
                </template>
            </Card>
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
                <DataTable :value="logs" dataKey="id" responsiveLayout="scroll">
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
