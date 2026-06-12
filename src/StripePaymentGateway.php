<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle;

use Psr\Log\LoggerInterface;
use Stetodd\PaymentGateway\Exception\Subscription\SubscriptionNotFoundException;
use Stetodd\PaymentGateway\Model\Checkout\LineItem;
use Stetodd\PaymentGateway\Model\Checkout\Session;
use Stetodd\PaymentGateway\Model\Customer;
use Stetodd\PaymentGateway\Model\Request\Checkout\CreateCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Customer\CreateCustomerRequest;
use Stetodd\PaymentGateway\Model\Request\Portal\CreatePortalSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\CancelSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\GetSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\ReactivateSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\UpdateSubscriptionPlanRequest;
use Stetodd\PaymentGateway\Model\Subscription;
use Stetodd\PaymentGateway\Model\Subscription\Status;
use Stetodd\PaymentGateway\PaymentGatewayInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private StripeClient $stripeClient,
        private string $successUrl,
        private string $cancelUrl,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function createCheckoutSession(CreateCheckoutSessionRequest $request): Session
    {
        $lineItems = array_map(
            fn (LineItem $item) => [
                'price' => $item->getPriceId(),
                'quantity' => $item->quantity,
            ],
            $request->lineItems->getItems()
        );
        $session = $this->stripeClient->checkout->sessions->create([
            'customer' => $request->customer->id,
            'mode' => 'subscription',
            'line_items' => $lineItems,
            'success_url' => $this->successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->cancelUrl,
            'metadata' => $request->getMetadata(),
        ]);

        $url = $session->url;
        if ($url === null) {
            $this->logger?->error('Stripe Checkout session URL is null', [
                'customer_id' => $request->customer->id,
                'line_items' => $lineItems,
                'session' => $session->toArray(),
                'metadata' => $request->getMetadata(),
            ]);

            throw new \RuntimeException(sprintf('Stripe Checkout session URL is null for %s and plan %s', $request->customer->id, implode(',', array_map(fn (LineItem $item) => $item->getPriceId(), $request->lineItems->getItems()))));
        }

        return new Session($url);
    }

    public function getSubscription(GetSubscriptionRequest $request): Subscription
    {
        try {
            $response = $this->stripeClient->subscriptions->retrieve($request->subscriptionId);
        } catch (InvalidRequestException $e) {
            throw new SubscriptionNotFoundException($request->subscriptionId, $e);
        }

        return $this->hydrateSubscription($response);
    }

    public function findSubscription(GetSubscriptionRequest $request): ?Subscription
    {
        try {
            return $this->getSubscription($request);
        } catch (SubscriptionNotFoundException) {
            return null;
        }
    }

    public function cancelSubscription(CancelSubscriptionRequest $request): Subscription
    {
        $response = $this->stripeClient->subscriptions->update(
            $request->subscriptionId,
            [
                'cancel_at_period_end' => $request->cancelAtPeriodEnd,
            ]
        );

        return $this->hydrateSubscription($response);
    }

    public function reactivateSubscription(ReactivateSubscriptionRequest $request): void
    {
        $this->stripeClient->subscriptions->update($request->subscriptionId, [
            'cancel_at_period_end' => false,
        ]);
    }

    public function updateSubscriptionPlan(UpdateSubscriptionPlanRequest $request): void
    {
        /** @psalm-suppress UndefinedMagicPropertyFetch, MixedPropertyFetch */
        $items = $this->stripeClient->subscriptions->retrieve($request->subscriptionId)->items;
        /** @psalm-suppress MixedPropertyFetch, MixedArrayAccess */
        $itemId = $items->data[0]->id;

        $this->stripeClient->subscriptions->update($request->subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $request->newPriceId]],
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => false,
        ]);
    }

    public function createPortalSession(CreatePortalSessionRequest $request): string
    {
        $session = $this->stripeClient->billingPortal->sessions->create([
            'customer' => $request->customerId,
            'return_url' => $request->returnUrl,
        ]);

        return $session->url;
    }

    public function createCustomer(CreateCustomerRequest $request): Customer
    {
        $response = $this->stripeClient->customers->create([
            'email' => $request->email,
            'metadata' => [
                'user_id' => $request->id,
            ],
        ]);

        return new Customer($response->id, []);
    }

    private function hydrateSubscription(\Stripe\Subscription $stripeSubscription): Subscription
    {
        /** @psalm-suppress UndefinedMagicPropertyFetch */
        $periodStart = new \DateTimeImmutable()
            ->setTimestamp((int) $stripeSubscription->current_period_start);
        /** @psalm-suppress UndefinedMagicPropertyFetch */
        $periodEnd = new \DateTimeImmutable()
            ->setTimestamp((int) $stripeSubscription->current_period_end);

        $cancelAtPeriodEnd = $stripeSubscription->cancel_at_period_end;

        return new Subscription(
            $stripeSubscription->id,
            $this->mapStatus($stripeSubscription->status),
            $periodStart,
            $periodEnd,
            $cancelAtPeriodEnd
        );
    }

    private function mapStatus(string $stripeStatus): Status
    {
        // Stripe spells it 'canceled'; the canonical status value is 'cancelled'.
        return match ($stripeStatus) {
            'canceled' => Status::Cancelled,
            default => Status::tryFrom($stripeStatus) ?? Status::Active,
        };
    }
}
