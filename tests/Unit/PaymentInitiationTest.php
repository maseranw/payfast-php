<?php

namespace Ngelekanyo\Payfast\Tests\Unit;

use Ngelekanyo\Payfast\Core\PayfastConfig;
use Ngelekanyo\Payfast\Core\PaymentInitiation;
use PHPUnit\Framework\TestCase;

class PaymentInitiationTest extends TestCase
{
    private function config(bool $sandbox = true): PayfastConfig
    {
        return new PayfastConfig(
            sandbox: $sandbox,
            merchantId: '10000100',
            merchantKey: 'key',
            passphrase: 'passphrase123',
            returnUrl: 'https://example.com/return',
            cancelUrl: 'https://example.com/cancel',
            notifyUrl: 'https://example.com/notify',
        );
    }

    public function test_rejects_missing_required_fields(): void
    {
        $result = PaymentInitiation::build(['item_name' => 'Plan', 'm_payment_id' => 'PAY-1'], $this->config());

        $this->assertSame(['error' => 'Missing required fields'], $result);
    }

    public function test_rejects_non_numeric_amount(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => 'abc', 'item_name' => 'Plan', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertSame(['error' => 'Amount must be a valid positive number'], $result);
    }

    public function test_rejects_negative_amount(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '-9999.99', 'item_name' => 'Plan', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertSame(['error' => 'Amount must be a valid positive number'], $result);
    }

    public function test_rejects_zero_amount(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '0', 'item_name' => 'Plan', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertSame(['error' => 'Amount must be a valid positive number'], $result);
    }

    public function test_builds_a_signed_payment_payload_for_a_valid_request(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '99.5', 'item_name' => 'Plan', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('99.50', $result['paymentData']['amount']);
        $this->assertSame('10000100', $result['paymentData']['merchant_id']);
        $this->assertNotEmpty($result['paymentData']['signature']);
        $this->assertSame(PaymentInitiation::processUrl($this->config()), $result['payfastUrl']);
    }

    public function test_defaults_billing_date_to_today(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '10.00', 'item_name' => 'Plan', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertSame(date('Y-m-d'), $result['paymentData']['billing_date']);
    }

    public function test_process_url_uses_sandbox_when_configured(): void
    {
        $this->assertStringContainsString('sandbox.payfast.co.za', PaymentInitiation::processUrl($this->config(true)));
    }

    public function test_process_url_uses_live_when_not_sandbox(): void
    {
        $this->assertSame('https://www.payfast.co.za/eng/process', PaymentInitiation::processUrl($this->config(false)));
    }
}
