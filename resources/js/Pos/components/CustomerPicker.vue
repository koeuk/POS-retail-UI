<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CurrencyDef } from '@/composables/useCurrency';
import { http } from '@/Pos/lib/http';
import { formatMoney } from '@/Pos/lib/money';
import { HandCoins, Plus, Search, UserRound } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
}

/*
 * One form, two moments. Opened from the "In debt" chip it only picks who
 * owes (the bill is still growing, so money talk is premature). Opened from
 * "Record debt" (`withDeposit`) the same dialog carries on to the money:
 * pick the name, then say how much of the bill is paid right now — plenty
 * of customers put SOMETHING down — and the rest is the debt.
 */
const props = defineProps<{
    open: boolean;
    withDeposit?: boolean;
    total?: number;
    currency?: CurrencyDef;
    /** Already attached to the cart — the deposit step starts from them. */
    preselected?: Customer | null;
}>();

const emit = defineEmits<{ close: []; pick: [customer: Customer]; confirm: [customer: Customer, deposit: number] }>();

const query = ref('');
const results = ref<Customer[]>([]);
const loading = ref(false);
const failed = ref(false);
let debounce: ReturnType<typeof setTimeout>;

/*
 * Debt needs a name to collect from, and a shop often has no customer list
 * at all when the first debt happens. So this is search-or-create in one
 * box: type a name, and if nobody matches, the same name becomes a new
 * customer with one tap. Turning a debt away for lack of a record is worse
 * than a slightly untidy customer list.
 */
async function search() {
    loading.value = true;
    failed.value = false;
    try {
        const { data } = await http.get<Customer[]>('/customers', { params: { q: query.value } });
        results.value = data;
    } catch {
        failed.value = true;
        results.value = [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        query.value = '';
        creating.value = false;
        raw.value = '';
        chosen.value = props.withDeposit ? (props.preselected ?? null) : null;
        if (!chosen.value) void search();
    },
);
watch(query, () => {
    clearTimeout(debounce);
    debounce = setTimeout(search, 250);
});

function choose(c: Customer) {
    if (!props.withDeposit) {
        emit('pick', c);
        return;
    }
    chosen.value = c;
}

/** Back from the money step to the list — wrong name happens. */
function changeCustomer() {
    chosen.value = null;
    raw.value = '';
    void search();
}

/* Inline create. */
const creating = ref(false);
const newPhone = ref('');
const createError = ref<string | null>(null);
const saving = ref(false);

async function create() {
    createError.value = null;
    saving.value = true;
    try {
        const { data } = await http.post<Customer>('/customers', { name: query.value.trim(), phone: newPhone.value.trim() || null });
        choose(data);
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        createError.value = msg ?? 'Could not save the customer.';
    } finally {
        saving.value = false;
    }
}

/* The money step. */
const chosen = ref<Customer | null>(null);
const raw = ref('');

const deposit = computed(() => Number(raw.value) || 0);
const owed = computed(() => Math.max(0, (props.total ?? 0) - deposit.value));

/* A deposit that covers the bill is not a debt — that sale is a customer sale. */
const coversEverything = computed(() => (props.total ?? 0) > 0 && deposit.value >= (props.total ?? 0));
const validDeposit = computed(() => deposit.value > 0 && !coversEverything.value);

const money = (v: number) => (props.currency ? formatMoney(v, props.currency) : String(v));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Who owes this?</DialogTitle>
                <DialogDescription>
                    <template v-if="withDeposit && chosen">
                        <strong class="text-foreground">{{ chosen.name }}</strong> owes
                        <strong class="tabular font-mono text-foreground">{{ money(total ?? 0) }}</strong> — take part of it now, or put the whole
                        bill in debt.
                    </template>
                    <template v-else>A debt has to have a name on it, or there is nobody to collect from.</template>
                </DialogDescription>
            </DialogHeader>

            <!-- Step 2: the money, in the same form. -->
            <div v-if="withDeposit && chosen" class="space-y-3 py-2">
                <div class="flex items-center justify-between rounded-lg border border-border px-3 py-2.5">
                    <span class="flex min-w-0 items-center gap-3">
                        <UserRound class="size-4 shrink-0 text-muted-foreground" />
                        <span class="truncate text-sm font-medium">{{ chosen.name }}</span>
                    </span>
                    <button type="button" class="press text-xs font-medium text-primary" @click="changeCustomer">Change</button>
                </div>

                <div class="grid gap-2">
                    <Label for="deposit">Paying now</Label>
                    <Input
                        id="deposit"
                        v-model="raw"
                        type="number"
                        min="0"
                        :step="(currency?.decimals ?? 0) > 0 ? '0.01' : '1'"
                        inputmode="decimal"
                        placeholder="0"
                        class="tabular font-mono text-lg"
                        autofocus
                    />

                    <p v-if="coversEverything" class="text-xs text-destructive">
                        That covers the whole bill — ring it up as a Customer sale instead.
                    </p>
                    <p v-else class="tabular font-mono text-xs text-muted-foreground">
                        Still owed: <strong class="text-foreground">{{ money(owed) }}</strong>
                    </p>
                </div>
            </div>

            <!-- Step 1: the name. -->
            <div v-else class="space-y-3 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="query" placeholder="Name or phone…" class="pl-9" autofocus autocomplete="off" />
                </div>

                <p v-if="failed" class="text-xs text-destructive">Could not load customers — check the connection.</p>

                <ul v-else-if="results.length" class="max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="c in results" :key="c.id">
                        <button
                            type="button"
                            class="press flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent"
                            @click="choose(c)"
                        >
                            <UserRound class="size-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ c.name }}</span>
                                <span v-if="c.phone" class="tabular block font-mono text-xs text-muted-foreground">{{ c.phone }}</span>
                            </span>
                        </button>
                    </li>
                </ul>

                <p v-else-if="!loading" class="px-1 text-sm text-muted-foreground">
                    {{ query.trim() ? `No customer called “${query.trim()}”.` : 'No customers yet.' }}
                </p>

                <!-- Create the typed name as a new customer. -->
                <div v-if="query.trim().length >= 2" class="rounded-lg border border-dashed border-border p-3">
                    <button
                        v-if="!creating"
                        type="button"
                        class="press flex w-full items-center gap-2 text-left text-sm font-medium text-primary"
                        @click="creating = true"
                    >
                        <Plus class="size-4" />
                        Add “{{ query.trim() }}” as a new customer
                    </button>
                    <div v-else class="grid gap-2">
                        <Label for="np" class="text-xs text-muted-foreground">Phone (optional, helps you find them later)</Label>
                        <Input id="np" v-model="newPhone" inputmode="tel" class="font-mono" placeholder="012 345 678" />
                        <InputError :message="createError ?? undefined" />
                        <Button type="button" class="press" :disabled="saving" @click="create">Save &amp; use “{{ query.trim() }}”</Button>
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" class="press" @click="emit('close')">Cancel</Button>
                <template v-if="withDeposit && chosen">
                    <Button type="button" variant="outline" class="press" @click="emit('confirm', chosen, 0)">All in debt</Button>
                    <Button type="button" class="press" :disabled="!validDeposit" @click="emit('confirm', chosen, deposit)">
                        <HandCoins class="size-4" />
                        Take {{ money(deposit) }}
                    </Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
