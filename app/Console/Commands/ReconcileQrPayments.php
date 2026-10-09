<?php

namespace App\Console\Commands;

use App\Payments\QrPayments;
use Illuminate\Console\Command;

/**
 * Re-ask the provider about QR charges still in doubt.
 *
 * The till only polls while its QR is on screen. A customer who pays as the
 * cashier gives up, or a tablet that sleeps mid-poll, leaves a charge that
 * nobody asks about again — this sweep does.
 */
class ReconcileQrPayments extends Command
{
    protected $signature = 'payments:reconcile-qr {--hours=2 : How far back to look}';

    protected $description = 'Check unsettled QR charges with their provider and record late payments';

    public function handle(QrPayments $payments): int
    {
        $paid = $payments->reconcile((int) $this->option('hours'));

        $this->info($paid === 0 ? 'No late QR payments found.' : "{$paid} late QR payment(s) recorded.");

        return self::SUCCESS;
    }
}
