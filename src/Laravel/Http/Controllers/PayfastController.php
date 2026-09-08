<?php

namespace Ngelekanyo\Payfast\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ngelekanyo\Payfast\Core\Itn;
use Ngelekanyo\Payfast\Core\PayfastConfig;
use Ngelekanyo\Payfast\Core\PaymentInitiation;
use Ngelekanyo\Payfast\Laravel\Events\PaymentReceived;
use Ngelekanyo\Payfast\Laravel\SubscriptionsApi;

class PayfastController
{
    public function __construct(
        private PayfastConfig $config,
        private SubscriptionsApi $subscriptionsApi,
        private Dispatcher $events,
        private ?\Closure $itnHttpPost = null,
    ) {
    }

    public function initiate(Request $request): JsonResponse
    {
        $result = PaymentInitiation::build($request->all(), $this->config);

        if (isset($result['error'])) {
            return new JsonResponse(['error' => $result['error']], 400);
        }

        return new JsonResponse($result);
    }

    public function notify(Request $request): JsonResponse
    {
        $result = Itn::verifyIncoming(
            $request->all(),
            merchantId: $this->config->merchantId ?? '',
            passphrase: $this->config->passphrase,
            sandbox: $this->config->sandbox,
            sourceIp: $request->ip(),
            httpPost: $this->itnHttpPost,
        );

        if (!$result['valid']) {
            return new JsonResponse(['error' => $result['reason']], 400);
        }

        $this->events->dispatch(new PaymentReceived($result['payload']));

        return new JsonResponse(['status' => 'ok']);
    }

    public function cancel(string $token): JsonResponse
    {
        return $this->respondWith($this->subscriptionsApi->cancel($token));
    }

    public function pause(string $token): JsonResponse
    {
        return $this->respondWith($this->subscriptionsApi->pause($token));
    }

    public function unpause(string $token): JsonResponse
    {
        return $this->respondWith($this->subscriptionsApi->unpause($token));
    }

    public function fetch(string $token): JsonResponse
    {
        return $this->respondWith($this->subscriptionsApi->fetch($token));
    }

    private function respondWith(array $result): JsonResponse
    {
        return new JsonResponse($result['payload'], $result['status']);
    }
}
