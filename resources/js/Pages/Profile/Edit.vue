<script setup>
import Modulo6Layout from "@/Layouts/Modulo6Layout.vue";
import DeleteUserForm from "./Partials/DeleteUserForm.vue";
import UpdatePasswordForm from "./Partials/UpdatePasswordForm.vue";
import UpdateProfileInformationForm from "./Partials/UpdateProfileInformationForm.vue";
import TwoFactorAuthenticationForm from "@/Components/TwoFactorAuthenticationForm.vue";
import { Head } from "@inertiajs/vue3";

defineProps({
    twoFactorEnabled: Boolean,
    twoFactorConfigurationPending: Boolean,
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Mi perfil" />

    <Modulo6Layout headerTitle="Mi perfil">
        <div class="campus-page space-y-6">
            <section class="campus-profile-summary">
                <div
                    class="campus-avatar campus-avatar-large"
                    aria-hidden="true"
                >
                    {{
                        $page.props.auth.user.name
                            .trim()
                            .split(/\s+/)
                            .slice(0, 2)
                            .map((n) => n[0])
                            .join("")
                            .toUpperCase()
                    }}
                </div>
                <div class="min-w-0">
                    <h1>{{ $page.props.auth.user.name }}</h1>
                    <p>
                        {{
                            $page.props.auth.user.matricula
                                ? "Mat. " +
                                  $page.props.auth.user.matricula +
                                  " · "
                                : ""
                        }}{{ $page.props.auth.user.email }}
                    </p>
                    <span class="campus-profile-tag">Campus Digital</span>
                </div>
            </section>
            <div class="grid gap-6 xl:grid-cols-2 items-start">
                <div class="contents">
                    <div class="campus-profile-card">
                        <UpdateProfileInformationForm
                            :must-verify-email="mustVerifyEmail"
                            :status="status"
                            class="max-w-xl"
                        />
                    </div>

                    <div class="campus-profile-card">
                        <UpdatePasswordForm
                            id="seguridad"
                            class="max-w-xl scroll-mt-24"
                        />
                    </div>

                    <div class="campus-profile-card">
                        <TwoFactorAuthenticationForm :initially-enabled="twoFactorEnabled" :initially-pending="twoFactorConfigurationPending" />
                    </div>
                    <div class="campus-profile-card">
                        <DeleteUserForm class="max-w-xl" />
                    </div>
                </div>
            </div>
        </div>
    </Modulo6Layout>
</template>
