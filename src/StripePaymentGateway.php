<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle;

use Psr\Log\LoggerInterface;
use Stetodd\PaymentGateway\Exception\Payment\PaymentNotFoundException;
use Stetodd\PaymentGateway\Exception\Subscription\SubscriptionNotFoundException;
use Stetodd\PaymentGateway\Model\Checkout\LineItem;
use Stetodd\PaymentGateway\Model\Checkout\Session;
use Stetodd\PaymentGateway\Model\Customer;
use Stetodd\PaymentGateway\Model\Payment\Payment;
use Stetodd\PaymentGateway\Model\Payment\PaymentStatus;
use Stetodd\PaymentGateway\Model\Request\Checkout\CreateCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Customer\CreateCustomerRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CancelPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CapturePaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CreatePaymentHoldRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\GetPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Portal\CreatePortalSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\CancelSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\GetSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\ReactivateSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\UpdateSubscriptionPlanRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\UpdateSubscriptionQuantityRequest;
use Stetodd\PaymentGateway\Model\Subscription;
use Stetodd\PaymentGateway\Model\Subscription\Status;
use Stetodd\PaymentGateway\PaymentGatewayInterface;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private StripeClient $stripeClient,
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
            'success_url' => $request->successUrl.(str_contains($request->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->cancelUrl,
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

    public function updateSubscriptionQuantity(UpdateSubscriptionQuantityRequest $request): void
    {
        /** @psalm-suppress UndefinedMagicPropertyFetch, MixedPropertyFetch */
        $items = $this->stripeClient->subscriptions->retrieve($request->subscriptionId)->items;
        /** @psalm-suppress MixedPropertyFetch, MixedArrayAccess */
        $itemId = $items->data[0]->id;

        $this->stripeClient->subscriptions->update($request->subscriptionId, [
            'items' => [['id' => $itemId, 'quantity' => $request->quantity]],
            'proration_behavior' => 'create_prorations',
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

    /**
     * A Checkout Session in payment mode with manual capture: Stripe authorises
     * the amount and holds it until {@see capturePayment()} or
     * {@see cancelPayment()}. Card authorisations last seven days for a
     * customer-initiated transaction. The completed-checkout webhook's session
     * carries the `payment_intent` id and this request's metadata.
     */
    public function createPaymentHoldSession(CreatePaymentHoldRequest $request): Session
    {
        $session = $this->stripeClient->checkout->sessions->create([
            'customer' => $request->customer->id,
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->currency),
                    'unit_amount' => $request->amount,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            'payment_intent_data' => [
                'capture_method' => 'manual',
                'description' => $request->description,
                'metadata' => $request->getMetadata(),
            ],
            'success_url' => $request->successUrl.(str_contains($request->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->getMetadata(),
        ]);

        $url = $session->url;
        if ($url === null) {
            $this->logger?->error('Stripe Checkout hold session URL is null', [
                'customer_id' => $request->customer->id,
                'amount' => $request->amount,
                'session' => $session->toArray(),
                'metadata' => $request->getMetadata(),
            ]);

            throw new \RuntimeException(sprintf('Stripe Checkout hold session URL is null for %s', $request->customer->id));
        }

        return new Session($url);
    }

    public function capturePayment(CapturePaymentRequest $request): Payment
    {
        $params = $request->amountToCapture !== null ? ['amount_to_capture' => $request->amountToCapture] : [];

        try {
            $intent = $this->stripeClient->paymentIntents->capture($request->paymentId, $params);
        } catch (InvalidRequestException $e) {
            throw new PaymentNotFoundException($request->paymentId, $e);
        }

        return $this->hydratePayment($intent);
    }

    public function cancelPayment(CancelPaymentRequest $request): Payment
    {
        $params = $request->reason !== null ? ['cancellation_reason' => $request->reason] : [];

        try {
            $intent = $this->stripeClient->paymentIntents->cancel($request->paymentId, $params);
        } catch (InvalidRequestException $e) {
            throw new PaymentNotFoundException($request->paymentId, $e);
        }

        return $this->hydratePayment($intent);
    }

    public function getPayment(GetPaymentRequest $request): Payment
    {
        try {
            $intent = $this->stripeClient->paymentIntents->retrieve($request->paymentId);
        } catch (InvalidRequestException $e) {
            throw new PaymentNotFoundException($request->paymentId, $e);
        }

        return $this->hydratePayment($intent);
    }

    private function hydratePayment(PaymentIntent $intent): Payment
    {
        /** @psalm-suppress UndefinedMagicPropertyFetch */
        $captured = (int) ($intent->amount_received ?? 0);

        return new Payment(
            $intent->id,
            $this->mapPaymentStatus((string) $intent->status),
            (int) $intent->amount,
            (string) $intent->currency,
            $captured,
        );
    }

    private function mapPaymentStatus(string $stripeStatus): PaymentStatus
    {
        // Stripe spells it 'canceled'; the canonical status value is 'cancelled'.
        return match ($stripeStatus) {
            'canceled' => PaymentStatus::Cancelled,
            default => PaymentStatus::tryFrom($stripeStatus) ?? PaymentStatus::Processing,
        };
    }

    private function hydrateSubscription(\Stripe\Subscription $stripeSubscription): Subscription
    {
        [$periodStart, $periodEnd] = $this->currentPeriod($stripeSubscription);

        $cancelAtPeriodEnd = $stripeSubscription->cancel_at_period_end;

        return new Subscription(
            $stripeSubscription->id,
            $this->mapStatus($stripeSubscription->status),
            $periodStart,
            $periodEnd,
            $cancelAtPeriodEnd
        );
    }

    /**
     * Stripe API 2025-03-31 (basil) moved current_period_start/end off the
     * subscription and onto each subscription item, so on current API versions
     * the top-level fields are absent and read as epoch zero. Items win when
     * present (the earliest start and latest end across them); the top-level
     * fields remain the fallback for accounts pinned to an older version.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function currentPeriod(\Stripe\Subscription $stripeSubscription): array
    {
        $start = null;
        $end = null;

        foreach ($stripeSubscription->items->data as $item) {
            if (isset($item->current_period_start)) {
                $start = min($start ?? \PHP_INT_MAX, (int) $item->current_period_start);
            }
            if (isset($item->current_period_end)) {
                $end = max($end ?? 0, (int) $item->current_period_end);
            }
        }

        /** @psalm-suppress UndefinedMagicPropertyFetch */
        $start ??= isset($stripeSubscription->current_period_start) ? (int) $stripeSubscription->current_period_start : 0;
        /** @psalm-suppress UndefinedMagicPropertyFetch */
        $end ??= isset($stripeSubscription->current_period_end) ? (int) $stripeSubscription->current_period_end : 0;

        return [
            new \DateTimeImmutable()->setTimestamp($start),
            new \DateTimeImmutable()->setTimestamp($end),
        ];
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
