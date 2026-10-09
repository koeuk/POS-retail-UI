<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency, type CurrencyDef } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, ImageIcon, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    shop: {
        receipt_header: string;
        receipt_footer: string | null;
        currency: string;
        riel_per_usd: number;
        order_prefix: string | null;
        logo: string | null;
        favicon: string | null;
    };
    currencies: { code: string; symbol: string; name: string }[];
}>();

/* What today's first order would be called, so the code is never a surprise. */
const ymd = new Date().toISOString().slice(2, 10).replace(/-/g, '');
const prefixPreview = computed(() => `${(form.order_prefix || 'S1-R1').trim()}-${ymd}-0001`);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shop settings', href: '/settings/shop' }];

const form = useForm({
    receipt_header: props.shop.receipt_header,
    receipt_footer: props.shop.receipt_footer ?? '',
    currency: props.shop.currency,
    riel_per_usd: String(props.shop.riel_per_usd),
    order_prefix: props.shop.order_prefix ?? '',
    logo: null as File | null,
    favicon: null as File | null,
    remove_logo: false as boolean,
    remove_favicon: false as boolean,
});

/*
 * Branding previews. A freshly-chosen file shows immediately via a blob URL;
 * otherwise the saved image shows, unless the person hit Remove — then the
 * tile goes back to the placeholder until they save.
 */
type BrandField = 'logo' | 'favicon';
const blobUrls = ref<Record<BrandField, string | null>>({ logo: null, favicon: null });
const logoInput = ref<HTMLInputElement>();
const faviconInput = ref<HTMLInputElement>();

const logoPreview = computed(() => previewFor('logo'));
const faviconPreview = computed(() => previewFor('favicon'));

function previewFor(field: BrandField): string | null {
    if (blobUrls.value[field]) return blobUrls.value[field];
    if (field === 'logo' ? form.remove_logo : form.remove_favicon) return null;
    const saved = props.shop[field];
    return saved ? `/storage/${saved}` : null;
}

function pick(field: BrandField, event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    dropBlob(field);
    blobUrls.value[field] = URL.createObjectURL(file);
    form[field] = file;
    if (field === 'logo') form.remove_logo = false;
    else form.remove_favicon = false;
    input.value = '';
}

function removeImage(field: BrandField) {
    dropBlob(field);
    form[field] = null;
    if (field === 'logo') form.remove_logo = true;
    else form.remove_favicon = true;
}

function dropBlob(field: BrandField) {
    const url = blobUrls.value[field];
    if (url) URL.revokeObjectURL(url);
    blobUrls.value[field] = null;
}

onBeforeUnmount(() => {
    dropBlob('logo');
    dropBlob('favicon');
});

/*
 * A live example of a $10.00 shelf price, so the effect of the rate is
 * visible before Save. Typing 4100 and seeing ៛41,000 is worth more than a
 * paragraph explaining it.
 */
const preview = computed(() => {
    const def: CurrencyDef = {
        code: form.currency,
        symbol: form.currency === 'KHR' ? '៛' : '$',
        decimals: form.currency === 'KHR' ? 0 : 2,
        riel_per_usd: Number(form.riel_per_usd) || 0,
    };
    return formatCurrency(10, def);
});

