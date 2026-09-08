<?php

namespace Ngelekanyo\Payfast\Tests\Unit;

use Ngelekanyo\Payfast\Core\Signature;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    public function test_generate_for_initiate_matches_hand_computed_md5(): void
    {
        $data = [
            'merchant_id' => '10000100',
            'merchant_key' => '46f0cd694581a',
            'return_url' => 'https://example.com/return',
            'cancel_url' => 'https://example.com/cancel',
            'notify_url' => 'https://example.com/notify',
            'm_payment_id' => 'PAY-1',
            'amount' => '99.00',
            'item_name' => 'Pro Plan',
        ];

        $signature = Signature::generateForInitiate($data, 'jt7NOE43FZPn');

        $expectedString = 'merchant_id=10000100&merchant_key=46f0cd694581a'
            . '&return_url=' . urlencode('https://example.com/return')
            . '&cancel_url=' . urlencode('https://example.com/cancel')
            . '&notify_url=' . urlencode('https://example.com/notify')
            . '&m_payment_id=PAY-1&amount=99.00&item_name=' . urlencode('Pro Plan')
            . '&passphrase=jt7NOE43FZPn';

        $this->assertSame(md5($expectedString), $signature);
    }

    public function test_generate_for_initiate_includes_custom_fields_in_payfasts_canonical_order(): void
    {
        // Regression test: custom_str1-5/custom_int1-5 were previously missing
        // from FIELD_ORDER, so a caller that sends them (e.g. to carry a slug
        // or internal ID through checkout) produced a signature PayFast's own
        // ITN validation would never match, since PayFast includes any custom
        // field that was actually sent when it recomputes the signature.
        $data = [
            'merchant_id' => '10000100',
            'merchant_key' => '46f0cd694581a',
            'm_payment_id' => 'PAY-1',
            'amount' => '10.00',
            'item_name' => 'Song',
            'custom_str1' => 'song-slug',
            'custom_str2' => '37071',
            'custom_int1' => '5',
        ];

        $signature = Signature::generateForInitiate($data, 'jt7NOE43FZPn');

        $expectedString = 'merchant_id=10000100&merchant_key=46f0cd694581a'
            . '&m_payment_id=PAY-1&amount=10.00&item_name=' . urlencode('Song')
            . '&custom_int1=5&custom_str1=song-slug&custom_str2=37071'
            . '&passphrase=jt7NOE43FZPn';

        $this->assertSame(md5($expectedString), $signature);
    }

    public function test_generate_for_initiate_omits_empty_and_missing_fields(): void
    {
        $withEmpty = Signature::generateForInitiate([
            'merchant_id' => '100',
            'name_first' => '',
            'm_payment_id' => 'PAY-1',
            'amount' => '10.00',
            'item_name' => 'Plan',
        ], null);

        $withoutField = Signature::generateForInitiate([
            'merchant_id' => '100',
            'm_payment_id' => 'PAY-1',
            'amount' => '10.00',
            'item_name' => 'Plan',
        ], null);

        $this->assertSame($withoutField, $withEmpty);
    }

    public function test_generate_for_initiate_changes_when_any_field_changes(): void
    {
        $base = ['merchant_id' => '100', 'm_payment_id' => 'PAY-1', 'amount' => '10.00', 'item_name' => 'Plan'];
        $changed = ['merchant_id' => '100', 'm_payment_id' => 'PAY-1', 'amount' => '10.01', 'item_name' => 'Plan'];

        $this->assertNotSame(
            Signature::generateForInitiate($base, 'pass'),
            Signature::generateForInitiate($changed, 'pass')
        );
    }

    public function test_generate_for_initiate_without_passphrase_is_stable(): void
    {
        $data = ['merchant_id' => '100', 'm_payment_id' => 'PAY-1', 'amount' => '10.00', 'item_name' => 'Plan'];

        $this->assertSame(
            Signature::generateForInitiate($data, null),
            Signature::generateForInitiate($data, null)
        );
    }

    public function test_is_valid_accepts_a_correctly_signed_payload(): void
    {
        $paramString = 'm_payment_id=PAY-1&payment_status=COMPLETE';
        $passphrase = 'jt7NOE43FZPn';
        $signature = md5($paramString . '&passphrase=' . urlencode($passphrase));

        $pfData = ['m_payment_id' => 'PAY-1', 'payment_status' => 'COMPLETE', 'signature' => $signature];

        $this->assertTrue(Signature::isValid($pfData, $paramString, $passphrase));
    }

    public function test_is_valid_rejects_a_tampered_payload_with_the_old_signature(): void
    {
        $paramString = 'm_payment_id=PAY-1&payment_status=COMPLETE';
        $passphrase = 'jt7NOE43FZPn';
        $staleSignature = md5($paramString . '&passphrase=' . urlencode($passphrase));

        $tamperedParamString = 'm_payment_id=PAY-1&payment_status=FAILED';
        $pfData = ['m_payment_id' => 'PAY-1', 'payment_status' => 'FAILED', 'signature' => $staleSignature];

        $this->assertFalse(Signature::isValid($pfData, $tamperedParamString, $passphrase));
    }

    public function test_is_valid_rejects_a_missing_signature_field(): void
    {
        $this->assertFalse(Signature::isValid([], 'm_payment_id=PAY-1', null));
    }

    public function test_generate_api_sorts_keys_and_excludes_signature_and_testing(): void
    {
        $signature = Signature::generateApi([
            'version' => 'v1',
            'signature' => 'ignored',
            'testing' => 'true',
            'merchant-id' => '10000100',
            'timestamp' => '2026-01-01T00:00:00+00:00',
        ], 'passphrase123');

        $expected = md5(implode('&', [
            'merchant-id=10000100',
            'passphrase=passphrase123',
            'timestamp=' . urlencode('2026-01-01T00:00:00+00:00'),
            'version=v1',
        ]));

        $this->assertSame($expected, $signature);
    }
}
