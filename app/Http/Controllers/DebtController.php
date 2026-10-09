<?php

namespace App\Http\Controllers;

use App\Enums\Action;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderSyncService;
use App\Support\AuditLog;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Money owed to the shop.
 *
 * A debt sale records the full total as owed. Settling it writes an ordinary
 * payment row against the same order and bumps paid_amount, so the payment
 * breakdown in Reports picks it up on the day the cash actually arrived —
 * not the day the goods left. Partial payments are fine; a debt is "settled"
 * the moment paid_amount reaches total.
 */
class DebtController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'state' => (string) $request->input('filter.state', 'open'),
        ];

        $debts = QueryBuilder::for($this->scoped($user))
            ->with([
                'customer:id,name,phone',
                'cashier:id,name',
                // What they took and what they have paid so far, so the page
                // can show the whole story of a debt without a round trip.
                'items:id,order_id,product_name,qty,unit_price,subtotal',
                'payments:id,order_id,method,amount,reference_no,created_at',
            ])
            ->withCount('items')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $q, string $search) {
                    $q->where(function (Builder $w) use ($search) {
                        $w->where('order_no', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%"));
                    });
                }),
                // Default to what is still owed; "settled" shows the paid-off history.
                AllowedFilter::callback('state', fn (Builder $q, string $state) => $state === 'open'
                    ? $q->whereColumn('paid_amount', '<', 'total')
                    : $q->whereColumn('paid_amount', '>=', 'total'))->default('open'),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        $open = $this->scoped($user)->whereColumn('paid_amount', '<', 'total');
        $openCount = (clone $open)->count();
        $owed = (float) $open->sum(DB::raw('total - paid_amount'));

        return Inertia::render('Debts/Index', [
            'debts' => $debts,
            'filters' => $filters,
            'summary' => [
                'open_count' => $openCount,
                'owed' => number_format($owed, 2, '.', ''),
            ],
            'methods' => collect(PaymentMethod::cases())
                ->reject(fn (PaymentMethod $m) => $m === PaymentMethod::Credit) // paying a debt on credit is circular
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->values(),
            'currency' => Currency::current()->toArray(),
        ]);
    }

    /** Record money received against a debt. */
    /**
     * The one line a manual debt carries.
     *
     * With a product, the catalogue prices it — the client sends only an id
     * and a quantity, never a price, so a hand-edited request cannot put beer
     * on the book at ៛1. The sync service then moves stock exactly as a till
     * sale would, packs included. Without one, it is the typed amount under
     * the shopkeeper's own words, and no shelf moves.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function debtLine(array $data): array
    {
        if ($data['product_id'] ?? null) {
            $product = Product::query()->active()->findOrFail($data['product_id']);

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'qty' => (int) $data['qty'],
                'unit_price' => $product->sell_price,
                'discount' => 0,
            ];
        }

        return [
            'product_id' => null,
            'product_name' => trim((string) ($data['note'] ?? '')) ?: 'Goods on credit',
            'qty' => 1,
            'unit_price' => $data['amount'],
            'discount' => 0,
        ];
    }

    /**
     * Put more on the book without a trip through the till.
     *
     * A customer who already owes takes more goods — rice, oil, whatever was
     * grabbed — and the shopkeeper types the amount rather than ringing lines.
     * It goes through the same sync service a POS debt sale does, so it gets a
     * real order number, the currency stamp, and a place in that day's sales,
     * and it is settled by the very same Record payment flow.
     *
     * The one line it carries has no product behind it; its name is the note,
     * so the debt detail — and the by-product report, which groups on the
     * snapshot name — say what the money was for in the shopkeeper's words.
     */
    public function store(Request $request, OrderSyncService $sync): RedirectResponse
    {
        // Debts are orders, not their own model, so the action gates sit here
        // rather than in a policy: putting a sale on the book is a create,
        // recording money against one is an update.
        abort_unless($request->user()->mayDo(Permission::Debts, Action::Create), 403);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],

            /*
             * Two ways onto the book: a real product (they took beer — the
             * shelf must move), or a typed amount (a scribbled total — no
             * shelf involved). Exactly one of the two.
             */
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'qty' => ['required_with:product_id', 'integer', 'min:1', 'max:100000'],
            'amount' => ['required_without:product_id', 'nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $sync->syncOne([
                'client_uuid' => (string) Str::uuid(),
                'customer_id' => (int) $data['customer_id'],
                'sale_type' => SaleType::Debt->value,
                'discount_amount' => 0,
                'items' => [$this->debtLine($data)],
                // Nothing has been paid — that is the point of a debt.
                'payments' => [],
            ], $request->user());

            if ($result['status'] !== 'created') {
                return back()->withErrors(['amount' => $result['message'] ?? 'The debt could not be recorded.']);
            }

            $customer = Customer::find($data['customer_id']);
            $owedNow = $this->scoped($request->user())
                ->where('customer_id', $data['customer_id'])
                ->get()
                ->sum(fn (Order $o) => (float) $o->outstanding());

            return back()->with(
                'success',
                "Added to the book — {$customer?->name} now owes ".Currency::current()->format($owedNow).'.',
            );
        } catch (QueryException $e) {
            return $this->failed($e, 'The debt could not be recorded. Nothing was added — try again.');
        }
    }

    /**
     * Products for the add-debt picker, packs included.
     *
     * Packs are product rows of their own, so "Hanuman" and "6 cans" both
     * come back — each carries its own price, and picking the pack later
     * moves the parent's stock in base units, exactly as the till does.
     */
    public function productLookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));

        $rows = Product::query()
            ->active()
            ->with('parent:id,name')
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%")
                ->orWhereHas('parent', fn ($p) => $p->where('name', 'like', "%{$q}%"))))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sell_price' => $p->sell_price,
                'unit' => $p->unit,
                'units_per_pack' => $p->units_per_pack,
                'parent_name' => $p->parent?->name,
            ]);

        return response()->json(['results' => $rows]);
    }

    public function settle(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->mayDo(Permission::Debts, Action::Update), 403);

        try {
            $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

            $owed = (float) $order->total - (float) $order->paid_amount;

            $data = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01', "max:{$owed}"],
                'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Card->value, PaymentMethod::Qr->value])],
                'reference_no' => ['nullable', 'string', 'max:255'],
            ], [
                'amount.max' => 'That is more than is owed.',
            ]);

            DB::transaction(function () use ($order, $data) {
                $order->payments()->create([
                    'method' => $data['method'],
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'reference_no' => $data['reference_no'] ?? null,
                ]);

                // Recompute from the ledger rather than adding, so a double-submit
                // cannot drift paid_amount away from what the payments say.
                $order->update(['paid_amount' => $order->payments()->sum('amount')]);
            });

            $order->refresh();
            $left = (float) $order->outstanding();

            AuditLog::money(
                $left > 0 ? "Debt payment on {$order->order_no}" : "Debt on {$order->order_no} settled",
                $order,
                [
                    'order_no' => $order->order_no,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'method' => $data['method'],
                    'reference_no' => $data['reference_no'] ?? null,
                    'outstanding_after' => number_format($left, 2, '.', ''),
                    'customer' => $order->customer?->name,
                ],
                $left > 0 ? 'debt_payment' : 'debt_settled',
            );

            return back()->with(
                'success',
                $left > 0
                    ? "Payment recorded. {$order->customer?->name} still owes ".Currency::current()->format($left).'.'
                    : "Debt settled — {$order->customer?->name} is paid up.",
            );
        } catch (QueryException $e) {
            return $this->failed($e, 'The payment could not be recorded. The debt is unchanged — try again.');
        }
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Debt->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
