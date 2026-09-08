<?php

namespace Ngelekanyo\Payfast\Core;

class PaymentInitiation
{
    /**
     * @return array{paymentData: array, payfastUrl: string}|array{error: string}
     */
    public static function build(array $body, PayfastConfig $config): array
    {
        $amount = $body['amount'] ?? null;
        $itemName = $body['item_name'] ?? null;
        $paymentId = $body['m_payment_id'] ?? null;

        if (self::isBlank($amount) || self::isBlank($itemName) || self::isBlank($paymentId)) {
            return ['error' => 'Missing required fields'];
        }

        $parsedAmount = filter_var($amount, FILTER_VALIDATE_FLOAT);
        if ($parsedAmount === false || $parsedAmount <= 0) {
            return ['error' => 'Amount must be a valid positive number'];
        }

        $formattedAmount = number_format($parsedAmount, 2, '.', '');
        $billingDate = $body['billing_date'] ?? date('Y-m-d');
        $recurringAmount = $body['recurring_amount'] ?? $formattedAmount;

        $paymentData = [
            'merchant_id' => $config->merchantId ?? '',
            'merchant_key' => $config->merchantKey ?? '',
            'return_url' => $config->returnUrl,
            'cancel_url' => $config->cancelUrl,
            'notify_url' => $config->notifyUrl,
            'name_first' => $body['name_first'] ?? '',
            'name_last' => $body['name_last'] ?? '',
            'email_address' => $body['email_address'] ?? '',
            'm_payment_id' => $paymentId,
            'amount' => $formattedAmount,
            'item_name' => $itemName,
            'item_description' => $body['item_description'] ?? '',
            'subscription_type' => $body['subscription_type'] ?? 1,
            'billing_date' => $billingDate,
            'recurring_amount' => $recurringAmount,
            'frequency' => $body['frequency'] ?? 3,
            'cycles' => $body['cycles'] ?? 0,
            'subscription_notify_email' => $body['subscription_notify_email'] ?? true,
            'subscription_notify_webhook' => $body['subscription_notify_webhook'] ?? true,
            'subscription_notify_buyer' => $body['subscription_notify_buyer'] ?? true,
        ];

        $paymentData['signature'] = Signature::generateForInitiate($paymentData, $config->passphrase);

        return [
            'paymentData' => $paymentData,
            'payfastUrl' => self::processUrl($config),
        ];
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    public static function processUrl(PayfastConfig $config): string
    {
        return $config->sandbox
            ? 'https://sandbox.payfast.co.za/eng/process'
            : 'https://www.payfast.co.za/eng/process';
    }
}
