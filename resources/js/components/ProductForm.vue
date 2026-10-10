<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCurrency } from '@/composables/useCurrency';
import { imageSrc } from '@/lib/utils';
import type { Category, Product } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { ImageUp, LoaderCircle, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PackRow {
    /* Inertia's useForm only accepts FormDataConvertible values, and an array
       of plain objects needs this index signature to qualify. */
    [key: string]: string | number | null;
    id: number | null;
    name: string;
    units_per_pack: number | string;
    sell_price: string;
}

const props = withDefaults(
    defineProps<{
        categories: Category[];
        product?: Product;
        /** Larger sizes already saved against this product. */
        packs?: Array<{ id: number; name: string; units_per_pack: number; sell_price: string }>;
        /** Stores that can receive goods, and what is on the shelf now. */
        stores?: Array<{ id: number; name: string }>;
        onHand?: number;
    }>(),
    { packs: () => [], stores: () => [], onHand: 0 },
);

const isEdit = computed(() => !!props.product);

/*
 * Money fields carry the shop's symbol, so it is never a guess whether a box
 * wants dollars or riel — and the step follows the currency, because riel has
 * no fractional unit and nudging one by a hundredth is meaningless.
 */
const { currency } = useCurrency();

/**
 * Decimal columns always arrive as "500.00". In a currency with no fractional
 * unit that is two digits of noise in a box the shopkeeper is about to type
 * into, so the stored string is trimmed to the currency's own precision.
 */
const forEditing = (amount: string | number | null | undefined): string => Number(amount ?? 0).toFixed(currency.value.decimals);

const priceStep = computed(() => (currency.value.decimals > 0 ? '0.01' : '1'));

/** Sentinel for "received as single units", since a Select needs a string. */
const SINGLE = 'single';

/** Fields that only mean anything once the product exists. */
/*
 * Opening stock, entered the way it arrives: two cases of twenty-four and one
 * loose, not forty-nine. The per-case count borrows "Counted in cases of"
 * from Options so it is typed once; either can be overridden here. Only the
 * computed total travels to the server — the wire stays a plain opening_qty.
 */
const openingEach = ref<string | number>('');
const openingLoose = ref<string | number>('');
const openingPerCase = computed(() => Number(openingEach.value) || Number(form.case_size) || 1);
const openingInCases = computed(() => openingPerCase.value > 1);
const openingTotal = computed(() => (Number(form.opening_qty) || 0) * openingPerCase.value + (Number(openingLoose.value) || 0));

const RECEIPT_KEYS = ['add_stock', 'add_stock_pack_id', 'add_stock_units_each', 'add_stock_unit_label', 'add_stock_loose', 'add_stock_note'] as const;

const form = useForm({
    category_id: props.product ? String(props.product.category_id) : '',
    packs: props.packs.map((p): PackRow => ({ id: p.id, name: p.name, units_per_pack: p.units_per_pack, sell_price: forEditing(p.sell_price) })),
    name: props.product?.name ?? '',
    sku: props.product?.sku ?? '',
    barcode: props.product?.barcode ?? '',
    description: props.product?.description ?? '',
    sell_price: props.product ? forEditing(props.product.sell_price) : '',
    unit: props.product?.unit ?? 'pcs',
    case_size: (props.product?.case_size ?? '') as string | number,
    track_stock: props.product?.track_stock ?? true,
    is_active: props.product?.is_active ?? true,
    image: null as File | null,
    image_url: '',
    // Gallery: files picked now, links pasted now, and (on edit) the saved
    // sources being kept — dropping one from the kept list removes it.
    gallery: [] as File[],
    gallery_urls: [] as string[],
    gallery_existing: (props.product?.gallery ?? []) as string[],
    opening_qty: 0,
    low_stock_threshold: 10,

    /*
     * Goods received. Left blank normally — it is an action, not a setting, so
     * it clears itself after saving rather than sitting there ready to double
     * the stock on the next unrelated edit.
     */
    add_stock: '' as string | number,
    add_stock_pack_id: SINGLE as string,
    add_stock_units_each: '' as string | number,
    add_stock_unit_label: '',
    add_stock_loose: '' as string | number,
    add_stock_store_id: '' as string,
    add_stock_note: '',
});

/*
 * Larger sizes of this same product. Stock is always counted in the base unit,
 * so a pack needs only a name, how many it holds, and a price — no stock of
 * its own, and no SKU to invent: the server derives one.
 */
function addPack() {
    form.packs.push({ id: null, name: '', units_per_pack: 6, sell_price: forEditing(form.sell_price) });
}

function removePack(index: number) {
    form.packs.splice(index, 1);
}

/** What one unit inside the pack works out at — the number that decides whether the bulk price makes sense. */
function perUnit(pack: PackRow): number | null {
    const units = Number(pack.units_per_pack) || 0;
    const price = Number(pack.sell_price) || 0;
    if (units <= 0 || price <= 0) return null;

    return price / units;
}

const packError = (index: number, field: string) => (form.errors as Record<string, string | undefined>)[`packs.${index}.${field}`];

const preview = ref<string | null>(imageSrc(props.product?.image) ?? null);

function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : preview.value;
}

