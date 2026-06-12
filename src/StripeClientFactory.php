<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle;

use Stripe\StripeClient;

final class StripeClientFactory
{
    public function __construct(
        #[\SensitiveParameter]
        private string $secretKey,
    ) {
    }

    public function create(): StripeClient
    {
        return new StripeClient($this->secretKey);
    }
}
