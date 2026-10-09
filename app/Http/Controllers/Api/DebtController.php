<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The receivable book over JSON — same rules as the web screen: a debt is a
 * completed sale whose payments have not yet caught up with its total, and
 * settling one writes an ordinary payment row so the ledger stays the truth.
 */
class DebtController extends Controller
{
    /**
     * List debts
     *
     * Each row carries the whole story: customer, items, payments (any
     * deposit included).
     *
     * @group Debts
     *
     * @queryParam filter[state] string `open` (default — still owed) or `settled`. Example: open
     * @queryParam filter[search] string Order no., customer name or phone. Example: GoJo
     */
    public function index(Request $request): JsonResponse
    {
        $debts = QueryBuilder::for($this->scoped($request->user()))
            ->with(['customer:id,name,phone', 'items:id,order_id,product_name,qty,unit_price,subtotal', 'payments:id,order_id,method,amount,created_at'])
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $q, string $search) {
                    $q->where(fn (Builder $w) => $w
                        ->where('order_no', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")));
                }),
                AllowedFilter::callback('state', fn (Builder $q, string $state) => $state === 'open'
                    ? $q->whereColumn('paid_amount', '<', 'total')
                    : $q->whereColumn('paid_amount', '>=', 'total'))->default('open'),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($debts);
    }

    /**
     * Settle a debt
     *
     * Records money received; `paid_amount` is recomputed from the payments
     * ledger, so a double-submit cannot drift it. Paying more than is owed
     * is refused.
     *
     * @group Debts
     *
     * @response {"order_no": "S1-R1-260901-0001", "paid_amount": "15000.00", "outstanding": "0.00", "settled": true}
     */
    public function settle(Request $request, Order $order): JsonResponse
    {
        $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

        $owed = (float) $order->total - (float) $order->paid_amount;

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', "max:{$owed}"],
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Card->value, PaymentMethod::Qr->value])],
            'reference_no' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.max' => 'That is more than is owed.',
        ]);

        try {
            DB::transaction(function () use ($order, $data) {
                $order->payments()->create([
                    'method' => $data['method'],
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'reference_no' => $data['reference_no'] ?? null,
                ]);

                // Recompute from the ledger rather than adding, so a retry
                // cannot drift paid_amount away from what the payments say.
                $order->update(['paid_amount' => $order->payments()->sum('amount')]);
            });
        } catch (QueryException $e) {
            report($e);

            return response()->json(['message' => 'The payment could not be recorded — try again.'], 503);
        }

        $order->refresh();

        return response()->json([
            'order_no' => $order->order_no,
            'paid_amount' => $order->paid_amount,
            'outstanding' => $order->outstanding(),
            'settled' => (float) $order->outstanding() <= 0,
        ]);
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Debt->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->where('store_id', $user->store_id));
    }
}
