<script setup>
import { Head } from "@inertiajs/vue3";
import { useAuth } from "../../Composables/useAuth";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from "primevue/button";

defineProps({
    employees: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
</script>

<template>
    <Head title="Employees" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Employees</h1>

            <Button v-if="can.create" label="Add employee" icon="pi pi-plus" />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only employees in your own company.
        </p>

        <Card>
            <template #content>
                <DataTable
                    :value="employees"
                    dataKey="id"
                    responsiveLayout="scroll"
                >
                    <Column field="employee_no" header="Employee no." />
                    <Column field="full_name" header="Name" />
                    <Column header="Company">
                        <template #body="{ data }">
                            {{ data.company?.name ?? "—" }}
                        </template>
                    </Column>
                    <Column header="Linked account">
                        <template #body="{ data }">
                            {{ data.user?.email ?? "—" }}
                        </template>
                    </Column>
                    <Column header="" style="width: 6rem">
                        <template #body>
                            <Button
                                v-if="can.edit"
                                icon="pi pi-pencil"
                                text
                                rounded
                                size="small"
                            />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