/** A pasted link previews immediately; an actual upload still wins on save. */
watch(
    () => form.image_url,
    (url) => {
        if (!form.image && url && /^https?:\/\//.test(url)) preview.value = url;
    },
);

/* Gallery. Pending uploads preview through object URLs; pasted links and
   already-saved sources render directly. */
const galleryPending = ref<string[]>([]);
const galleryUrlDraft = ref('');

function onGalleryFiles(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    for (const file of files) {
        form.gallery.push(file);
        galleryPending.value.push(URL.createObjectURL(file));
    }
    (event.target as HTMLInputElement).value = '';
}

function addGalleryUrl() {
    const url = galleryUrlDraft.value.trim();
    if (!url || !/^https?:\/\//.test(url)) return;
    form.gallery_urls.push(url);
    galleryUrlDraft.value = '';
}

function removeExisting(index: number) {
    form.gallery_existing.splice(index, 1);
}

function removePendingFile(index: number) {
    form.gallery.splice(index, 1);
    galleryPending.value.splice(index, 1);
}

function removePendingUrl(index: number) {
    form.gallery_urls.splice(index, 1);
}

/**
 * Saved packs only. A row still being typed has no id yet, so it cannot be
 * received against — it has to be saved before it can be counted in.
 */
const receivableUnits = computed(() => props.packs.filter((p) => !!p.id));

/**
 * Base units in one of the things being received.
 *
 * A saved pack is authoritative when one is chosen; otherwise the optional
 * pair below says how the delivery was boxed. Not every product comes 24 to a
 * case, so both are left blank by default and a bare quantity means singles.
 */
const unitsPerSelected = computed(() => {
    if (form.add_stock_pack_id !== SINGLE) {
        return receivableUnits.value.find((p) => String(p.id) === form.add_stock_pack_id)?.units_per_pack ?? 1;
    }

    return Number(form.add_stock_units_each) || 1;
});

/** True while the delivery is counted in something bigger than a single. */
const receivingInPacks = computed(() => unitsPerSelected.value > 1);

/** Base units this delivery adds: the packs, plus anything loose. */
const receivingTotal = computed(() => (Number(form.add_stock) || 0) * unitsPerSelected.value + (Number(form.add_stock_loose) || 0));

/** What the shelf will read once this save goes through. */
const stockAfter = computed(() => props.onHand + receivingTotal.value);

/**
 * The shelf figure said the way a shopkeeper counts it: "4 cases + 4" rather
 * than "100". Uses the largest pack, since that is what is stacked; anything
 * that does not fill one is the remainder.
 */
const onHandBreakdown = computed(() => {
    const largest = receivableUnits.value.reduce<null | { name: string; units_per_pack: number }>(
        (top, pack) => (!top || pack.units_per_pack > top.units_per_pack ? pack : top),
        null,
    );

    if (!largest || largest.units_per_pack < 2 || props.onHand < largest.units_per_pack) return null;

    const whole = Math.floor(props.onHand / largest.units_per_pack);
    const rest = props.onHand % largest.units_per_pack;

    return `${whole} × ${largest.name}${rest ? ` + ${rest} ${props.product?.unit ?? ''}`.trimEnd() : ''}`;
});

function submit() {
    if (isEdit.value) {
        // Multipart cannot be sent as a real PUT, so spoof the method.
        form.transform((data) => ({
            ...data,
            _method: 'put',
            // SINGLE is a sentinel the Select needs; the server wants a real
            // pack id or nothing at all.
            add_stock_pack_id: data.add_stock_pack_id === SINGLE ? null : data.add_stock_pack_id,
        })).post(route('products.update', { product: props.product!.uuid }), {
            forceFormData: true,
            /*
             * Receiving is an action, not a setting. The update redirects away
             * so this rarely matters — but if it ever stops redirecting, a
             * second save would book the same delivery twice.
             */
            onSuccess: () => form.reset('add_stock', 'add_stock_loose', 'add_stock_note'),
        });

        return;
    }

    /*
     * Creating has no Add stock section — a product that does not exist yet
     * cannot have received a delivery — so those keys are dropped rather than
     * sent along. Leaving them in meant posting the SINGLE sentinel as a pack
     * id, which failed validation on a field the create form never shows: the
     * form simply refused to save, with the error nowhere on screen.
     */
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };

        for (const key of RECEIPT_KEYS) delete payload[key];

        // The server hears one number; the cases arithmetic lives up here.
        payload.opening_qty = openingTotal.value;

        return payload;
    }).post(route('products.store'), { forceFormData: true });
}
</script>

