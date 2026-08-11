<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Card from "primevue/card";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Checkbox from "primevue/checkbox";
import Button from "primevue/button";
import Message from "primevue/message";

defineProps({
    status: { type: String, default: null },
    // False once an admin exists — registration is a one-time bootstrap.
    canRegister: { type: Boolean, default: false },
});

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

function submit() {
    form.post("/login", {
        onFinish: () => form.reset("password"),
    });
}
</script>

<template>
    <Head title="Log in" />

    <Card>
        <template #title>Log in</template>
        <template #subtitle>
            <span class="app-muted">Use your work email to continue.</span>
        </template>

        <template #content>
            <Message v-if="status" severity="success" class="mb-4">
                {{ status }}
            </Message>

            <form class="stack" @submit.prevent="submit">
                <div class="field">
                    <label for="email" class="field-label">Email</label>
                    <InputText
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        :invalid="!!form.errors.email"
                        fluid
                        autofocus
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
                        :feedback="false"
                        toggleMask
                        autocomplete="current-password"
                        :invalid="!!form.errors.password"
                        fluid
                    />
                    <small v-if="form.errors.password" class="field-error">
                        {{ form.errors.password }}
                    </small>
                </div>

                <div class="field-inline">
                    <Checkbox v-model="form.remember" inputId="remember" binary />
                    <label for="remember" class="text-sm">Remember me</label>
                </div>

                <Button
                    type="submit"
                    label="Log in"
                    icon="pi pi-sign-in"
                    :loading="form.processing"
                    fluid
                />
            </form>
        </template>

        <template v-if="canRegister" #footer>
            <p class="auth-footer">
                First time here?
                <Link href="/register" class="font-medium">
                    Set up the administrator account
                </Link>
            </p>
        </template>
    </Card>
</template>
