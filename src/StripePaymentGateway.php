<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle;

use Psr\Log\LoggerInterface;
use Stetodd\PaymentGateway\Exception\Payment\PaymentNotFoundException;
use Stetodd\PaymentGateway\Exception\Payment\RefundFailedException;
use Stetodd\PaymentGateway\Exception\Subscription\SubscriptionNotFoundException;
use Stetodd\PaymentGateway\Model\Checkout\CheckoutMode;
use Stetodd\PaymentGateway\Model\Checkout\CheckoutSession;
use Stetodd\PaymentGateway\Model\Checkout\CheckoutStatus;
use Stetodd\PaymentGateway\Model\Checkout\CustomText;
use Stetodd\PaymentGateway\Model\Checkout\LineItem;
use Stetodd\PaymentGateway\Model\Checkout\Session;
use Stetodd\PaymentGateway\Model\Customer;
use Stetodd\PaymentGateway\Model\Payment\Payment;
use Stetodd\PaymentGateway\Model\Payment\PaymentStatus;
use Stetodd\PaymentGateway\Model\Payment\Refund;
use Stetodd\PaymentGateway\Model\Payment\RefundStatus;
use Stetodd\PaymentGateway\Model\Request\Checkout\CreateCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Checkout\GetCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Customer\CreateCustomerRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CancelPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CapturePaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CreatePaymentHoldRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\GetPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\RefundPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Portal\CreatePortalSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\CancelSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\GetSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\ReactivateSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\UpdateSubscriptionPlanRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\UpdateSubscriptionQuantityRequest;
use Stetodd\PaymentGateway\Model\Subscription;
use Stetodd\PaymentGateway\Model\Subscription\Status;
use Stetodd\PaymentGateway\Model\Subscription\SubscriptionPayment;
use Stetodd\PaymentGateway\PaymentGatewayInterface;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripePaymentGateway implements PaymentGatewayInterface
{
    /** Refund metadata key carrying the caller's idempotency key. */
    private const string REFUND_KEY = 'idempotency_key';

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
        $payment = $request->mode === CheckoutMode::Payment;
        $session = $this->stripeClient->checkout->sessions->create([
            'customer' => $request->customer->id,
            'mode' => $request->mode->value,
            'line_items' => $lineItems,
            'success_url' => $request->successUrl.(str_contains($request->successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->getMetadata(),
        ]
            // In payment mode the charge is its own object, so the metadata has
            // to be put on it too or a refund months later has nothing to read.
            + ($payment ? ['payment_intent_data' => ['metadata' => $request->getMetadata()]] : [])
            + $this->customTextParams($request->customText));

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

        return new Session($session->id, $url);
    }

    public function findCheckoutSession(GetCheckoutSessionRequest $request): ?CheckoutSession
    {
        try {
            $session = $this->stripeClient->checkout->sessions->retrieve($request->sessionId);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }

            throw $e;
        }

        // Read through the array form: a session Stripe left a field off
        // altogether is ordinary, and reading it off the object warns.
        $data = $session->toArray();

        /** @var array<string, string> $metadata */
        $metadata = $data['metadata'] ?? [];

        return new CheckoutSession(
            $session->id,
            CheckoutStatus::tryFrom((string) ($data['status'] ?? '')) ?? CheckoutStatus::Open,
            // A checkout that needed no payment (a full discount, say) is as
            // settled as one that was paid.
            in_array($data['payment_status'] ?? null, ['paid', 'no_payment_required'], true),
            self::idOf($data['subscription'] ?? null),
            self::idOf($data['customer'] ?? null),
            (int) ($data['amount_total'] ?? 0),
            $metadata,
            self::idOf($data['payment_intent'] ?? null),
        );
    }

    /**
     * Stripe refuses to expire a session that is no longer open, which is the
     * outcome asked for, so that refusal is not an error here.
     */
    public function expireCheckoutSession(GetCheckoutSessionRequest $request): void
    {
        try {
            $this->stripeClient->checkout->sessions->expire($request->sessionId);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing' || $e->getHttpStatus() === 400) {
                return;
            }

            throw $e;
        }
    }

    /** A related object comes back either as a bare id or expanded. */
    private static function idOf(mixed $related): ?string
    {
        if (is_string($related)) {
            return $related;
        }

        $id = $related instanceof \Stripe\StripeObject ? $related->id : null;

        return is_string($id) ? $id : null;
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

    /**
     * Stripe API basil moved the payment off the invoice (`payment_intent`)
     * and onto invoice payments, so the paid invoice is found first and its
     * payment second. An invoice settled entirely from credit balance has no
     * payment to refund and reads as nothing paid.
     */
    public function findLatestSubscriptionPayment(GetSubscriptionRequest $request): ?SubscriptionPayment
    {
        try {
            $invoices = $this->stripeClient->invoices->all([
                'subscription' => $request->subscriptionId,
                'status' => 'paid',
                'limit' => 1,
            ]);
        } catch (InvalidRequestException $e) {
            throw new SubscriptionNotFoundException($request->subscriptionId, $e);
        }

        $invoice = $invoices->data[0] ?? null;
        if (!$invoice instanceof Invoice || $invoice->amount_paid < 1) {
            return null;
        }

        $paymentId = $this->paymentIdForInvoice($invoice->id);
        if ($paymentId === null) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->servicePeriod($invoice);
        $paidAt = $invoice->status_transitions->paid_at ?? $invoice->created;

        return new SubscriptionPayment(
            $request->subscriptionId,
            $invoice->id,
            $paymentId,
            $invoice->amount_paid,
            $invoice->currency,
            new \DateTimeImmutable()->setTimestamp($paidAt),
            $periodStart,
            $periodEnd,
        );
    }

    /**
     * At period end, the subscription is flagged and runs out its paid period.
     * Otherwise it is cancelled now: Stripe ends it at once, with no proration
     * invoice, and sends customer.subscription.deleted. (Before v0.5.1 the
     * immediate form only cleared cancel_at_period_end and cancelled nothing.)
     */
    public function cancelSubscription(CancelSubscriptionRequest $request): Subscription
    {
        try {
            $response = $request->cancelAtPeriodEnd
                ? $this->stripeClient->subscriptions->update($request->subscriptionId, ['cancel_at_period_end' => true])
                : $this->stripeClient->subscriptions->cancel($request->subscriptionId);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                throw new SubscriptionNotFoundException($request->subscriptionId, $e);
            }

            throw $e;
        }

        return $this->hydrateSubscription($response);
    }

    public function reactivateSubscription(ReactivateSubscriptionRequest $request): void
    {
        $this->stripeClient->subscriptions->update($request->subscriptionId, $this->uncancel($request->subscriptionId));
    }

    public function updateSubscriptionPlan(UpdateSubscriptionPlanRequest $request): void
    {
        $subscription = $this->stripeClient->subscriptions->retrieve($request->subscriptionId);
        /** @psalm-suppress MixedPropertyFetch, MixedArrayAccess */
        $itemId = $subscription->items->data[0]->id;

        $this->stripeClient->subscriptions->update($request->subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $request->newPriceId]],
            'proration_behavior' => 'create_prorations',
            ...$this->uncancel($subscription),
        ]);
    }

    /**
     * The parameters that undo a pending cancellation. Stripe refuses both at
     * once ("pass in only one"), and each undoes only its own form: a portal
     * cancellation names a moment in cancel_at, ours sets the flag. Clearing
     * cancel_at clears the flag along with it, so the date wins when set.
     *
     * @return array{cancel_at: null}|array{cancel_at_period_end: false}
     */
    private function uncancel(string|\Stripe\Subscription $subscription): array
    {
        if (is_string($subscription)) {
            $subscription = $this->stripeClient->subscriptions->retrieve($subscription);
        }

        return $subscription->cancel_at !== null ? ['cancel_at' => null] : ['cancel_at_period_end' => false];
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
        ] + $this->customTextParams($request->customText));

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

        return new Session($session->id, $url);
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

    /**
     * Refunds against the PaymentIntent, or against the charge when the id is
     * one (older invoices paid without an intent). A refusal Stripe reports
     * up front — more than is left, a disputed charge, nothing captured — is
     * a RefundFailedException; transport errors propagate so callers can retry.
     */
    public function refundPayment(RefundPaymentRequest $request): Refund
    {
        $params = [
            str_starts_with($request->paymentId, 'ch_') ? 'charge' : 'payment_intent' => $request->paymentId,
            'metadata' => $request->metadata,
        ];
        if ($request->amount !== null) {
            $params['amount'] = $request->amount;
        }

        $opts = [];
        if ($request->idempotencyKey !== null) {
            $made = $this->refundMadeUnder($request->paymentId, $request->idempotencyKey);
            if ($made !== null) {
                return $made;
            }

            // Stripe forgets its own idempotency keys after a day, so the key
            // also rides on the refund, where refundMadeUnder() finds it for
            // as long as the refund exists. The header covers two requests
            // racing each other before either refund is listed.
            $params['metadata'][self::REFUND_KEY] = $request->idempotencyKey;
            $opts['idempotency_key'] = $request->idempotencyKey;
        }

        try {
            $refund = $this->stripeClient->refunds->create($params, $opts);
        } catch (IdempotencyException $e) {
            throw new RefundFailedException($request->paymentId, $e->getMessage(), $e);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                throw new PaymentNotFoundException($request->paymentId, $e);
            }

            throw new RefundFailedException($request->paymentId, $e->getMessage(), $e);
        } catch (CardException $e) {
            throw new RefundFailedException($request->paymentId, $e->getMessage(), $e);
        }

        return $this->hydrateRefund($refund, $request->paymentId);
    }

    /**
     * The live refund already made against a payment under a key, if any. A
     * failed or cancelled one returned no money, so it does not count.
     */
    private function refundMadeUnder(string $paymentId, string $key): ?Refund
    {
        try {
            $refunds = $this->stripeClient->refunds->all([
                str_starts_with($paymentId, 'ch_') ? 'charge' : 'payment_intent' => $paymentId,
                'limit' => 100,
            ]);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                throw new PaymentNotFoundException($paymentId, $e);
            }

            throw new RefundFailedException($paymentId, $e->getMessage(), $e);
        }

        foreach ($refunds->data as $refund) {
            if (($refund->metadata[self::REFUND_KEY] ?? null) !== $key) {
                continue;
            }
            if (\in_array((string) $refund->status, ['failed', 'canceled'], true)) {
                continue;
            }

            return $this->hydrateRefund($refund, $paymentId);
        }

        return null;
    }

    private function hydrateRefund(\Stripe\Refund $refund, string $paymentId): Refund
    {
        return new Refund(
            $refund->id,
            $paymentId,
            $this->mapRefundStatus((string) $refund->status),
            $refund->amount,
            $refund->currency,
            isset($refund->failure_reason) ? (string) $refund->failure_reason : null,
        );
    }

    /**
     * @return array{custom_text?: array<string, array{message: string}>}
     */
    private function customTextParams(?CustomText $customText): array
    {
        $params = array_filter([
            'submit' => $customText?->submit,
            'after_submit' => $customText?->afterSubmit,
        ], static fn (?string $message): bool => $message !== null && $message !== '');

        if ($params === []) {
            return [];
        }

        return ['custom_text' => array_map(static fn (string $message): array => ['message' => $message], $params)];
    }

    private function paymentIdForInvoice(string $invoiceId): ?string
    {
        $payments = $this->stripeClient->invoicePayments->all(['invoice' => $invoiceId, 'status' => 'paid']);

        foreach ($payments->data as $invoicePayment) {
            $payment = $invoicePayment->payment;
            foreach ([$payment->payment_intent ?? null, $payment->charge ?? null] as $reference) {
                if (\is_string($reference)) {
                    return $reference;
                }
                if (\is_object($reference) && isset($reference->id) && \is_string($reference->id)) {
                    return $reference->id;
                }
            }
        }

        return null;
    }

    /**
     * The service period the invoice bought: the span of its subscription
     * item lines (earliest start, latest end), falling back to every line.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function servicePeriod(Invoice $invoice): array
    {
        $all = [];
        $items = [];
        foreach ($invoice->lines->data as $line) {
            $span = [(int) $line->period->start, (int) $line->period->end];
            $all[] = $span;
            if (($line->parent->type ?? null) === 'subscription_item_details') {
                $items[] = $span;
            }
        }
        $spans = $items !== [] ? $items : $all;

        $start = $spans === [] ? $invoice->period_start : min(array_column($spans, 0));
        $end = $spans === [] ? $invoice->period_end : max(array_column($spans, 1));

        return [
            new \DateTimeImmutable()->setTimestamp($start),
            new \DateTimeImmutable()->setTimestamp($end),
        ];
    }

    private function mapRefundStatus(string $stripeStatus): RefundStatus
    {
        // Stripe spells it 'canceled'; the canonical status value is 'cancelled'.
        return match ($stripeStatus) {
            'canceled' => RefundStatus::Cancelled,
            default => RefundStatus::tryFrom($stripeStatus) ?? RefundStatus::Pending,
        };
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

    /**
     * A subscription told to stop is not always flagged. Cancelling through the
     * customer portal names the moment instead — `cancel_at` is set and
     * `cancel_at_period_end` stays false — so either one means it will not renew.
     */
    private function hydrateSubscription(\Stripe\Subscription $stripeSubscription): Subscription
    {
        [$periodStart, $periodEnd] = $this->currentPeriod($stripeSubscription);

        $cancelAt = $stripeSubscription->cancel_at;
        $cancelAt = $cancelAt === null ? null : new \DateTimeImmutable()->setTimestamp((int) $cancelAt);

        return new Subscription(
            $stripeSubscription->id,
            $this->mapStatus($stripeSubscription->status),
            $periodStart,
            $periodEnd,
            $stripeSubscription->cancel_at_period_end || $cancelAt !== null,
            $cancelAt,
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
