<?php

namespace Ngelekanyo\Payfast\Core;

class SubscriptionApiClient
{
    public static function currentIsoTimestamp(): string
    {
        return date('Y-m-d\TH:i:s') . self::timezoneOffset();
    }

    private static function timezoneOffset(): string
    {
        $offsetMinutes = (int) (date('Z') / 60);
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $hours = str_pad((string) intdiv(abs($offsetMinutes), 60), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string) (abs($offsetMinutes) % 60), 2, '0', STR_PAD_LEFT);

        return "{$sign}{$hours}:{$minutes}";
    }

    public static function buildSignedHeaders(?string $merchantId, ?string $passphrase, string $apiVersion = 'v1'): array
    {
        $headers = [
            'merchant-id' => $merchantId ?? '',
            'version' => $apiVersion,
            'timestamp' => self::currentIsoTimestamp(),
        ];

        $headers['signature'] = Signature::generateApi($headers, $passphrase);

        return $headers;
    }

    /**
     * @return array{csrfToken: ?string, sessionCookie: ?string}
     */
    public static function fetchCsrfAndSession(string $baseUrl, ?callable $httpGet = null): array
    {
        $httpGet ??= static function (string $url): ?array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Accept: text/html'],
            ]);
            $response = curl_exec($ch);
            if (curl_errno($ch) !== 0 || $response === false) {
                curl_close($ch);

                return null;
            }
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            return [
                'headers' => substr((string) $response, 0, $headerSize),
                'body' => substr((string) $response, $headerSize),
            ];
        };

        try {
            $result = $httpGet($baseUrl);
            if ($result === null) {
                return ['csrfToken' => null, 'sessionCookie' => null];
            }

            $csrfToken = null;
            if (preg_match('/<meta name="csrf-token" content="(.+?)"/', $result['body'] ?? '', $matches)) {
                $csrfToken = $matches[1];
            }

            $sessionCookie = null;
            if (preg_match_all('/^Set-Cookie:\s*(.+)$/mi', $result['headers'] ?? '', $matches)) {
                $sessionCookie = implode('; ', array_map(
                    static fn (string $cookie) => trim(explode(';', $cookie, 2)[0]),
                    $matches[1]
                ));
            }

            return ['csrfToken' => $csrfToken, 'sessionCookie' => $sessionCookie];
        } catch (\Throwable) {
            return ['csrfToken' => null, 'sessionCookie' => null];
        }
    }

    /**
     * @return array{status: int, payload: mixed}
     */
    public static function attemptRequest(
        string $method,
        string $url,
        array $headers,
        ?callable $httpRequest = null,
        ?callable $fetchCsrfAndSession = null
    ): array {
        $httpRequest ??= static function (string $method, string $url, array $headers): array {
            $ch = curl_init($url);
            $headerLines = [];
            foreach ($headers as $key => $value) {
                $headerLines[] = "{$key}: {$value}";
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errored = curl_errno($ch) !== 0;
            curl_close($ch);

            if ($errored) {
                return ['status' => 500, 'body' => null];
            }

            $decoded = json_decode((string) $body, true);

            return ['status' => $status, 'body' => $decoded ?? $body];
        };

        $fetchCsrfAndSession ??= [self::class, 'fetchCsrfAndSession'];

        $maxRetries = 2;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $result = $httpRequest($method, $url, $headers);

            if ($result['status'] === 419 && $attempt < $maxRetries - 1) {
                $baseUrl = strtok($url, '/subscriptions') !== false
                    ? substr($url, 0, strpos($url, '/subscriptions'))
                    : $url;
                $session = $fetchCsrfAndSession($baseUrl);
                if ($session['csrfToken']) {
                    $headers['X-CSRF-TOKEN'] = $session['csrfToken'];
                }
                if ($session['sessionCookie']) {
                    $headers['Cookie'] = $session['sessionCookie'];
                }
                $attempt++;
                continue;
            }

            if ($result['status'] >= 200 && $result['status'] < 300) {
                $body = is_array($result['body']) ? $result['body'] : [];

                return [
                    'status' => $result['status'],
                    'payload' => [
                        'message' => $body['message'] ?? null,
                        'data' => $result['body'],
                    ],
                ];
            }

            return [
                'status' => $result['status'],
                'payload' => [
                    'error' => is_array($result['body']) ? ($result['body']['message'] ?? 'Request failed') : 'Request failed',
                    'details' => $result['body'],
                ],
            ];
        }

        return ['status' => 500, 'payload' => ['error' => 'Max retry attempts exceeded']];
    }
}
