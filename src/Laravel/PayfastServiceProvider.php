<?php

namespace Ngelekanyo\Payfast\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ngelekanyo\Payfast\Core\PayfastConfig;

class PayfastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/payfast.php', 'payfast');

        $this->app->singleton(PayfastConfig::class, static function (): PayfastConfig {
            return new PayfastConfig(
                sandbox: (bool) config('payfast.sandbox'),
                merchantId: config('payfast.merchant_id'),
                merchantKey: config('payfast.merchant_key'),
                passphrase: (string) config('payfast.passphrase'),
                returnUrl: (string) config('payfast.return_url'),
                cancelUrl: (string) config('payfast.cancel_url'),
                notifyUrl: (string) config('payfast.notify_url'),
            );
        });

        $this->app->singleton(SubscriptionsApi::class, static function ($app): SubscriptionsApi {
            $config = $app->make(PayfastConfig::class);

            return new SubscriptionsApi(
                baseUrl: config('payfast.subscriptions_api_base'),
                merchantId: $config->merchantId,
                passphrase: $config->passphrase,
                sandbox: $config->sandbox,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/payfast.php' => config_path('payfast.php'),
        ], 'payfast-config');

        Route::prefix(config('payfast.route_prefix'))
            ->middleware(config('payfast.route_middleware'))
            ->group(__DIR__ . '/routes.php');
    }
}
