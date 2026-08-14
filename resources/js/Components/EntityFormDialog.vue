<script setup>
import { computed } from "vue";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Select from "primevue/select";
import Textarea from "primevue/textarea";
import ToggleSwitch from "primevue/toggleswitch";
import Button from "primevue/button";
import Message from "primevue/message";

/**
 * One modal for every entity. A page hands it a field schema and an Inertia
 * form; create and edit are the same dialog with different state, so the two
 * never drift apart.
 *
 * A field looks like:
 *   { name, label, type, help, placeholder, options, optionLabel, optionValue, when }
 *
 * `when` is a plain boolean — false drops the field entirely, which is how
 * create-only (password) and edit-only (status) fields are expressed.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    submitLabel: { type: String, default: "Save" },
    fields: { type: Array, required: true },
    // The object returned by Inertia's useForm(): values, errors, processing.
    form: { type: Object, required: true },
});

const emit = defineEmits(["update:visible", "submit"]);

const activeFields = computed(() =>
    props.fields.filter((field) => field.when !== false),
);

// Errors for keys that have no field on screen (or a whole-form failure) would
// otherwise be invisible — the user would just see nothing happen.
const orphanErrors = computed(() => {
    const shown = new Set(activeFields.value.map((field) => field.name));

    return Object.entries(props.form.errors ?? {})
        .filter(([key]) => !shown.has(key))
        .map(([, message]) => message);
});

function close() {
    emit("update:visible", false);
}
</script>

<template>
    <Dialog
        :visible="visible"
        :header="title"
        modal
        :draggable="false"
        :dismissableMask="true"
        class="entity-dialog"
        @update:visible="emit('update:visible', $event)"
    >
        <p v-if="subtitle" class="app-hint mb-4">{{ subtitle }}</p>

        <Message
            v-for="(message, index) in orphanErrors"
            :key="index"
            severity="error"
            :closable="false"
            class="mb-4"
        >
            {{ message }}
        </Message>

        <form id="entity-dialog-form" class="stack" @submit.prevent="emit('submit')">
            <template v-for="field in activeFields" :key="field.name">
                <!-- A switch reads better with the label beside the control. -->
                <div v-if="field.type === 'switch'" class="field">
                    <div class="field-inline">
                        <ToggleSwitch
                            :inputId="field.name"
                            v-model="form[field.name]"
                        />
                        <label :for="field.name" class="field-label">
                            {{ field.label }}
                        </label>
                    </div>
                    <small v-if="field.help" class="app-hint">
                        {{ field.help }}
                    </small>
                    <small v-if="form.errors[field.name]" class="field-error">
                        {{ form.errors[field.name] }}
                    </small>
                </div>

                <div v-else class="field">
                    <label :for="field.name" class="field-label">
                        {{ field.label }}
                    </label>

                    <Select
                        v-if="field.type === 'select'"
                        :inputId="field.name"
                        v-model="form[field.name]"
                        :options="field.options ?? []"
                        :optionLabel="field.optionLabel ?? 'label'"
                        :optionValue="field.optionValue ?? 'value'"
                        :placeholder="field.placeholder ?? 'Select…'"
                        :showClear="field.clearable === true"
                        :filter="(field.options?.length ?? 0) > 8"
                        :invalid="!!form.errors[field.name]"
                        fluid
                    />

                    <Password
                        v-else-if="field.type === 'password'"
                        :inputId="field.name"
                        v-model="form[field.name]"
                        :feedback="field.feedback !== false"
                        toggleMask
                        autocomplete="new-password"
                        :invalid="!!form.errors[field.name]"
                        fluid
                    />

                    <Textarea
                        v-else-if="field.type === 'textarea'"
                        :id="field.name"
                        v-model="form[field.name]"
                        rows="3"
                        :invalid="!!form.errors[field.name]"
                        fluid
                    />

                    <div v-else-if="field.type === 'file'" class="stack">
                        <img
                            v-if="field.preview"
                            :src="field.preview"
                            :alt="`${field.label} preview`"
                            class="h-24 w-24 rounded-full object-cover"
                        />
                        <input
                            :id="field.name"
                            type="file"
                            :accept="field.accept"
                            :aria-invalid="!!form.errors[field.name]"
                            @click="$event.target.value = null"
                            @change="form[field.name] = $event.target.files?.[0] ?? null"
                        />
                    </div>

                    <InputText
                        v-else
                        :id="field.name"
                        v-model="form[field.name]"
                        :type="field.type ?? 'text'"
                        :placeholder="field.placeholder"
                        :invalid="!!form.errors[field.name]"
                        fluid
                    />

                    <small v-if="field.help" class="app-hint">
                        {{ field.help }}
                    </small>
                    <small v-if="form.errors[field.name]" class="field-error">
                        {{ form.errors[field.name] }}
                    </small>
                </div>
            </template>
        </form>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                text
                :disabled="form.processing"
                @click="close"
            />
            <Button
                type="submit"
                form="entity-dialog-form"
                :label="submitLabel"
                icon="pi pi-check"
                :loading="form.processing"
            />
        </template>
    </Dialog>
</template>
