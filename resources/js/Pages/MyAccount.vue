<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Button from "primevue/button";
import Tag from "primevue/tag";

const props = defineProps({
    account: { type: Object, required: true },
    employee: { type: Object, default: null },
});

const form = useForm({
    name: props.account.name,
    email: props.account.email,
    username: props.account.username,
});
</script>

<template>
    <Head title="My Account" />

    <div class="page">
        <div class="page-header">
            <div>
                <h1 class="page-title">My Account</h1>
                <p class="app-hint">
                    {{ account.company?.name ?? "All companies" }}
                </p>
            </div>
            <div class="tag-list">
                <Tag
                    v-for="role in account.roles"
                    :key="role"
                    :value="role"
                    severity="info"
                />
            </div>
        </div>

        <Card>
            <template #title>Profile</template>
            <template #content>
                <form
                    class="stack max-w-lg"
                    @submit.prevent="form.put('/my-account')"
                >
                    <div class="field">
                        <label for="name" class="field-label">Name</label>
                        <InputText
                            id="name"
                            v-model="form.name"
                            :invalid="!!form.errors.name"
                            fluid
                        />
                        <small v-if="form.errors.name" class="field-error">
                            {{ form.errors.name }}
                        </small>
                    </div>

                    <div class="field">
                        <label for="email" class="field-label">Email</label>
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            :invalid="!!form.errors.email"
                            fluid
                        />
                        <small v-if="form.errors.email" class="field-error">
                            {{ form.errors.email }}
                        </small>
                    </div>

                    <div class="field">
                        <label for="username" class="field-label">
                            Username
                        </label>
                        <InputText
                            id="username"
                            v-model="form.username"
                            :invalid="!!form.errors.username"
                            fluid
                        />
                        <small v-if="form.errors.username" class="field-error">
                            {{ form.errors.username }}
                        </small>
                    </div>

                    <div>
                        <Button
                            type="submit"
                            label="Save changes"
                            icon="pi pi-check"
                            :loading="form.processing"
                        />
                    </div>
                </form>
            </template>
        </Card>

        <!-- Employees reach their own record here; they have no /employees access. -->
        <Card v-if="employee">
            <template #title>Employee record</template>
            <template #content>
                <dl class="detail-grid">
                    <div>
                        <dt class="app-hint">Employee no.</dt>
                        <dd>{{ employee.employee_no }}</dd>
                    </div>
                    <div>
                        <dt class="app-hint">Name</dt>
                        <dd>{{ employee.full_name }}</dd>
                    </div>
                    <div>
                        <dt class="app-hint">Company</dt>
                        <dd>{{ account.company?.name ?? "—" }}</dd>
                    </div>
                </dl>
            </template>
        </Card>
    </div>
</template>
