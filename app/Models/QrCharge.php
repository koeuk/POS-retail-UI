<?php

namespace App\Models;

use App\Enums\QrChargeStatus;
use App\Models\Concerns\HasUuid;
use App\Payments\StatusResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCharge extends Model
{
    use HasUuid;

    protected $fillable = [
        'provider', 'reference', 'qr', 'amount', 'currency', 'status',
        'store_id', 'register_id', 'cashier_id', 'order_id', 'confirmed_by',
        'provider_ref', 'payer', 'meta', 'expires_at', 'paid_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QrChargeStatus::class,
            'amount' => 'decimal:2',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Fold a provider's answer into the row. Returns true when this call is
     * the one that moved the charge to paid, so the caller audits it once.
     */
    public function applyStatus(StatusResult $result): bool
    {
        $wasSettled = $this->status->isSettled();

        $this->checked_at = now();

        if ($result->status === QrChargeStatus::Paid && ! $wasSettled) {
            $this->fill([
                'status' => QrChargeStatus::Paid,
                'paid_at' => now(),
                'provider_ref' => $result->providerRef,
                'payer' => $result->payer,
            ]);
        } elseif ($result->status === QrChargeStatus::Failed && ! $wasSettled) {
            $this->status = QrChargeStatus::Failed;
            $this->meta = array_merge($this->meta ?? [], ['failure' => $result->message]);
        } elseif ($this->status === QrChargeStatus::Pending && $this->isExpired()) {
            $this->status = QrChargeStatus::Expired;
        }

        $this->save();

        return ! $wasSettled && $this->status === QrChargeStatus::Paid;
    }
}
