<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Tests;

use PHPUnit\Framework\TestCase;
use Stetodd\PaymentGateway\Exception\Payment\PaymentNotFoundException;
use Stetodd\PaymentGateway\Exception\Payment\RefundFailedException;
use Stetodd\PaymentGateway\Exception\Subscription\SubscriptionNotFoundException;
use Stetodd\PaymentGateway\Model\Checkout\CustomText;
use Stetodd\PaymentGateway\Model\Checkout\LineItem;
use Stetodd\PaymentGateway\Model\Checkout\LineItemCollection;
use Stetodd\PaymentGateway\Model\Customer;
use Stetodd\PaymentGateway\Model\Payment\RefundStatus;
use Stetodd\PaymentGateway\Model\Request\Checkout\CreateCheckoutSessionRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CreatePaymentHoldRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\RefundPaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Subscription\GetSubscriptionRequest;
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
}
