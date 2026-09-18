<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Tests;

use PHPUnit\Framework\TestCase;
use Stetodd\PaymentGateway\Exception\Payment\PaymentNotFoundException;
use Stetodd\PaymentGateway\Exception\Payment\RefundFailedException;
use Stetodd\PaymentGateway\Exception\Subscription\SubscriptionNotFoundException;
use Stetodd\PaymentGateway\Model\Checkout\CheckoutStatus;
use Stetodd\PaymentGateway\Model\Checkout\CustomText;
use Stetodd\PaymentGateway\Model\Checkout\LineItem;
use Stetodd\PaymentGateway\Model\Checkout\LineItemCollection;
use Stetodd\PaymentGateway\Model\Customer;
use Stetodd\PaymentGateway\Model\Payment\RefundStatus;
use Stetodd\PaymentGateway\Model\Request\Checkout\CreateCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Checkout\GetCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CreatePaymentHoldRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\RefundPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\CancelSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\GetSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\ReactivateSubscriptionRequest;
use Stetodd\PaymentGateway\Model\Subscription\Status;
use Stetodd\StripeGatewayBundle\StripePaymentGateway;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

final class StripePaymentGatewayTest extends TestCase
{
    private FakeStripeHttpClient $http;
    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->http = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->http);
        $this->gateway = new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_fake', 'max_network_retries' => 0]));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function test_a_partial_refund_is_sent_against_the_payment_intent(): void
    {
        $this->http->respond('post', '/v1/refunds', ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'gbp', 'failure_reason' => null]);

        $refund = $this->gateway->refundPayment(new RefundPaymentRequest('pi_1', 1000, ['reason' => 'statutory']));

        self::assertSame('re_1', $refund->id);
        self::assertSame('pi_1', $refund->paymentId);
        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame(1000, $refund->amount);
        self::assertSame(['payment_intent' => 'pi_1', 'metadata' => ['reason' => 'statutory'], 'amount' => 1000], $this->http->lastParams());
    }

    public function test_a_charge_id_is_refunded_as_a_charge_and_a_full_refund_sends_no_amount(): void
    {
        $this->http->respond('post', '/v1/refunds', ['id' => 're_2', 'object' => 'refund', 'status' => 'pending', 'amount' => 1500, 'currency' => 'gbp']);

        $refund = $this->gateway->refundPayment(new RefundPaymentRequest('ch_1'));

        self::assertSame(RefundStatus::Pending, $refund->status);
        self::assertSame(['charge' => 'ch_1', 'metadata' => []], $this->http->lastParams());
    }

    public function test_a_refund_stripe_refuses_is_a_refund_failure(): void
    {
        $this->http->respondError('post', '/v1/refunds', 400, 'charge_already_refunded', 'Charge ch_1 has already been refunded.');

        $this->expectException(RefundFailedException::class);
        $this->gateway->refundPayment(new RefundPaymentRequest('pi_1'));
    }

    public function test_refunding_a_missing_payment_is_not_found(): void
    {
        $this->http->respondError('post', '/v1/refunds', 404, 'resource_missing', "No such payment_intent: 'pi_missing'");

        $this->expectException(PaymentNotFoundException::class);
        $this->gateway->refundPayment(new RefundPaymentRequest('pi_missing'));
    }

    public function test_the_latest_subscription_payment_reads_the_paid_invoice_and_its_payment(): void
    {
        $this->http->respond('get', '/v1/invoices', ['object' => 'list', 'data' => [[
            'id' => 'in_1',
            'object' => 'invoice',
            'amount_paid' => 4900,
            'currency' => 'gbp',
            'created' => 1_789_000_000,
            'period_start' => 1_789_000_000,
            'period_end' => 1_789_000_000,
            'status_transitions' => ['paid_at' => 1_789_000_100],
            'lines' => ['object' => 'list', 'data' => [
                ['id' => 'il_1', 'object' => 'line_item', 'period' => ['start' => 1_789_000_000, 'end' => 1_804_811_200], 'parent' => ['type' => 'subscription_item_details']],
            ]],
        ]]]);
        $this->http->respond('get', '/v1/invoice_payments', ['object' => 'list', 'data' => [[
            'id' => 'inpay_1',
            'object' => 'invoice_payment',
            'status' => 'paid',
            'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_paid'],
        ]]]);

        $payment = $this->gateway->findLatestSubscriptionPayment(new GetSubscriptionRequest('sub_1'));

        self::assertNotNull($payment);
        self::assertSame('in_1', $payment->invoiceId);
        self::assertSame('pi_paid', $payment->paymentId);
        self::assertSame(4900, $payment->amountPaid);
        self::assertSame(1_789_000_100, $payment->paidAt->getTimestamp());
        self::assertSame(1_789_000_000, $payment->periodStart->getTimestamp());
        self::assertSame(1_804_811_200, $payment->periodEnd->getTimestamp());
        self::assertSame(['subscription' => 'sub_1', 'status' => 'paid', 'limit' => 1], $this->http->params('get', '/v1/invoices'));
        self::assertSame(['invoice' => 'in_1', 'status' => 'paid'], $this->http->params('get', '/v1/invoice_payments'));
    }

    public function test_a_subscription_with_no_paid_invoice_has_no_latest_payment(): void
    {
        $this->http->respond('get', '/v1/invoices', ['object' => 'list', 'data' => []]);

        self::assertNull($this->gateway->findLatestSubscriptionPayment(new GetSubscriptionRequest('sub_1')));
    }

    public function test_an_unknown_subscription_is_not_found(): void
    {
        $this->http->respondError('get', '/v1/invoices', 404, 'resource_missing', "No such subscription: 'sub_missing'");

        $this->expectException(SubscriptionNotFoundException::class);
        $this->gateway->findLatestSubscriptionPayment(new GetSubscriptionRequest('sub_missing'));
    }

    public function test_an_immediate_cancel_cancels_the_subscription_now(): void
    {
        $this->http->respond('delete', '/v1/subscriptions/sub_1', $this->subscription('canceled', false));

        $subscription = $this->gateway->cancelSubscription(new CancelSubscriptionRequest('sub_1'));

        self::assertSame(Status::Cancelled, $subscription->status);
    }

    public function test_a_cancel_at_period_end_only_flags_the_subscription(): void
    {
        $this->http->respond('post', '/v1/subscriptions/sub_1', $this->subscription('active', true));
        $request = new CancelSubscriptionRequest('sub_1');
        $request->cancelAtPeriodEnd();

        $subscription = $this->gateway->cancelSubscription($request);

        self::assertTrue($subscription->cancelAtPeriodEnd);
        self::assertSame(['cancel_at_period_end' => 'true'], $this->http->lastParams(), 'stripe-php sends booleans as strings');
    }

    public function test_a_subscription_cancelled_in_the_portal_reads_as_ending(): void
    {
        // The portal names the moment instead of setting the flag.
        $this->http->respond('get', '/v1/subscriptions/sub_1', $this->subscription('active', false, 1_791_600_000));

        $subscription = $this->gateway->getSubscription(new GetSubscriptionRequest('sub_1'));

        self::assertTrue($subscription->cancelAtPeriodEnd);
        self::assertEquals(new \DateTimeImmutable()->setTimestamp(1_791_600_000), $subscription->cancelAt);
    }

    public function test_a_subscription_with_neither_flag_nor_date_renews(): void
    {
        $this->http->respond('get', '/v1/subscriptions/sub_1', $this->subscription('active', false));

        $subscription = $this->gateway->getSubscription(new GetSubscriptionRequest('sub_1'));

        self::assertFalse($subscription->cancelAtPeriodEnd);
        self::assertNull($subscription->cancelAt);
    }

    public function test_reactivating_clears_the_named_cancellation_date(): void
    {
        $this->http->respond('get', '/v1/subscriptions/sub_1', $this->subscription('active', false, 1_791_600_000));
        $this->http->respond('post', '/v1/subscriptions/sub_1', $this->subscription('active', false));

        $this->gateway->reactivateSubscription(new ReactivateSubscriptionRequest('sub_1'));

        // Stripe refuses cancel_at alongside cancel_at_period_end, and takes an
        // empty string as the null that unsets the field.
        self::assertSame(['cancel_at' => ''], $this->http->params('post', '/v1/subscriptions/sub_1'));
    }

    public function test_reactivating_a_subscription_cancelled_by_the_flag_clears_the_flag(): void
    {
        $this->http->respond('get', '/v1/subscriptions/sub_1', $this->subscription('active', true));
        $this->http->respond('post', '/v1/subscriptions/sub_1', $this->subscription('active', false));

        $this->gateway->reactivateSubscription(new ReactivateSubscriptionRequest('sub_1'));

        self::assertSame(['cancel_at_period_end' => 'false'], $this->http->params('post', '/v1/subscriptions/sub_1'));
    }

    public function test_cancelling_a_missing_subscription_is_not_found(): void
    {
        $this->http->respondError('delete', '/v1/subscriptions/sub_missing', 404, 'resource_missing', "No such subscription: 'sub_missing'");

        $this->expectException(SubscriptionNotFoundException::class);
        $this->gateway->cancelSubscription(new CancelSubscriptionRequest('sub_missing'));
    }

    public function test_custom_text_reaches_the_subscription_checkout(): void
    {
        $this->http->respond('post', '/v1/checkout/sessions', ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_1']);
        $lineItems = new LineItemCollection();
        $lineItems->add(LineItem::fromPriceId('price_1', 1));

        $this->gateway->createCheckoutSession(new CreateCheckoutSessionRequest(
            new Customer('cus_1', []),
            $lineItems,
            'https://app.test/ok',
            'https://app.test/cancel',
            [],
            new CustomText(submit: 'You can cancel within 14 days.'),
        ));

        self::assertSame(['submit' => ['message' => 'You can cancel within 14 days.']], $this->http->lastParams()['custom_text'] ?? null);
    }

    public function test_a_paid_checkout_reads_back_with_its_subscription_and_metadata(): void
    {
        $this->http->respond('get', '/v1/checkout/sessions/cs_1', [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'subscription' => 'sub_1',
            'customer' => 'cus_1',
            'amount_total' => 4900,
            'metadata' => ['plan' => 'mover'],
        ]);

        $session = $this->gateway->findCheckoutSession(new GetCheckoutSessionRequest('cs_1'));

        self::assertNotNull($session);
        self::assertSame(CheckoutStatus::Complete, $session->status);
        self::assertTrue($session->paid);
        self::assertSame('sub_1', $session->subscriptionId);
        self::assertSame('cus_1', $session->customerId);
        self::assertSame(4900, $session->amountTotal);
        self::assertSame(['plan' => 'mover'], $session->metadata);
    }

    public function test_an_abandoned_checkout_reads_back_expired_and_unpaid(): void
    {
        $this->http->respond('get', '/v1/checkout/sessions/cs_1', [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => 'expired',
            'payment_status' => 'unpaid',
        ]);

        $session = $this->gateway->findCheckoutSession(new GetCheckoutSessionRequest('cs_1'));

        self::assertNotNull($session);
        self::assertSame(CheckoutStatus::Expired, $session->status);
        self::assertFalse($session->paid);
        self::assertNull($session->subscriptionId);
    }

    public function test_a_checkout_that_needed_no_payment_counts_as_settled(): void
    {
        $this->http->respond('get', '/v1/checkout/sessions/cs_1', [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'no_payment_required',
            'subscription' => 'sub_1',
        ]);

        $session = $this->gateway->findCheckoutSession(new GetCheckoutSessionRequest('cs_1'));

        self::assertNotNull($session);
        self::assertTrue($session->paid);
    }

    public function test_a_checkout_stripe_has_never_heard_of_is_not_found(): void
    {
        $this->http->respondError('get', '/v1/checkout/sessions/cs_missing', 404, 'resource_missing', "No such checkout.session: 'cs_missing'");

        self::assertNull($this->gateway->findCheckoutSession(new GetCheckoutSessionRequest('cs_missing')));
    }

    public function test_a_hold_checkout_without_custom_text_sends_none(): void
    {
        $this->http->respond('post', '/v1/checkout/sessions', ['id' => 'cs_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_2']);

        $this->gateway->createPaymentHoldSession(new CreatePaymentHoldRequest(
            new Customer('cus_1', []),
            1800,
            'gbp',
            'Title register',
            'https://app.test/ok',
            'https://app.test/cancel',
        ));

        self::assertArrayNotHasKey('custom_text', $this->http->lastParams());
    }

    /** @return array<string, mixed> */
    private function subscription(string $status, bool $cancelAtPeriodEnd, ?int $cancelAt = null): array
    {
        return [
            'id' => 'sub_1',
            'object' => 'subscription',
            'status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'cancel_at' => $cancelAt,
            'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'current_period_start' => 1_789_000_000, 'current_period_end' => 1_791_600_000]]],
        ];
    }
}
