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

    public function test_return_and_cancel_url_can_be_overridden_per_call(): void
    {
        $result = PaymentInitiation::build(
            [
                'amount' => '10.00',
                'item_name' => 'Song',
                'm_payment_id' => 'PAY-1',
                'return_url' => 'https://example.com/return/song-slug',
                'cancel_url' => 'https://example.com/cancel/song-slug',
            ],
            $this->config()
        );

        $this->assertSame('https://example.com/return/song-slug', $result['paymentData']['return_url']);
        $this->assertSame('https://example.com/cancel/song-slug', $result['paymentData']['cancel_url']);
    }

    public function test_return_and_cancel_url_default_to_config(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '10.00', 'item_name' => 'Song', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertSame('https://example.com/return', $result['paymentData']['return_url']);
        $this->assertSame('https://example.com/cancel', $result['paymentData']['cancel_url']);
    }

    public function test_once_off_payments_omit_subscription_fields(): void
    {
        $result = PaymentInitiation::build(
            ['amount' => '10.00', 'item_name' => 'Song', 'm_payment_id' => 'PAY-1'],
            $this->config()
        );

        $this->assertArrayNotHasKey('subscription_type', $result['paymentData']);
        $this->assertArrayNotHasKey('recurring_amount', $result['paymentData']);
        $this->assertArrayNotHasKey('billing_date', $result['paymentData']);
    }

    public function test_once_off_payments_pass_through_custom_fields(): void
    {
        $result = PaymentInitiation::build(
            [
                'amount' => '10.00',
                'item_name' => 'Song',
                'm_payment_id' => 'PAY-1',
                'custom_str1' => 'song-slug',
                'custom_str2' => '42',
            ],
            $this->config()
        );

        $this->assertSame('song-slug', $result['paymentData']['custom_str1']);
        $this->assertSame('42', $result['paymentData']['custom_str2']);
    }

    public function test_subscription_type_opts_into_subscription_fields_with_defaults(): void
    {
        $result = PaymentInitiation::build(
            [
                'amount' => '10.00',
                'item_name' => 'Plan',
                'm_payment_id' => 'PAY-1',
                'subscription_type' => 1,
            ],
            $this->config()
        );

        $this->assertSame(1, $result['paymentData']['subscription_type']);
        $this->assertSame(date('Y-m-d'), $result['paymentData']['billing_date']);
        $this->assertSame('10.00', $result['paymentData']['recurring_amount']);
        $this->assertSame(3, $result['paymentData']['frequency']);
        $this->assertSame(0, $result['paymentData']['cycles']);
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
