<?php

namespace Ngelekanyo\Payfast\Laravel;

use Ngelekanyo\Payfast\Core\SubscriptionApiClient;

class SubscriptionsApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $merchantId,
        private readonly ?string $passphrase,
        private readonly bool $sandbox,
    ) {
    }

    public function pause(string $token): array
    {
        return $this->send('PUT', "{$token}/pause");
    }

    public function unpause(string $token): array
    {
        return $this->send('PUT', "{$token}/unpause");
    }

    public function cancel(string $token): array
    {
        return $this->send('DELETE', $token);
    }

    public function fetch(string $token): array
    {
        return $this->send('GET', $token);
    }

    private function send(string $method, string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/subscriptions/' . $path;
        if ($this->sandbox) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'testing=true';
        }

        $headers = SubscriptionApiClient::buildSignedHeaders($this->merchantId, $this->passphrase);

        return SubscriptionApiClient::attemptRequest($method, $url, $headers);
    }
}