function submit() {
    // Browsers cannot send files in a real PUT, so Inertia posts with the
    // method spoofed. Laravel still routes it to update() as a PUT.
    form.transform((data) => ({ ...data, _method: 'put' })).post(route('shop.update'), {
        preserveScroll: true,
        onSuccess: () => {
            dropBlob('logo');
            dropBlob('favicon');
            form.reset('logo', 'favicon', 'remove_logo', 'remove_favicon');
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Shop settings" />

        <SettingsLayout>
            <form class="space-y-10" @submit.prevent="submit">
                <!-- Currency -->
                <div class="space-y-6">
                    <HeadingSmall
                        title="Currency"
                        description="Prices are kept in US dollars. Choose what the till, receipts and menu show, and the rate used to convert."
                    />

                    <div class="grid gap-2">
                        <Label>Show prices in</Label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="c in currencies"
                                :key="c.code"
                                type="button"
                                class="press flex items-center gap-3 rounded-lg border p-3 text-left transition-colors"
                                :class="form.currency === c.code ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="form.currency = c.code"
                            >
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted font-mono text-lg">
                                    {{ c.symbol }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ c.name }}</span>
                                    <span class="block font-mono text-xs text-muted-foreground">{{ c.code }}</span>
                                </span>
                                <Check v-if="form.currency === c.code" class="size-4 shrink-0 text-primary" />
                            </button>
                        </div>
                        <InputError :message="form.errors.currency" />
                    </div>

                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="rate">Exchange rate</Label>
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 font-mono text-sm text-muted-foreground">$1 =</span>
                            <Input
                                id="rate"
                                v-model="form.riel_per_usd"
                                type="number"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                class="tabular font-mono"
                            />
                            <span class="shrink-0 font-mono text-sm text-muted-foreground">៛</span>
                        </div>
                        <InputError :message="form.errors.riel_per_usd" />
                    </div>

                    <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm">
                        A <span class="tabular font-mono">$10.00</span> item will show as
                        <strong class="tabular font-mono text-primary">{{ preview }}</strong>
                    </p>
                </div>

                <!-- Branding -->
                <div class="space-y-6">
                    <HeadingSmall
                        title="Branding"
                        description="The logo shown in the app, and the icon on the browser tab. Square images look best."
                    />

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label>Logo</Label>
                            <!-- The card IS the picker — no separate button. Remove
                                 floats over the preview so the card stays the one control. -->
                            <div class="relative w-full max-w-[11rem]">
                                <button
                                    type="button"
                                    class="press flex aspect-square w-full cursor-pointer flex-col items-center justify-center gap-2 overflow-hidden rounded-xl border border-dashed border-input bg-muted/40 transition-colors hover:border-primary/60 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    aria-label="Choose logo image"
                                    @click="logoInput?.click()"
                                >
                                    <img v-if="logoPreview" :src="logoPreview" alt="Shop logo" class="size-full object-cover" />
                                    <template v-else>
                                        <ImageIcon class="size-8 text-muted-foreground" aria-hidden="true" />
                                        <span class="text-xs text-muted-foreground">Click to upload</span>
                                    </template>
                                </button>
                                <button
                                    v-if="logoPreview"
                                    type="button"
                                    class="press absolute right-2 top-2 grid size-7 place-items-center rounded-full bg-background/90 text-muted-foreground shadow-sm transition-colors hover:text-destructive"
                                    aria-label="Remove logo"
                                    @click="removeImage('logo')"
                                >
                                    <X class="size-4" />
                                </button>
                                <input ref="logoInput" type="file" accept="image/*" class="hidden" @change="pick('logo', $event)" />
                            </div>
                            <p class="text-xs text-muted-foreground">Up to 2 MB. Appears in the sidebar next to the shop name.</p>
                            <InputError :message="form.errors.logo" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label>App icon · favicon</Label>
                            <div class="relative w-full max-w-[11rem]">
                                <button
                                    type="button"
                                    class="press flex aspect-square w-full cursor-pointer flex-col items-center justify-center gap-2 overflow-hidden rounded-xl border border-dashed border-input bg-muted/40 transition-colors hover:border-primary/60 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    aria-label="Choose app icon image"
                                    @click="faviconInput?.click()"
                                >
                                    <img v-if="faviconPreview" :src="faviconPreview" alt="App icon" class="size-full object-cover" />
                                    <template v-else>
                                        <ImageIcon class="size-8 text-muted-foreground" aria-hidden="true" />
                                        <span class="text-xs text-muted-foreground">Click to upload</span>
                                    </template>
                                </button>
                                <button
                                    v-if="faviconPreview"
                                    type="button"
                                    class="press absolute right-2 top-2 grid size-7 place-items-center rounded-full bg-background/90 text-muted-foreground shadow-sm transition-colors hover:text-destructive"
                                    aria-label="Remove app icon"
                                    @click="removeImage('favicon')"
                                >
                                    <X class="size-4" />
                                </button>
                                <input
                                    ref="faviconInput"
                                    type="file"
                                    accept="image/png,image/webp,image/jpeg,image/x-icon,image/vnd.microsoft.icon,.ico"
                                    class="hidden"
                                    @change="pick('favicon', $event)"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">PNG or ICO up to 512 KB. Shown on the browser tab.</p>
                            <InputError :message="form.errors.favicon" />
                        </div>
                    </div>
                </div>

                <!-- Order numbers -->
                <div class="space-y-6">
                    <HeadingSmall title="Order numbers" description="The code every order number starts with." />

                    <div class="grid gap-2">
                        <Label for="order-prefix">Code <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="order-prefix" v-model="form.order_prefix" class="font-mono" maxlength="20" placeholder="S1-R1" />
                        <p class="tabular font-mono text-xs text-muted-foreground">
                            Orders will look like <strong class="text-foreground">{{ prefixPreview }}</strong
                            >. Leave empty for the store-and-register default.
                        </p>
                        <InputError :message="form.errors.order_prefix" />
                    </div>
                </div>

                <!-- Receipt -->
                <div class="space-y-6">
                    <HeadingSmall title="Receipt" description="Printed at the top and bottom of every receipt." />

                    <div class="grid gap-2">
                        <Label for="header">Header</Label>
                        <Input id="header" v-model="form.receipt_header" required />
                        <InputError :message="form.errors.receipt_header" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="footer">Footer</Label>
                        <Textarea id="footer" v-model="form.receipt_footer" rows="2" placeholder="Thank you for shopping with us!" />
                        <InputError :message="form.errors.receipt_footer" />
                    </div>
                </div>

                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                    <!-- Full width on a phone: a thumb-sized target, and the
                         page ends with an obvious action rather than a chip. -->
                    <Button type="submit" class="press w-full sm:w-auto" :disabled="form.processing">Save</Button>
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
