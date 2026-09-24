<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';
import { computed, ref } from 'vue';

const props = defineProps({
    initiallyEnabled: { type: Boolean, default: false },
    initiallyPending: { type: Boolean, default: false },
});

const enabled = ref(props.initiallyEnabled);
const configuring = ref(props.initiallyPending);
const busy = ref(false);
const qrSvg = ref('');
const secretKey = ref('');
const recoveryCodes = ref([]);
const code = ref('');
const password = ref('');
const passwordAction = ref(null);
const showPasswordDialog = ref(false);
const error = ref('');
const success = ref('');
const fieldErrors = ref({});

const statusText = computed(() => {
    if (enabled.value) return 'Activada';
    if (configuring.value) return 'Pendiente de confirmación';

    return 'Desactivada';
});

const clearMessages = () => {
    error.value = '';
    success.value = '';
    fieldErrors.value = {};
};

const normalizeError = (response, fallback) => {
    if (response?.status === 422) {
        fieldErrors.value = response.data?.errors ?? {};
    }

    if (response?.status === 429) {
        return 'Demasiados intentos. Espera un momento y vuelve a intentarlo.';
    }

    return response?.data?.message ?? fallback;
};

const askForPassword = (action) => {
    clearMessages();
    password.value = '';
    passwordAction.value = action;
    showPasswordDialog.value = true;
};

const loadSetup = async () => {
    const [qrResult, secretResult] = await Promise.allSettled([
        axios.get('/user/two-factor-qr-code'),
        axios.get('/user/two-factor-secret-key'),
    ]);

    qrSvg.value = qrResult.status === 'fulfilled' ? (qrResult.value.data.svg ?? '') : '';
    secretKey.value = secretResult.status === 'fulfilled'
        ? (secretResult.value.data.secretKey ?? secretResult.value.data.secret_key ?? '')
        : '';

    const passwordRequired = [qrResult, secretResult].find(
        (result) => result.status === 'rejected' && result.reason?.response?.status === 423,
    );
    if (passwordRequired) {
        throw passwordRequired.reason;
    }

    if (qrResult.status === 'rejected' && secretResult.status === 'rejected') {
        throw qrResult.reason;
    }

    if (qrResult.status === 'rejected') {
        error.value = 'No se pudo cargar el código QR. Puedes usar la clave manual.';
    } else if (secretResult.status === 'rejected') {
        error.value = 'No se pudo cargar la clave manual. Puedes escanear el código QR.';
    }
};

const loadRecoveryCodes = async () => {
    const { data } = await axios.get('/user/two-factor-recovery-codes');
    recoveryCodes.value = Array.isArray(data) ? data : [];
};

const enableTwoFactor = async () => {
    await axios.post('/user/two-factor-authentication');
    configuring.value = true;
    enabled.value = false;
    passwordAction.value = 'resume';
    await loadSetup();
    success.value = 'Escanea el código QR con una aplicación de autenticación y confirma el código de 6 dígitos.';
};

const resumeSetup = async () => {
    await loadSetup();
    configuring.value = true;
};

const disableTwoFactor = async (message) => {
    await axios.delete('/user/two-factor-authentication');
    enabled.value = false;
    configuring.value = false;
    qrSvg.value = '';
    secretKey.value = '';
    recoveryCodes.value = [];
    success.value = message;
};

const regenerateRecoveryCodes = async () => {
    await axios.post('/user/two-factor-recovery-codes');
    await loadRecoveryCodes();
    success.value = 'Se generaron nuevos códigos. Los anteriores dejaron de ser válidos.';
};

const showRecoveryCodes = async () => {
    await loadRecoveryCodes();
};

const run = async (action) => {
    busy.value = true;
    clearMessages();

    try {
        await action();
    } catch (exception) {
        if (exception.response?.status === 423) {
            askForPassword(passwordAction.value);
        } else {
            error.value = normalizeError(exception.response, 'No fue posible completar la operación de 2FA.');
        }
    } finally {
        busy.value = false;
    }
};

const begin = (action) => {
    passwordAction.value = action;
    askForPassword(action);
};

const confirmPasswordAndContinue = async () => {
    busy.value = true;
    clearMessages();

    try {
        await axios.post('/confirm-password', { password: password.value });
        showPasswordDialog.value = false;
        password.value = '';

        const actions = {
            enable: enableTwoFactor,
            resume: resumeSetup,
            disable: () => disableTwoFactor('Autenticación de dos factores desactivada.'),
            cancel: () => disableTwoFactor('Configuración cancelada.'),
            regenerate: regenerateRecoveryCodes,
            recovery: showRecoveryCodes,
            confirm: confirmTwoFactor,
        };

        await actions[passwordAction.value]();
        if (!showPasswordDialog.value) {
            passwordAction.value = null;
        }
    } catch (exception) {
        if (exception.response?.status === 423) {
            askForPassword(passwordAction.value);
        } else {
            error.value = normalizeError(exception.response, 'No fue posible completar la operación de 2FA.');
        }
    } finally {
        busy.value = false;
    }
};

