<?php

namespace Ngelekanyo\Payfast\Core;

final class PayfastConfig
{
    public function __construct(
        public bool $sandbox,
        public ?string $merchantId,
        public ?string $merchantKey,
        public string $passphrase,
        public string $returnUrl,
        public string $cancelUrl,
        public string $notifyUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        $testingMode = getenv('TESTING_MODE');
        $appEnv = getenv('APP_ENV') ?: getenv('NODE_ENV');

        return new self(
            sandbox: $testingMode === 'true' || $appEnv !== 'production',
            merchantId: self::env('PAYFAST_MERCHANT_ID'),
            merchantKey: self::env('PAYFAST_MERCHANT_KEY'),
            passphrase: self::env('PAYFAST_PASSPHRASE') ?? '',
            returnUrl: self::env('PAYFAST_RETURN_URL') ?? '',
            cancelUrl: self::env('PAYFAST_CANCEL_URL') ?? '',
            notifyUrl: self::env('PAYFAST_NOTIFY_URL') ?? '',
        );
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? null : $value;
    }
}