<template>
    <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit">
        <!-- Main -->
        <div class="stagger space-y-5 lg:col-span-2">
            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Details</h2>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required autofocus />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="category">Category</Label>
                        <Select v-model="form.category_id">
                            <SelectTrigger id="category">
                                <SelectValue placeholder="Choose a category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="c in categories" :key="c.id" :value="String(c.id)">
                                    {{ c.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.category_id" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sku">SKU</Label>
                            <Input id="sku" v-model="form.sku" class="font-mono" required />
                            <InputError :message="form.errors.sku" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="barcode">Barcode</Label>
                            <Input id="barcode" v-model="form.barcode" class="font-mono" placeholder="Optional" />
                            <InputError :message="form.errors.barcode" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <Textarea id="description" v-model="form.description" rows="3" placeholder="Optional" />
                        <InputError :message="form.errors.description" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Also sold in packs</h2>
                    <span class="text-xs text-muted-foreground">Optional</span>
                </div>
                <p class="mb-4 text-xs text-muted-foreground">
                    Selling the same thing by the twelve, the six or the single? Add a row for each. Stock stays counted in
                    {{ form.unit || 'single units' }} — selling one pack takes its whole contents off the same shelf.
                </p>

                <div v-if="form.packs.length" class="mb-3 space-y-3">
                    <div v-for="(pack, index) in form.packs" :key="index" class="rounded-lg border border-border p-3">
                        <div class="grid gap-3 sm:grid-cols-[1fr_6rem_7rem_auto] sm:items-start">
                            <div class="grid gap-1.5">
                                <Label :for="`pack-name-${index}`" class="text-xs text-muted-foreground">Name</Label>
                                <Input :id="`pack-name-${index}`" v-model="pack.name" placeholder="Half case" />
                                <InputError :message="packError(index, 'name')" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label :for="`pack-units-${index}`" class="text-xs text-muted-foreground">Holds</Label>
                                <Input :id="`pack-units-${index}`" v-model="pack.units_per_pack" type="number" min="1" class="tabular font-mono" />
                                <InputError :message="packError(index, 'units_per_pack')" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label :for="`pack-price-${index}`" class="text-xs text-muted-foreground">Price</Label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                                        {{ currency.symbol }}
                                    </span>
                                    <Input
                                        :id="`pack-price-${index}`"
                                        v-model="pack.sell_price"
                                        type="number"
                                        :step="priceStep"
                                        min="0"
                                        class="tabular pl-6 font-mono"
                                    />
                                </div>
                                <InputError :message="packError(index, 'sell_price')" />
                            </div>

                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="press touch-target mt-0 self-start text-muted-foreground hover:text-destructive sm:mt-[1.6rem]"
                                :aria-label="`Remove ${pack.name || 'pack'}`"
                                @click="removePack(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>

                        <!-- The per-unit figure is what tells you whether the bulk
                             price is actually a discount. -->
                        <!--
                            Deliberately finer than the currency's own decimals:
                            this figure exists to compare against the single
                            price, and rounding ៛83.3 to ៛83 hides the very
                            difference it is there to show.
                        -->
                        <p v-if="perUnit(pack) !== null" class="mt-2 text-xs text-muted-foreground">
                            <span class="tabular font-mono"> {{ currency.symbol }}{{ perUnit(pack)!.toFixed(currency.decimals > 0 ? 3 : 1) }} </span>
                            each
                        </p>
                    </div>
                </div>

                <Button type="button" variant="outline" class="press w-full sm:w-auto" @click="addPack">
                    <Plus class="size-4" />
                    Add a pack size
                </Button>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Pricing</h2>

                <div class="grid gap-2 sm:max-w-xs">
                    <Label for="sell">Sell price</Label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
                            {{ currency.symbol }}
                        </span>
                        <Input id="sell" v-model="form.sell_price" type="number" :step="priceStep" min="0" class="tabular pl-7 font-mono" />
                    </div>
                    <InputError :message="form.errors.sell_price" />
                </div>

                <p class="mt-3 text-xs text-muted-foreground">This is the price the customer pays.</p>
            </section>

            <!--
                Receiving goods, without a trip to Inventory.

                It is written as a Restock movement, not as a new figure typed
                over the old one: the quantity on the shelf has to stay
                explainable, and "someone edited it" is not an explanation. The
                field is blank by default because it is an action — leaving it
                filled would add the same delivery again on the next save.
            -->
            <section v-if="isEdit" class="rounded-xl border border-border bg-card p-4 md:p-5">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Add stock</h2>
                    <span class="tabular text-right font-mono text-xs text-muted-foreground">
                        {{ onHand }} {{ form.unit }} on hand
                        <span v-if="onHandBreakdown" class="block text-[0.65rem] text-muted-foreground/70">{{ onHandBreakdown }}</span>
                    </span>
                </div>
                <p class="mb-4 text-xs text-muted-foreground">
                    Goods arrived from a supplier. Recorded as a restock movement, so Inventory can still say where the figure came from.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="add-stock">Quantity received</Label>
                        <div class="flex gap-2">
                            <Input id="add-stock" v-model="form.add_stock" type="number" min="0" placeholder="0" class="tabular w-24 font-mono" />
                            <!-- Counted the way the delivery arrived: three cases,
                                 not seventy-two packets. -->
                            <Select v-model="form.add_stock_pack_id">
                                <SelectTrigger class="flex-1"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="SINGLE">{{ form.unit || 'single' }}</SelectItem>
                                    <SelectItem v-for="pack in receivableUnits" :key="pack.id" :value="String(pack.id)">
                                        {{ pack.name }} (×{{ pack.units_per_pack }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <InputError :message="form.errors.add_stock" />
                    </div>

                    <!--
                        How the delivery was boxed. Optional and blank by
                        default, because not every product comes 24 to a case —
                        a bare quantity means singles. It is used for this
                        delivery only and never becomes a pack to sell.
                    -->
                    <div v-if="form.add_stock_pack_id === SINGLE" class="grid gap-2">
                        <Label for="add-stock-units-each">Each contains <span class="text-muted-foreground">(optional)</span></Label>
                        <div class="flex gap-2">
                            <Input
                                id="add-stock-units-each"
                                v-model="form.add_stock_units_each"
                                type="number"
                                min="1"
                                placeholder="24"
                                class="tabular w-24 font-mono"
                                aria-label="Units in each container"
                            />
                            <Input
                                v-model="form.add_stock_unit_label"
                                :placeholder="form.unit || 'cans'"
                                class="flex-1"
                                aria-label="What the container is called"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">Leave blank if they arrived loose.</p>
                        <InputError :message="form.errors.add_stock_units_each" />
                    </div>

                    <!-- The remainder that did not come in a full container. -->
                    <div v-if="receivingInPacks" class="grid gap-2">
                        <Label for="add-stock-loose">Plus loose {{ form.unit }}</Label>
                        <Input id="add-stock-loose" v-model="form.add_stock_loose" type="number" min="0" placeholder="0" class="tabular font-mono" />
                        <InputError :message="form.errors.add_stock_loose" />
                    </div>

                    <p v-if="receivingTotal > 0" class="text-xs text-muted-foreground sm:col-span-2">
                        Adds <span class="tabular font-mono text-foreground">{{ receivingTotal }}</span> {{ form.unit }} — will be
                        <span class="tabular font-mono text-foreground">{{ stockAfter }}</span> {{ form.unit }} on hand.
                    </p>

                    <!-- Only worth asking when there is more than one answer. -->
                    <div v-if="stores.length > 1" class="grid gap-2">
                        <Label for="add-stock-store">Into which store</Label>
                        <Select v-model="form.add_stock_store_id">
                            <SelectTrigger id="add-stock-store">
                                <SelectValue :placeholder="stores[0]?.name" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.add_stock_store_id" />
                    </div>

                    <div class="grid gap-2" :class="stores.length > 1 ? 'sm:col-span-2' : ''">
                        <Label for="add-stock-note">Note</Label>
                        <Input id="add-stock-note" v-model="form.add_stock_note" placeholder="Optional — supplier, invoice number" />
                        <InputError :message="form.errors.add_stock_note" />
                    </div>
                </div>
            </section>

            <section v-if="!isEdit" class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Opening stock</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="qty">{{ openingInCases ? 'Number of cases, per store' : 'Quantity per store' }}</Label>
                        <Input id="qty" v-model="form.opening_qty" type="number" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.opening_qty" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="opening-each">
                            {{ form.unit || 'pcs' }} in each case
                            <span class="text-muted-foreground">(optional)</span>
                        </Label>
                        <Input
                            id="opening-each"
                            v-model="openingEach"
                            type="number"
                            min="1"
                            inputmode="numeric"
                            :placeholder="String(form.case_size || 12)"
                            class="tabular font-mono"
                        />
                    </div>
                    <div v-if="openingInCases" class="grid gap-2">
                        <Label for="opening-loose">Plus loose {{ form.unit || 'pcs' }}</Label>
                        <Input id="opening-loose" v-model="openingLoose" type="number" min="0" class="tabular font-mono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="threshold">Low-stock alert at</Label>
                        <Input id="threshold" v-model="form.low_stock_threshold" type="number" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.low_stock_threshold" />
                    </div>
                    <p v-if="openingInCases" class="tabular font-mono text-xs text-muted-foreground sm:col-span-2">
                        Each store starts with {{ openingTotal }} {{ form.unit || 'pcs' }}
                    </p>
                </div>
            </section>
        </div>

        <!-- Aside -->
        <div class="stagger space-y-5">
            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Image</h2>

                <label
                    class="lift flex h-36 w-full cursor-pointer items-center justify-center overflow-hidden rounded-lg border border-dashed border-border bg-muted/40 lg:aspect-square lg:h-auto"
                >
                    <img v-if="preview" :src="preview" alt="" class="size-full object-cover" />
                    <div v-else class="flex flex-col items-center gap-2 text-muted-foreground">
                        <ImageUp class="size-6" />
                        <span class="text-xs">Click to upload</span>
                    </div>
                    <input type="file" accept="image/*" class="sr-only" @change="onFile" />
                </label>
                <InputError class="mt-2" :message="form.errors.image" />

                <!-- Or reuse a supplier's shot: paste its address instead of uploading. -->
                <div class="mt-3 grid gap-2">
                    <Label for="image-url" class="text-xs text-muted-foreground">Or paste an image link</Label>
                    <Input id="image-url" v-model="form.image_url" type="url" placeholder="https://…" autocomplete="off" />
                    <InputError :message="form.errors.image_url" />
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Gallery</h2>

                <div class="grid grid-cols-3 gap-2">
                    <!-- Saved photos being kept -->
                    <div v-for="(src, i) in form.gallery_existing" :key="`e${i}`" class="group relative aspect-square overflow-hidden rounded-lg border border-border">
                        <img :src="imageSrc(src)" alt="" class="size-full object-cover" />
                        <button
                            type="button"
                            class="absolute right-1 top-1 rounded-full bg-background/80 p-1 text-muted-foreground hover:text-destructive"
                            aria-label="Remove photo"
                            @click="removeExisting(i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>

                    <!-- Uploads picked this session -->
                    <div v-for="(src, i) in galleryPending" :key="`p${i}`" class="group relative aspect-square overflow-hidden rounded-lg border border-border">
                        <img :src="src" alt="" class="size-full object-cover" />
                        <button
                            type="button"
                            class="absolute right-1 top-1 rounded-full bg-background/80 p-1 text-muted-foreground hover:text-destructive"
                            aria-label="Remove photo"
                            @click="removePendingFile(i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>

                    <!-- Links pasted this session -->
                    <div v-for="(url, i) in form.gallery_urls" :key="`u${i}`" class="group relative aspect-square overflow-hidden rounded-lg border border-border">
                        <img :src="url" alt="" class="size-full object-cover" />
                        <button
                            type="button"
                            class="absolute right-1 top-1 rounded-full bg-background/80 p-1 text-muted-foreground hover:text-destructive"
                            aria-label="Remove photo"
                            @click="removePendingUrl(i)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </div>

                    <!-- Add tile -->
                    <label
                        class="lift flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border bg-muted/40 text-muted-foreground"
                    >
                        <ImageUp class="size-5" />
                        <span class="text-[0.65rem]">Add photos</span>
                        <input type="file" accept="image/*" multiple class="sr-only" @change="onGalleryFiles" />
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.gallery" />

                <div class="mt-3 flex gap-2">
                    <Input
                        v-model="galleryUrlDraft"
                        type="url"
                        placeholder="https://… photo link"
                        autocomplete="off"
                        class="flex-1"
                        @keydown.enter.prevent="addGalleryUrl"
                    />
                    <Button type="button" variant="outline" class="press shrink-0" @click="addGalleryUrl">Add</Button>
                </div>
                <InputError class="mt-2" :message="form.errors.gallery_urls" />
            </section>

            <section class="rounded-xl border border-border bg-card p-4 md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Options</h2>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="unit">Unit</Label>
                        <Input id="unit" v-model="form.unit" placeholder="pcs" />
                        <InputError :message="form.errors.unit" />
                    </div>

                    <!--
                        Counting only. A pack size below is something the shop
                        sells; a case is how the goods arrive and how the shelf
                        is counted, and it needs no price.
                    -->
                    <div class="grid gap-2">
                        <Label for="case-size"> Counted in cases of <span class="text-muted-foreground">(optional)</span> </Label>
                        <Input
                            id="case-size"
                            v-model="form.case_size"
                            type="number"
                            min="2"
                            inputmode="numeric"
                            placeholder="80"
                            class="tabular font-mono"
                        />
                        <p class="text-xs text-muted-foreground">
                            Inventory then reads "18 cases + 22 {{ form.unit || 'pcs' }}" instead of 1,462. Nothing is sold as a case.
                        </p>
                        <InputError :message="form.errors.case_size" />
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <div>
                            <p class="text-sm font-medium">Track stock</p>
                            <p class="text-xs text-muted-foreground">Decrement on every sale</p>
                        </div>
                        <Switch v-model="form.track_stock" />
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <div>
                            <p class="text-sm font-medium">Active</p>
                            <p class="text-xs text-muted-foreground">Show on the POS grid</p>
                        </div>
                        <Switch v-model="form.is_active" />
                    </div>
                </div>
            </section>

            <!-- Desktop: the action pair sits at the foot of the aside column. -->
            <div class="hidden items-center gap-2 md:flex">
                <Button type="submit" class="press flex-1" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ isEdit ? 'Save changes' : 'Create product' }}
                </Button>
                <Button as-child type="button" variant="ghost" class="press">
                    <Link :href="route('products.index')">Cancel</Link>
                </Button>
            </div>
        </div>

        <!--
            Phone: a docked action bar. This form is taller than the screen, so
            a submit button at the end of the document means scrolling past the
            image picker and both switches to commit an edit made at the top.
            It rests just above the tab bar.

            Sticky, not fixed: AppLayout's entrance animation uses fill-mode
            `both`, so its wrapper keeps a transform after the animation ends —
            and a transformed ancestor becomes the containing block for any
            fixed child, which would anchor this bar to the foot of the page
            instead of the screen.
        -->
        <div
            class="sticky z-30 -mx-5 border-t border-border bg-background/95 px-5 py-3 backdrop-blur-md md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom))"
        >
            <div class="flex items-center gap-2">
                <Button as-child type="button" variant="outline" class="press">
                    <Link :href="route('products.index')">Cancel</Link>
                </Button>
                <Button type="submit" class="press flex-1" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ isEdit ? 'Save changes' : 'Create product' }}
                </Button>
            </div>
        </div>
    </form>
</template>