const confirmTwoFactor = async () => {
    busy.value = true;
    clearMessages();

    try {
        await axios.post('/user/confirmed-two-factor-authentication', {
            code: code.value.replace(/\s/g, ''),
        });
        code.value = '';
        configuring.value = false;
        enabled.value = true;
        qrSvg.value = '';
        secretKey.value = '';
        success.value = 'Autenticación de dos factores activada correctamente.';
        try {
            await loadRecoveryCodes();
        } catch (exception) {
            if (exception.response?.status === 423) {
                askForPassword('recovery');
            } else {
                error.value = normalizeError(exception.response, 'No fue posible cargar los códigos de recuperación.');
            }
        }
    } catch (exception) {
        if (exception.response?.status === 423) {
            askForPassword('confirm');
        } else {
            error.value = normalizeError(exception.response, 'El código no es válido o ya expiró.');
        }
    } finally {
        busy.value = false;
    }
};

const protectedAction = (action) => {
    passwordAction.value = action;
    run(async () => {
        const actions = {
            recovery: showRecoveryCodes,
        };

        await actions[action]();
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Autenticación de dos factores</h2>
            <p class="mt-1 text-sm text-gray-600">
                Protege tu inicio de sesión con TOTP mediante una aplicación de autenticación compatible.
            </p>
        </header>

        <div class="mt-5 rounded-lg border border-gray-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-900">Estado</p>
                    <p class="mt-1 text-sm font-semibold" :class="enabled ? 'text-emerald-600' : configuring ? 'text-amber-600' : 'text-gray-500'">
                        {{ statusText }}
                    </p>
                </div>

                <PrimaryButton v-if="!enabled && !configuring" type="button" :disabled="busy" @click="begin('enable')">
                    Activar 2FA
                </PrimaryButton>
                <div v-else-if="configuring" class="flex gap-3">
                    <SecondaryButton type="button" :disabled="busy" @click="begin('resume')">Continuar</SecondaryButton>
                    <SecondaryButton type="button" :disabled="busy" @click="begin('cancel')">Cancelar</SecondaryButton>
                </div>
                <SecondaryButton v-else type="button" :disabled="busy" @click="begin('disable')">
                    Desactivar 2FA
                </SecondaryButton>
            </div>
        </div>

        <p v-if="success" class="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ success }}</p>
        <p v-if="error" class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

        <div v-if="configuring && (qrSvg || secretKey)" class="mt-6 space-y-5">
            <p class="text-sm text-gray-600">Escanea el QR con Google Authenticator u otra aplicación TOTP compatible, o usa la clave manual. Después introduce el código de seis dígitos de la aplicación.</p>
            <div v-if="qrSvg" class="inline-block rounded-xl border bg-white p-4" v-html="qrSvg"></div>

            <div v-if="secretKey" class="rounded-lg bg-gray-50 p-4">
                <p class="text-sm font-medium text-gray-900">Clave manual</p>
                <code class="mt-2 block break-all text-sm text-gray-700">{{ secretKey }}</code>
            </div>

            <div class="max-w-xs">
                <InputLabel for="two_factor_code" value="Código de autenticación" />
                <TextInput id="two_factor_code" v-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 block w-full tracking-[0.35em]" placeholder="000000" />
                <InputError class="mt-2" :message="fieldErrors.code?.[0]" />
                <PrimaryButton type="button" :disabled="busy || code.length < 6" class="mt-4" @click="confirmTwoFactor">
                    Confirmar y activar
                </PrimaryButton>
            </div>
        </div>

        <div v-if="enabled" class="mt-6 space-y-4">
            <div class="flex flex-wrap gap-3">
                <SecondaryButton type="button" :disabled="busy" @click="protectedAction('recovery')">Mostrar códigos de recuperación</SecondaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="begin('regenerate')">Regenerar códigos</SecondaryButton>
            </div>

            <div v-if="recoveryCodes.length" class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="font-medium text-amber-900">Códigos de recuperación</p>
                <p class="mt-1 text-sm text-amber-800">Guárdalos en un lugar seguro. Cada código es de un solo uso.</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <code v-for="item in recoveryCodes" :key="item" class="rounded bg-white px-3 py-2 text-sm text-gray-800">{{ item }}</code>
                </div>
            </div>
        </div>

        <div v-if="showPasswordDialog" class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <p class="font-medium text-gray-900">Confirma tu contraseña</p>
            <p class="mt-1 text-sm text-gray-600">Esta operación modifica o revela datos sensibles de seguridad.</p>
            <div class="mt-3 max-w-sm">
                <InputLabel for="two_factor_password" value="Contraseña actual" />
                <TextInput id="two_factor_password" v-model="password" type="password" autocomplete="current-password" class="mt-1 block w-full" @keyup.enter="confirmPasswordAndContinue" />
                <InputError class="mt-2" :message="fieldErrors.password?.[0]" />
            </div>
            <div class="mt-4 flex gap-3">
                <PrimaryButton type="button" :disabled="busy || !password" @click="confirmPasswordAndContinue">Continuar</PrimaryButton>
                <SecondaryButton type="button" :disabled="busy" @click="showPasswordDialog = false">Cancelar</SecondaryButton>
            </div>
        </div>
    </section>
</template>
