<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, PlugZap, ShieldCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    payments: {
        qr_provider: string;
        khqr_account_id: string | null;
        khqr_merchant_name: string | null;
        khqr_merchant_city: string | null;
        khqr_merchant_id: string | null;
        khqr_acquiring_bank: string | null;
        qr_manual_confirm: boolean;
        bakong_environment: 'production' | 'sandbox';
        bakong_token_set: boolean;
        bakong_token_from_env: boolean;
        bakong_calls_today: number;
        bakong_daily_limit: number;
    };
    providers: { key: string; label: string; verifies: boolean }[];
    defaults: { merchant_name: string; merchant_city: string };
    preview_svg: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payment settings', href: '/settings/payments' }];

const form = useForm({
    qr_provider: props.payments.qr_provider,
    khqr_account_id: props.payments.khqr_account_id ?? '',
    khqr_merchant_name: props.payments.khqr_merchant_name ?? '',
    khqr_merchant_city: props.payments.khqr_merchant_city ?? '',
    khqr_merchant_id: props.payments.khqr_merchant_id ?? '',
    khqr_acquiring_bank: props.payments.khqr_acquiring_bank ?? '',
    qr_manual_confirm: props.payments.qr_manual_confirm,
    bakong_environment: props.payments.bakong_environment,
    // Never pre-filled: the server does not send the saved token back.
    bakong_token: '',
    clear_bakong_token: false as boolean,
});

const isBakong = computed(() => form.qr_provider === 'bakong');
const showMerchant = ref(Boolean(props.payments.khqr_merchant_id));
const testing = ref(false);

function submit() {
    form.put(route('payments.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('bakong_token', 'clear_bakong_token'),
    });
}

