<script setup>
import { Head } from "@inertiajs/vue3";
import { useAuth } from "../../Composables/useAuth";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from "primevue/button";
import Tag from "primevue/tag";

defineProps({
    companies: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
</script>

<template>
    <Head title="Companies" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Companies</h1>

            <!-- hidden for company_admin: no companies.create permission -->
            <Button v-if="can.create" label="Add company" icon="pi pi-plus" />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only your own company.
        </p>

        <Card>
            <template #content>
                <DataTable
                    :value="companies"
                    dataKey="id"
                    responsiveLayout="scroll"
                >
                    <Column field="name" header="Name" />
                    <Column field="users_count" header="Users" />
                    <Column field="employees_count" header="Employees" />
                    <Column header="Status">
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
                        <template #body>
                            <div class="row-actions">
                                <Button
                                    v-if="can.edit"
                                    icon="pi pi-pencil"
                                    text
                                    rounded
                                    size="small"
                                />
                                <Button
                                    v-if="can.delete"
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    rounded
                                    size="small"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
