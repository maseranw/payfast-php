<?php

namespace Ngelekanyo\Payfast\Core;

class Signature
{
    private const FIELD_ORDER = [
        'merchant_id',
        'merchant_key',
        'return_url',
        'cancel_url',
        'notify_url',
        'name_first',
        'name_last',
        'email_address',
        'm_payment_id',
        'amount',
        'item_name',
        'item_description',
        'custom_int1',
        'custom_int2',
        'custom_int3',
        'custom_int4',
        'custom_int5',
        'custom_str1',
        'custom_str2',
        'custom_str3',
        'custom_str4',
        'custom_str5',
        'email_confirmation',
        'confirmation_address',
        'payment_method',
        'subscription_type',
        'billing_date',
        'recurring_amount',
        'frequency',
        'cycles',
        'subscription_notify_email',
        'subscription_notify_webhook',
        'subscription_notify_buyer',
    ];

    public static function generateForInitiate(array $data, ?string $passphrase = null): string
    {
        $paramString = '';

        foreach (self::FIELD_ORDER as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }

            $value = urlencode(trim((string) $data[$key]));
            $paramString .= "{$key}={$value}&";
        }

        $paramString = rtrim($paramString, '&');

        if ($passphrase !== null && $passphrase !== '') {
            $paramString .= '&passphrase=' . urlencode(trim($passphrase));
        }

        return md5($paramString);
    }

    public static function isValid(array $pfData, string $pfParamString, ?string $passphrase = null): bool
    {
        if ($passphrase !== null && $passphrase !== '') {
            $pfParamString .= '&passphrase=' . urlencode(trim($passphrase));
        }

        $expected = md5($pfParamString);
        $received = $pfData['signature'] ?? '';

        return hash_equals($expected, $received);
    }

    public static function generateApi(array $pfData, ?string $passphrase = null): string
    {
        $data = $pfData;
        unset($data['signature'], $data['testing']);

        if ($passphrase !== null) {
            $data['passphrase'] = $passphrase;
        }

        ksort($data);

        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        return md5(implode('&', $pairs));
    }
}
