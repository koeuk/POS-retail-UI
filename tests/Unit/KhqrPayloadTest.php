<?php

namespace Tests\Unit;

use App\Payments\Khqr\KhqrPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KhqrPayloadTest extends TestCase
{
    public function test_crc_matches_the_ccitt_false_check_value(): void
    {
        // The published check value for CRC-16/CCITT-FALSE over "123456789".
        $this->assertSame('29B1', KhqrPayload::crc16('123456789'));
    }

    public function test_static_qr_has_no_amount_and_no_expiry(): void
    {
        $qr = (new KhqrPayload('jonhsmith@nbcq', 'Jonh Smith', 'PHNOM PENH', 'KHR'))->toString();

        $this->assertSame(
            '00020101021129180014jonhsmith@nbcq5204599953031165802KH5910Jonh Smith6010PHNOM PENH6304',
            substr($qr, 0, -4),
        );
        $this->assertSame(KhqrPayload::crc16(substr($qr, 0, -4)), substr($qr, -4));
    }

    public function test_dynamic_qr_carries_amount_bill_and_expiry(): void
    {
        $qr = (new KhqrPayload(
            accountId: 'shop@aclb',
            merchantName: 'Corner Mart',
            merchantCity: 'Siem Reap',
            currency: 'KHR',
            amount: '16400.00',
            storeLabel: 'Main',
            terminalLabel: 'Till 1',
            expiresAtMs: 1790000000000,
            createdAtMs: 1789999820000,
        ))->toString();

        $this->assertStringContainsString('010212', $qr);             // dynamic
        $this->assertStringContainsString('540516400', $qr);          // riel, whole
        $this->assertStringContainsString('5303116', $qr);            // KHR
        $this->assertStringContainsString('0304Main0706Till 1', $qr); // store + terminal
        $this->assertStringContainsString('01131790000000000', $qr); // expiry
    }

    public function test_dollar_amounts_keep_cents_without_trailing_zeros(): void
    {
        $qr = (new KhqrPayload('shop@aclb', 'Shop', 'PP', 'USD', amount: 4.1, expiresAtMs: 1))->toString();

        $this->assertStringContainsString('54034.1', $qr);
        $this->assertStringContainsString('5303840', $qr);
    }

    public function test_merchant_accounts_use_tag_30(): void
    {
        $qr = (new KhqrPayload('shop@aclb', 'Shop', 'PP', 'KHR', merchantId: 'M123', acquiringBank: 'ACLEDA'))->toString();

        $this->assertStringContainsString('3031'.'0009shop@aclb'.'0104M123'.'0206ACLEDA', $qr);
    }

    public function test_rejects_unsupported_currency_and_bad_account(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KhqrPayload('not-an-account', 'Shop', 'PP', 'KHR');
    }
}
