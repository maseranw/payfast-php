<?php

namespace Ngelekanyo\Payfast\Tests\Unit;

use Ngelekanyo\Payfast\Core\Itn;
use PHPUnit\Framework\TestCase;

class ItnTest extends TestCase
{
    public function test_create_payload_maps_raw_fields(): void
    {
        $body = [
            'm_payment_id' => 'PAY-1',
            'pf_payment_id' => '9999',
            'payment_status' => 'COMPLETE',
            'merchant_id' => '10000100',
            'token' => 'abc123',
            'signature' => 'deadbeef',
        ];

        $payload = Itn::createPayload($body);

        $this->assertSame('PAY-1', $payload['m_payment_id']);
        $this->assertSame('9999', $payload['pf_payment_id']);
        $this->assertSame('COMPLETE', $payload['payment_status']);
        $this->assertSame('', $payload['item_name']);
        $this->assertSame('', $payload['custom_str5']);
    }

    public function test_is_payfast_source_ip_true_when_hostname_ends_with_payfast(): void
    {
        $resolver = fn (string $ip) => 'mta1.payfast.co.za';

        $this->assertTrue(Itn::isPayfastSourceIp('196.1.2.3', $resolver));
    }

    public function test_is_payfast_source_ip_false_for_other_hostnames(): void
    {
        $resolver = fn (string $ip) => 'mail.example.com';

        $this->assertFalse(Itn::isPayfastSourceIp('1.2.3.4', $resolver));
    }

    public function test_is_payfast_source_ip_false_when_lookup_fails(): void
    {
        $resolver = fn (string $ip) => false;

        $this->assertFalse(Itn::isPayfastSourceIp('1.2.3.4', $resolver));
    }

    private function buildValidBody(array $overrides = []): array
    {
        $fields = array_merge([
            'm_payment_id' => 'PAY-1',
            'pf_payment_id' => '9999',
            'payment_status' => 'COMPLETE',
            'item_name' => 'Subscription',
            'merchant_id' => '10000100',
            'token' => 'abc123',
        ], $overrides);

        $paramString = '';
        foreach ($fields as $key => $value) {
            $paramString .= "{$key}=" . urlencode($value) . '&';
        }
        $paramString = rtrim($paramString, '&');
        $paramString .= '&passphrase=' . urlencode('passphrase123');

        $fields['signature'] = md5($paramString);

        return $fields;
    }

    public function test_verify_incoming_accepts_a_correctly_signed_payload(): void
    {
        $httpPost = fn (string $url, string $body) => 'VALID';

        $result = Itn::verifyIncoming(
            $this->buildValidBody(),
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: $httpPost
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('PAY-1', $result['payload']['m_payment_id']);
    }

    public function test_verify_incoming_rejects_a_tampered_signature_payfast_also_rejects(): void
    {
        // A tampered payload both fails our local recomputation AND has no
        // matching record at PayFast, so validateWithPayfast (mocked here
        // to avoid a real network call) also disagrees - giving the more
        // specific "Invalid signature" reason rather than the generic
        // "Validation with PayFast failed".
        $httpPost = fn (string $url, string $body) => 'INVALID';
        $body = $this->buildValidBody();
        $body['amount_gross'] = '999.00';

        $result = Itn::verifyIncoming(
            $body,
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: $httpPost
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid signature', $result['reason']);
    }

    public function test_verify_incoming_trusts_payfasts_own_validation_over_a_local_signature_mismatch(): void
    {
        // Regression test: a real ITN was observed where PayFast's own
        // /query/validate endpoint confirmed a payload as genuine even
        // though this package's local md5 reproduction of PayFast's
        // signature algorithm disagreed (an unreproduced encoding edge
        // case). Rejecting that payload would silently drop a real
        // completed payment, so PayFast's own confirmation must win.
        $httpPost = fn (string $url, string $body) => 'VALID';
        $body = $this->buildValidBody();
        $body['amount_gross'] = '999.00'; // makes the local signature disagree

        $result = Itn::verifyIncoming(
            $body,
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: $httpPost
        );

        $this->assertTrue($result['valid']);
    }

    public function test_verify_incoming_rejects_merchant_id_mismatch(): void
    {
        $body = $this->buildValidBody(['merchant_id' => '99999999']);

        $result = Itn::verifyIncoming(
            $body,
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('Merchant ID mismatch', $result['reason']);
    }

    public function test_verify_incoming_rejects_non_string_field_without_throwing(): void
    {
        $body = $this->buildValidBody();
        $body['amount_gross'] = ['1', '2'];

        $result = Itn::verifyIncoming(
            $body,
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4'
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('amount_gross', $result['reason']);
    }

    public function test_verify_incoming_treats_a_null_optional_field_as_absent(): void
    {
        // Regression test: Laravel's Request::all() represents a field
        // PayFast didn't send as null rather than omitting the array key
        // entirely (e.g. an unset item_description). buildParamString used
        // to reject any non-string value outright, so every real ITN with
        // an unset optional field failed with "expected a string" even
        // though the field was correctly excluded from the signed payload.
        $httpPost = fn (string $url, string $body) => 'VALID';
        $body = $this->buildValidBody();
        $body['item_description'] = null;
        $body['custom_int1'] = null;

        $result = Itn::verifyIncoming(
            $body,
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: $httpPost
        );

        $this->assertTrue($result['valid']);
    }

    public function test_verify_incoming_skips_source_ip_check_in_sandbox(): void
    {
        $reverseDnsCalled = false;
        $reverseDns = function () use (&$reverseDnsCalled) {
            $reverseDnsCalled = true;

            return 'evil.example.com';
        };

        $result = Itn::verifyIncoming(
            $this->buildValidBody(),
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: fn () => 'VALID',
            reverseDns: $reverseDns
        );

        $this->assertTrue($result['valid']);
        $this->assertFalse($reverseDnsCalled);
    }

    public function test_verify_incoming_enforces_source_ip_check_outside_sandbox(): void
    {
        $result = Itn::verifyIncoming(
            $this->buildValidBody(),
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: false,
            sourceIp: '1.2.3.4',
            httpPost: fn () => 'VALID',
            reverseDns: fn () => 'evil.example.com'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('Invalid source IP', $result['reason']);
    }

    public function test_verify_incoming_rejects_when_payfast_validation_disagrees(): void
    {
        $result = Itn::verifyIncoming(
            $this->buildValidBody(),
            merchantId: '10000100',
            passphrase: 'passphrase123',
            sandbox: true,
            sourceIp: '1.2.3.4',
            httpPost: fn () => 'INVALID'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('Validation with PayFast failed', $result['reason']);
    }
}
