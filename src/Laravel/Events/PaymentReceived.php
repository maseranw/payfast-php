<?php

namespace Ngelekanyo\Payfast\Laravel\Events;

class PaymentReceived
{
    public function __construct(public readonly array $itnData)
    {
    }
}
