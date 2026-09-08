# ngelekanyo/payfast

PayFast subscription toolkit for PHP: signature generation/verification, ITN
(webhook) handling, and payment initiation, with an optional Laravel
integration layer. PHP port of [`@ngelekanyo/payfast`](https://www.npmjs.com/package/@ngelekanyo/payfast).

## Install

```bash
composer require ngelekanyo/payfast
```

## Core (framework-agnostic)

Everything under `Ngelekanyo\Payfast\Core` has no framework dependency and
works in any PHP 8.1+ codebase.

```php
use Ngelekanyo\Payfast\Core\PayfastConfig;
use Ngelekanyo\Payfast\Core\PaymentInitiation;
use Ngelekanyo\Payfast\Core\Itn;

$config = PayfastConfig::fromEnv(); // reads PAYFAST_* env vars

// Initiate a once-off payment (e.g. a product purchase)
$result = PaymentInitiation::build([
    'amount' => '99.00',
    'item_name' => 'Widget',
    'm_payment_id' => 'ORDER-123',
], $config);
if (isset($result['error'])) {
    // $result['error'] is a user-facing validation message
}
// $result['paymentData'] is the signed payload to submit to $result['payfastUrl']

// Initiate a subscription by including subscription_type — this adds
// billing_date/recurring_amount/frequency/cycles with sensible defaults
$result = PaymentInitiation::build([
    'amount' => '99.00',
    'item_name' => 'Pro Plan',
    'm_payment_id' => 'SUB-123',
    'subscription_type' => 1,
], $config);

// Verify an incoming ITN (webhook) request
$result = Itn::verifyIncoming(
    body: $_POST,
    merchantId: $config->merchantId,
    passphrase: $config->passphrase,
    sandbox: $config->sandbox,
    sourceIp: $_SERVER['REMOTE_ADDR'],
);

if ($result['valid']) {
    $itn = $result['payload']; // trusted ITN fields
} else {
    // $result['reason'] explains why verification failed
}
```

### Subscription management

`Core\SubscriptionApiClient` builds signed headers and performs
retry-on-419 requests against PayFast's Subscriptions API. It is
transport-agnostic — pass your own `httpRequest`/`httpGet` callables, or let
it fall back to its built-in cURL implementation.

## Environment variables

| Variable | Required | Description |
|---|---|---|
| `PAYFAST_MERCHANT_ID` | yes | PayFast merchant ID |
| `PAYFAST_MERCHANT_KEY` | yes | PayFast merchant key |
| `PAYFAST_PASSPHRASE` | yes | PayFast passphrase used to sign/verify requests |
| `PAYFAST_RETURN_URL` | yes | Redirect URL after a successful payment |
| `PAYFAST_CANCEL_URL` | yes | Redirect URL after a cancelled payment |
| `PAYFAST_NOTIFY_URL` | yes | ITN webhook URL |
| `PAYFAST_SUBSCRIPTIONS_API_BASE` | no | Defaults to `https://api.payfast.co.za` |
| `TESTING_MODE` / `APP_ENV` | no | `TESTING_MODE=true` or a non-`production` `APP_ENV` runs against the PayFast sandbox |

## Laravel integration

The package auto-registers `Ngelekanyo\Payfast\Laravel\PayfastServiceProvider`
via Laravel package discovery. It binds a `PayfastConfig` (built from
`config/payfast.php`, itself populated from the environment) and a
`SubscriptionsApi` client into the container, and registers routes under a
configurable prefix (default `payfast`):

| Method | Path | Description |
|---|---|---|
| `POST` | `/payfast/initiate` | Build a signed payment payload |
| `POST` | `/payfast/notify` | Verify an incoming ITN and dispatch `PaymentReceived` |
| `DELETE` | `/payfast/cancel/{token}` | Cancel a subscription |
| `PUT` | `/payfast/pause/{token}` | Pause a subscription |
| `PUT` | `/payfast/unpause/{token}` | Resume a subscription |
| `GET` | `/payfast/fetch/{token}` | Fetch a subscription's current state |

Publish the config file to customize the route prefix, middleware, or
subscriptions API base URL:

```bash
php artisan vendor:publish --tag=payfast-config
```

Listen for `Ngelekanyo\Payfast\Laravel\Events\PaymentReceived` to persist a
successful payment/subscription activation:

```php
use Ngelekanyo\Payfast\Laravel\Events\PaymentReceived;

Event::listen(PaymentReceived::class, function (PaymentReceived $event) {
    $event->itnData; // verified ITN fields: m_payment_id, payment_status, token, ...
});
```

The routes are unauthenticated by default (PayFast calls `/notify` directly,
and `/initiate` typically runs behind your own app's session/auth
middleware) — add ownership/authentication middleware in your own app around
`/cancel`, `/pause`, `/unpause`, and `/fetch` before exposing them publicly,
the same way `payfast-subscribe-api` does for the Node package.

## Security notes

- ITN verification calls back to PayFast's `/eng/query/validate` endpoint
  and checks the request's source IP resolves to `*.payfast.co.za` outside
  sandbox mode — never trust ITN data on signature match alone.
- Signature comparison uses `hash_equals()` (constant-time).
- This package does not perform authentication or ownership checks on
  subscription tokens — that is the responsibility of the consuming
  application.

## Testing

```bash
composer install
./vendor/bin/phpunit
```

## License

MIT
