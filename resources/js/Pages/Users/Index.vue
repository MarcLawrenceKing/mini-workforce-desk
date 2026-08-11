<script setup>
import { Head } from "@inertiajs/vue3";
import { useAuth } from "../../Composables/useAuth";
import Card from "primevue/card";
import DataTable from "primevue/datatable";
import Column from "primevue/column";
import Button from "primevue/button";
import Tag from "primevue/tag";

defineProps({
    users: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    assignableRoles: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

const { hasRole } = useAuth();
</script>

<template>
    <Head title="Users" />

    <div class="page">
        <div class="page-header">
            <h1 class="page-title">Users</h1>

            <Button v-if="can.create" label="Add user" icon="pi pi-plus" />
        </div>

        <p v-if="!hasRole('admin')" class="app-hint">
            You are seeing only users in your own company.
        </p>

        <Card>
            <template #content>
                <DataTable :value="users" dataKey="id" responsiveLayout="scroll">
                    <Column field="name" header="Name" />
                    <Column field="email" header="Email" />
                    <Column field="username" header="Username" />
                    <Column header="Company">
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
                    <Column header="Status">
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
