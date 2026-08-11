<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Button from "primevue/button";
import Message from "primevue/message";

const form = useForm({
    name: "",
    username: "",
    email: "",
    password: "",
    password_confirmation: "",
});

function submit() {
    form.post("/register", {
        onFinish: () => form.reset("password", "password_confirmation"),
    });
}
</script>

<template>
    <Head title="Set up administrator" />

    <Card>
        <template #title>Set up the administrator</template>
        <template #subtitle>
            <span class="app-muted">This is a one-time step.</span>
        </template>

        <template #content>
            <Message severity="info" :closable="false" class="mb-4">
                This account gets the <strong>Administrator</strong> role and
                full access to every company. Once it exists, registration
                closes and all further accounts are created from the Users page.
            </Message>

            <form class="stack" @submit.prevent="submit">
                <div class="field">
                    <label for="name" class="field-label">Full name</label>
                    <InputText
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        fluid
                        autofocus
                    />
                    <small v-if="form.errors.name" class="field-error">
                        {{ form.errors.name }}
                    </small>
                </div>

                <div class="field">
                    <label for="username" class="field-label">Username</label>
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
                    <label for="password" class="field-label">Password</label>
                    <Password
                        id="password"
                        v-model="form.password"
                        toggleMask
                        :invalid="!!form.errors.password"
                        fluid
                    />
                    <small v-if="form.errors.password" class="field-error">
                        {{ form.errors.password }}
                    </small>
                </div>

                <div class="field">
                    <label for="password_confirmation" class="field-label">
                        Confirm password
                    </label>
                    <Password
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :feedback="false"
                        toggleMask
                        fluid
                    />
                </div>

                <Button
                    type="submit"
                    label="Create administrator"
                    icon="pi pi-shield"
                    :loading="form.processing"
                    fluid
                />
            </form>
        </template>

        <template #footer>
            <p class="auth-footer">
                Already set up?
                <Link href="/login" class="font-medium">Log in</Link>
            </p>
        </template>
    </Card>
</template>
