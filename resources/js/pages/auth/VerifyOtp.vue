<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import OtpInput from '@/components/OtpInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    email: string;
    status?: string;
    length: number;
    secondsUntilResend: number;
}>();

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('password.otp.verify'), {
        // A rejected code should leave empty boxes ready for the next attempt,
        // not six digits the user has to clear by hand.
        onError: () => {
            form.reset('code');
            otp.value?.focus();
        },
    });
};

const otp = ref<InstanceType<typeof OtpInput> | null>(null);

onMounted(() => otp.value?.focus());

/* Resend countdown. The server owns the cooldown; this only mirrors it so the
   button does not invite a tap that will be refused. */
const remaining = ref(props.secondsUntilResend);
const timer = window.setInterval(() => {
    if (remaining.value > 0) remaining.value--;
}, 1000);

onUnmounted(() => window.clearInterval(timer));

const canResend = computed(() => remaining.value <= 0 && !form.processing);

const resending = ref(false);

function resend() {
    if (!canResend.value) return;

    resending.value = true;
    router.post(
        route('password.otp.resend'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => (remaining.value = 60),
            onFinish: () => (resending.value = false),
        },
    );
}
</script>

<template>
    <AuthLayout title="Enter your code" :description="`We sent a ${length}-digit code to ${email}`">
        <Head title="Enter your code" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <OtpInput
                    ref="otp"
                    v-model="form.code"
                    :length="length"
                    :invalid="!!form.errors.code"
                    :disabled="form.processing"
                    @complete="submit"
                />
                <InputError class="text-center" :message="form.errors.code" />
            </div>

            <Button type="submit" class="press w-full" :disabled="form.processing || form.code.length < length">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                Verify code
            </Button>

            <div class="text-center text-sm text-muted-foreground">
                <span v-if="!canResend">
                    Didn't get it? You can ask again in
                    <span class="tabular font-mono">{{ remaining }}s</span>
                </span>
                <button
                    v-else
                    type="button"
                    class="press font-medium text-foreground underline underline-offset-4 disabled:opacity-50"
                    :disabled="resending"
                    @click="resend"
                >
                    Send a new code
                </button>
            </div>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>Wrong address?</span>
                <TextLink :href="route('password.request')">Start over</TextLink>
            </div>
        </form>
    </AuthLayout>
</template>