function testConnection() {
    testing.value = true;
    router.post(route('payments.test'), {}, { preserveScroll: true, onFinish: () => (testing.value = false) });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Payment settings" />

        <SettingsLayout>
            <form class="space-y-10" @submit.prevent="submit">
                <!-- Provider -->
                <div class="space-y-6">
                    <HeadingSmall
                        title="QR payments"
                        description="How the till takes KHQR. With auto-confirm, the sale completes by itself when the bank reports the money arrived."
                    />

                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="p in providers"
                            :key="p.key"
                            type="button"
                            class="press flex items-start gap-3 rounded-lg border p-3 text-left transition-colors"
                            :class="form.qr_provider === p.key ? 'border-primary bg-primary/10' : 'border-border'"
                            @click="form.qr_provider = p.key"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ p.label }}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">
                                    {{
                                        p.verifies
                                            ? 'A new QR with the amount for each sale, confirmed by the bank.'
                                            : "The shop's fixed QR. The cashier checks the phone and confirms."
                                    }}
                                </span>
                            </span>
                            <Check v-if="form.qr_provider === p.key" class="mt-0.5 size-4 shrink-0 text-primary" />
                        </button>
                    </div>
                    <InputError :message="form.errors.qr_provider" />
                </div>

                <!-- KHQR identity -->
                <div class="space-y-6">
                    <HeadingSmall
                        title="Receiving account"
                        description="Where QR money lands. Customers see the name and city in their banking app before they pay."
                    />

                    <div class="grid gap-6 sm:grid-cols-[1fr_auto]">
                        <div class="grid content-start gap-4">
                            <div class="grid gap-2">
                                <Label for="account">Bakong account ID</Label>
                                <Input id="account" v-model="form.khqr_account_id" class="font-mono" placeholder="shopname@aclb" autocomplete="off" />
                                <InputError :message="form.errors.khqr_account_id" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="mname">Name shown</Label>
                                    <Input id="mname" v-model="form.khqr_merchant_name" maxlength="25" :placeholder="defaults.merchant_name" />
                                    <InputError :message="form.errors.khqr_merchant_name" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="mcity">City</Label>
                                    <Input id="mcity" v-model="form.khqr_merchant_city" maxlength="15" :placeholder="defaults.merchant_city" />
                                    <InputError :message="form.errors.khqr_merchant_city" />
                                </div>
                            </div>

                            <button
                                v-if="!showMerchant"
                                type="button"
                                class="justify-self-start text-xs text-muted-foreground underline underline-offset-2"
                                @click="showMerchant = true"
                            >
                                Registered merchant account? Add merchant ID
                            </button>
                            <div v-else class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="mid">Merchant ID <span class="text-muted-foreground">(optional)</span></Label>
                                    <Input id="mid" v-model="form.khqr_merchant_id" class="font-mono" />
                                    <InputError :message="form.errors.khqr_merchant_id" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="bank">Acquiring bank</Label>
                                    <Input id="bank" v-model="form.khqr_acquiring_bank" placeholder="e.g. ACLEDA Bank" />
                                    <InputError :message="form.errors.khqr_acquiring_bank" />
                                </div>
                            </div>
                        </div>

                        <!-- The saved static QR: scan it with a banking app to check the name before a customer does. -->
                        <div v-if="preview_svg" class="grid justify-items-center gap-1.5">
                            <div
                                class="size-36 overflow-hidden rounded-lg border border-border bg-white p-1 [&>svg]:size-full"
                                v-html="preview_svg"
                            />
                            <span class="text-center text-xs text-muted-foreground">Saved static QR<br />scan to check</span>
                        </div>
                    </div>
                </div>

                <!-- Bakong API -->
                <div v-if="isBakong" class="space-y-6">
                    <HeadingSmall
                        title="Bakong API"
                        description="Register at api-bakong.nbc.gov.kh — the token arrives by email within 24 hours. When it expires, use Renew on that page and paste the new one here."
                    />

                    <div class="grid gap-2">
                        <Label>Environment</Label>
                        <div class="grid grid-cols-2 gap-2 sm:max-w-sm">
                            <button
                                v-for="env in ['production', 'sandbox'] as const"
                                :key="env"
                                type="button"
                                class="press h-10 rounded-lg border text-sm font-medium capitalize"
                                :class="form.bakong_environment === env ? 'border-primary bg-primary/10' : 'border-border text-muted-foreground'"
                                @click="form.bakong_environment = env"
                            >
                                {{ env }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-border px-3 py-2 text-sm">
                        <div class="flex items-baseline justify-between">
                            <span class="text-muted-foreground">Checks used today</span>
                            <span class="tabular font-mono">{{ payments.bakong_calls_today }} / {{ payments.bakong_daily_limit }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            A QR sale uses about four. Past the limit the till asks the cashier to confirm by hand until tomorrow.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="token">API token</Label>
                        <Input
                            id="token"
                            v-model="form.bakong_token"
                            type="password"
                            class="font-mono"
                            autocomplete="off"
                            :placeholder="
                                payments.bakong_token_set ? '•••••••• saved — paste a new one to replace' : 'Paste the token from the Bakong email'
                            "
                        />
                        <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <ShieldCheck class="size-3.5" />
                            Stored encrypted. It is never shown again or sent to the till.
                            <template v-if="payments.bakong_token_from_env">Currently using the token from the server's .env.</template>
                        </p>
                        <label v-if="payments.bakong_token_set && !payments.bakong_token_from_env" class="flex items-center gap-2 text-xs">
                            <input v-model="form.clear_bakong_token" type="checkbox" class="rounded border-input" />
                            Remove the saved token
                        </label>
                        <InputError :message="form.errors.bakong_token" />
                    </div>
                </div>

                <!-- Manual confirm -->
                <div class="space-y-4">
                    <HeadingSmall title="At the till" />
                    <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                        <div>
                            <p class="text-sm font-medium">Let cashiers confirm a QR payment by hand</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                For when the bank is slow to report. Every hand confirmation is written to the activity log. Offline, the static QR is
                                always confirmed by hand.
                            </p>
                        </div>
                        <Switch v-model="form.qr_manual_confirm" />
                    </div>
                </div>

                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                    <Button type="submit" class="press w-full sm:w-auto" :disabled="form.processing">Save</Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="press w-full sm:w-auto"
                        :disabled="testing || form.isDirty"
                        @click="testConnection"
                    >
                        <PlugZap class="size-4" />
                        {{ testing ? 'Testing…' : 'Test saved settings' }}
                    </Button>
                    <Transition
                        enter-from-class="opacity-0"
                        enter-active-class="transition-opacity duration-200"
                        leave-to-class="opacity-0"
                        leave-active-class="transition-opacity duration-500"
                    >
                        <p v-if="form.recentlySuccessful" class="text-center text-sm text-muted-foreground sm:text-left">Saved.</p>
                    </Transition>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
