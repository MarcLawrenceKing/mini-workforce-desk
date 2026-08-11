<script setup>
import { Head } from "@inertiajs/vue3";
import Card from "primevue/card";
import Button from "primevue/button";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Tag from "primevue/tag";

defineProps({ staff: { type: Array, default: () => [] } });
</script>

<template>
    <Head title="Dashboard" />
    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Workforce</h1>
            <Button label="Add staff" icon="pi pi-plus" />
        </div>

        <Card>
            <template #content>
                <DataTable
                    :value="staff"
                    dataKey="id"
                    responsiveLayout="scroll"
                >
                    <Column field="name" header="Name" />
                    <Column field="role" header="Role" />
                    <Column header="Status">
                        <template #body="{ data }">
                            <Tag
                                :value="data.status"
                                :severity="
                                    data.status === 'Active'
                                        ? 'success'
                                        : 'warn'
                                "
                            />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
