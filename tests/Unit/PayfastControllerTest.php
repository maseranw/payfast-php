<?php

namespace Ngelekanyo\Payfast\Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Ngelekanyo\Payfast\Core\PayfastConfig;
use Ngelekanyo\Payfast\Laravel\Events\PaymentReceived;
use Ngelekanyo\Payfast\Laravel\Http\Controllers\PayfastController;
use Ngelekanyo\Payfast\Laravel\SubscriptionsApi;
use PHPUnit\Framework\TestCase;

class PayfastControllerTest extends TestCase
{
    private function config(): PayfastConfig
    {
        return new PayfastConfig(
            sandbox: true,
            merchantId: '10000100',
            merchantKey: 'key',
            passphrase: 'passphrase123',
            returnUrl: 'https://example.com/return',
            cancelUrl: 'https://example.com/cancel',
            notifyUrl: 'https://example.com/notify',
        );
    }

    private function controller(?Dispatcher $events = null, ?\Closure $itnHttpPost = null): PayfastController
    {
        $subscriptionsApi = $this->createMock(SubscriptionsApi::class);
        $subscriptionsApi->method('cancel')->willReturn(['status' => 200, 'payload' => ['message' => 'cancelled']]);
        $subscriptionsApi->method('pause')->willReturn(['status' => 200, 'payload' => ['message' => 'paused']]);
        $subscriptionsApi->method('unpause')->willReturn(['status' => 200, 'payload' => ['message' => 'resumed']]);
        $subscriptionsApi->method('fetch')->willReturn(['status' => 404, 'payload' => ['error' => 'not found']]);

        return new PayfastController(
            $this->config(),
            $subscriptionsApi,
            $events ?? $this->createMock(Dispatcher::class),
            $itnHttpPost,
        );
    }

    public function test_initiate_rejects_invalid_payload(): void
    {
        $request = Request::create('/payfast/initiate', 'POST', ['item_name' => 'Plan']);

        $response = $this->controller()->initiate($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Missing required fields'], $response->getData(true));
    }

    public function test_initiate_returns_signed_payload_for_valid_request(): void
    {
        $request = Request::create('/payfast/initiate', 'POST', [
            'amount' => '99.00',
            'item_name' => 'Plan',
            'm_payment_id' => 'PAY-1',
        ]);

        $response = $this->controller()->initiate($request);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($data['paymentData']['signature']);
        $this->assertStringContainsString('sandbox.payfast.co.za', $data['payfastUrl']);
    }

    public function test_notify_rejects_an_unsigned_payload(): void
    {
        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->never())->method('dispatch');

        $request = Request::create('/payfast/notify', 'POST', ['m_payment_id' => 'PAY-1']);

        $response = $this->controller($events)->notify($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_notify_dispatches_payment_received_for_a_valid_itn(): void
    {
        $fields = [
            'm_payment_id' => 'PAY-1',
            'pf_payment_id' => '9999',
            'payment_status' => 'COMPLETE',
            'item_name' => 'Subscription',
            'merchant_id' => '10000100',
            'token' => 'abc123',
        ];
        $paramString = '';
        foreach ($fields as $key => $value) {
            $paramString .= "{$key}=" . urlencode($value) . '&';
        }
        $paramString = rtrim($paramString, '&') . '&passphrase=' . urlencode('passphrase123');
        $fields['signature'] = md5($paramString);

        $events = $this->createMock(Dispatcher::class);
        $events->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn (PaymentReceived $event) => $event->itnData['m_payment_id'] === 'PAY-1'));

        $request = Request::create('/payfast/notify', 'POST', $fields);

        $response = $this->controller($events, fn () => 'VALID')->notify($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_cancel_delegates_to_subscriptions_api(): void
    {
        $response = $this->controller()->cancel('token-123');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'cancelled'], $response->getData(true));
    }

    public function test_fetch_returns_upstream_error_status(): void
    {
        $response = $this->controller()->fetch('missing-token');

        $this->assertSame(404, $response->getStatusCode());
    }
}
