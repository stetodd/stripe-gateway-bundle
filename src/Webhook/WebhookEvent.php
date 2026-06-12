<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Webhook;

use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

/** @psalm-suppress MissingConstructor */
class WebhookEvent
{
    public string $id;
    public string $type;
    public int $created;
    public bool $livemode;
    public array $data;
    public array $metadata;
    public array $previousAttributes;

    public function getSession(): Session
    {
        return Session::constructFrom($this->getObjectData());
    }

    public function getSubscription(): Subscription
    {
        return Subscription::constructFrom($this->getObjectData());
    }

    public function getInvoice(): Invoice
    {
        return Invoice::constructFrom($this->getObjectData());
    }

    public function getSubscriptionSchedule(): SubscriptionSchedule
    {
        return SubscriptionSchedule::constructFrom($this->getObjectData());
    }

    private function getObjectData(): array
    {
        $objectData = $this->data['object'] ?? null;

        if (!is_array($objectData)) {
            throw new \RuntimeException('No object found or object is not an array');
        }

        return $objectData;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
