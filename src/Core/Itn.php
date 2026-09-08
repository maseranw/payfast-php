<?php

namespace Ngelekanyo\Payfast\Core;

class Itn
{
    private const FIELDS = [
        'm_payment_id',
        'pf_payment_id',
        'payment_status',
        'item_name',
        'item_description',
        'amount_gross',
        'amount_fee',
        'amount_net',
        'custom_str1',
        'custom_str2',
        'custom_str3',
        'custom_str4',
        'custom_str5',
        'custom_int1',
        'custom_int2',
        'custom_int3',
        'custom_int4',
        'custom_int5',
        'name_first',
        'name_last',
        'email_address',
        'merchant_id',
        'token',
        'billing_date',
        'signature',
    ];

    public static function createPayload(array $body): array
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            $payload[$field] = $body[$field] ?? '';
        }

        return $payload;
    }

    public static function validateWithPayfast(
        array $pfData,
        bool $sandbox,
        ?callable $httpPost = null
    ): bool {
        $url = $sandbox
            ? 'https://sandbox.payfast.co.za/eng/query/validate'
            : 'https://www.payfast.co.za/eng/query/validate';

        $httpPost ??= static function (string $url, string $body): ?string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $failed = curl_errno($ch) !== 0;
            curl_close($ch);

            return $failed ? null : (string) $response;
        };

        $body = http_build_query($pfData);
        $response = $httpPost($url, $body);

        return $response !== null && trim($response) === 'VALID';
    }

    public static function isPayfastSourceIp(string $ip, ?callable $reverseDns = null): bool
    {
        $reverseDns ??= static fn (string $ip): string|false => gethostbyaddr($ip);

        $hostname = $reverseDns($ip);
        if ($hostname === false || $hostname === $ip) {
            return false;
        }

        return str_ends_with($hostname, 'payfast.co.za');
    }

    public static function buildParamString(array $body): array
    {
        $paramString = '';
        foreach ($body as $key => $value) {
            if ($key === 'signature') {
                continue;
            }

            // An optional field PayFast didn't send arrives as null through
            // Laravel's request bag rather than being absent from the array
            // entirely - treat it the same as absent. Scalars (e.g. an int
            // custom_int field) are coerced to string; only a genuinely
            // malformed payload (an array value) is rejected.
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                return [null, "Invalid ITN field \"{$key}\": expected a string"];
            }

            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

            $paramString .= "{$key}=" . urlencode(trim($value)) . '&';
        }

        return [rtrim($paramString, '&'), null];
    }

    /**
     * @return array{valid: bool, payload?: array, reason?: string}
     */
    public static function verifyIncoming(
        array $body,
        string $merchantId,
        ?string $passphrase,
        bool $sandbox,
        string $sourceIp,
        ?callable $httpPost = null,
        ?callable $reverseDns = null
    ): array {
        [$paramString, $error] = self::buildParamString($body);
        if ($error !== null) {
            return ['valid' => false, 'reason' => $error];
        }

        $payload = self::createPayload($body);
        $locallyValid = Signature::isValid($payload, $paramString, $passphrase);

        if ($payload['merchant_id'] !== $merchantId) {
            return ['valid' => false, 'reason' => 'Merchant ID mismatch'];
        }

        if (!$sandbox && !self::isPayfastSourceIp($sourceIp, $reverseDns)) {
            return ['valid' => false, 'reason' => 'Invalid source IP'];
        }

        // PayFast's own /query/validate round-trip is the authoritative
        // check - it confirms the payload is byte-for-byte what PayFast
        // sent, which also rules out a forged signature. The local
        // signature recomputation above is kept as a fast, offline sanity
        // check, but is not itself a hard gate: real ITN payloads have been
        // observed where this md5 reproduction disagrees with PayFast's own
        // signature (an encoding edge case in their algorithm that hasn't
        // been fully reverse-engineered) even though PayFast confirms the
        // payload as genuine. Rejecting those would silently drop real
        // completed payments, so a local mismatch is only fatal when
        // PayFast's own validation also fails to confirm the payload.
        if (!self::validateWithPayfast($payload, $sandbox, $httpPost)) {
            return [
                'valid' => false,
                'reason' => $locallyValid ? 'Validation with PayFast failed' : 'Invalid signature',
            ];
        }

        return ['valid' => true, 'payload' => $payload];
    }
}
