<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Tests;

use PHPUnit\Framework\TestCase;
use Stetodd\PaymentGateway\Exception\Payment\PaymentNotFoundException;
use Stetodd\PaymentGateway\Model\Balance\BalanceTransactionStatus;
use Stetodd\PaymentGateway\Model\Balance\BalanceTransactionType;
use Stetodd\PaymentGateway\Model\Balance\FeeType;
use Stetodd\PaymentGateway\Model\Payment\PaymentStatus;
use Stetodd\PaymentGateway\Model\Payment\RefundStatus;
use Stetodd\PaymentGateway\Model\Payout\PayoutStatus;
use Stetodd\PaymentGateway\Model\Request\Balance\ListBalanceTransactionsRequest;
use Stetodd\PaymentGateway\Model\Request\Listing\ListSinceRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\CapturePaymentRequest;
use Stetodd\PaymentGateway\Model\Request\Payment\GetPaymentRequest;
use Stetodd\StripeGatewayBundle\StripePaymentGateway;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

final class StripeListingTest extends TestCase
{
    private const int SINCE = 1_789_000_000;

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

    public function test_paid_invoices_are_read_since_a_date_with_the_payment_that_paid_each(): void
    {
        $this->http->respond('get', '/v1/invoices', self::page('/v1/invoices', [[
            'id' => 'in_1', 'object' => 'invoice', 'amount_paid' => 2900, 'currency' => 'gbp', 'created' => self::SINCE + 10,
            'billing_reason' => 'subscription_cycle', 'customer' => 'cus_1', 'metadata' => ['plan' => 'portfolio'],
            'status_transitions' => ['paid_at' => self::SINCE + 60],
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
        ]], true));
        $this->http->respond('get', '/v1/invoice_payments', self::page('/v1/invoice_payments', [[
            'id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_1'],
        ]]));

        $page = $this->gateway->listPaidInvoices(new ListSinceRequest(self::since(), cursor: 'in_9', limit: 50));

        $invoice = $page->invoices[0] ?? null;
        self::assertNotNull($invoice);
        self::assertSame(['in_1', 2900, 'gbp', 'pi_1', 'sub_1', 'cus_1', 'subscription_cycle'], [$invoice->id, $invoice->amountPaid, $invoice->currency, $invoice->paymentId, $invoice->subscriptionId, $invoice->customerId, $invoice->billingReason]);
        self::assertSame([self::SINCE + 60, self::SINCE + 10], [$invoice->paidAt->getTimestamp(), $invoice->createdAt->getTimestamp()]);
        self::assertSame(['plan' => 'portfolio'], $invoice->metadata);
        self::assertSame('in_1', $page->nextCursor);
        self::assertSame(['status' => 'paid', 'limit' => 50, 'created' => ['gte' => self::SINCE], 'starting_after' => 'in_9'], $this->http->params('get', '/v1/invoices'));
    }

    public function test_only_succeeded_payments_are_listed_and_the_cursor_follows_the_vendor_page(): void
    {
        $this->http->respond('get', '/v1/payment_intents', self::page('/v1/payment_intents', [
            ['id' => 'pi_ok', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 8900, 'amount_received' => 8900, 'currency' => 'gbp', 'created' => self::SINCE + 5, 'metadata' => ['service_order_id' => 'abc'], 'description' => 'EPC'],
            ['id' => 'pi_held', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 100, 'currency' => 'gbp', 'created' => self::SINCE + 4, 'metadata' => []],
        ], true));

        $page = $this->gateway->listSucceededPayments(new ListSinceRequest(self::since(), self::since()->modify('+1 day')));

        self::assertCount(1, $page->payments);
        $payment = $page->payments[0];
        self::assertSame(['pi_ok', PaymentStatus::Succeeded, 8900, ['service_order_id' => 'abc'], 'EPC', self::SINCE + 5], [$payment->id, $payment->status, $payment->amountCaptured, $payment->metadata, $payment->description, $payment->createdAt?->getTimestamp()]);
        self::assertSame('pi_held', $page->nextCursor);
        self::assertSame(['limit' => 100, 'created' => ['gte' => self::SINCE, 'lt' => self::SINCE + 86400]], $this->http->params('get', '/v1/payment_intents'));
    }

    public function test_refunds_are_listed_against_their_payment_in_every_status(): void
    {
        $this->http->respond('get', '/v1/refunds', self::page('/v1/refunds', [
            ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'gbp', 'created' => self::SINCE + 9, 'payment_intent' => 'pi_1', 'charge' => 'ch_1', 'metadata' => ['kind' => 'goodwill']],
            ['id' => 're_2', 'object' => 'refund', 'status' => 'canceled', 'amount' => 100, 'currency' => 'gbp', 'created' => self::SINCE + 8, 'payment_intent' => null, 'charge' => 'ch_2', 'metadata' => []],
        ]));

        $refunds = $this->gateway->listRefunds(new ListSinceRequest(self::since()))->refunds;

        self::assertSame([['re_1', 'pi_1', RefundStatus::Succeeded, 500], ['re_2', 'ch_2', RefundStatus::Cancelled, 100]], array_map(static fn ($r): array => [$r->id, $r->paymentId, $r->status, $r->amount], $refunds));
        self::assertSame(['kind' => 'goodwill'], $refunds[0]->metadata);
        self::assertSame(self::SINCE + 9, $refunds[0]->createdAt?->getTimestamp());
    }

    public function test_balance_rows_of_one_kind_carry_the_fee_and_the_payment_behind_them(): void
    {
        $this->http->respond('get', '/v1/balance_transactions', self::page('/v1/balance_transactions', [[
            'id' => 'txn_1', 'object' => 'balance_transaction', 'type' => 'charge', 'reporting_category' => 'charge',
            'amount' => 8900, 'fee' => 154, 'net' => 8746, 'currency' => 'gbp', 'created' => self::SINCE + 3, 'available_on' => self::SINCE + 300000,
            'status' => 'pending', 'description' => null, 'exchange_rate' => null,
            'source' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1'],
            'fee_details' => [['amount' => 154, 'currency' => 'gbp', 'type' => 'stripe_fee', 'description' => 'Stripe processing fees', 'application' => null]],
        ]]));

        $row = $this->gateway->listBalanceTransactions(new ListBalanceTransactionsRequest(self::since(), type: BalanceTransactionType::Charge))->transactions[0] ?? null;

        self::assertNotNull($row);
        self::assertSame(['txn_1', BalanceTransactionType::Charge, 'charge', 8900, 154, 8746, 'ch_1', 'pi_1', BalanceTransactionStatus::Pending], [$row->id, $row->type, $row->rawType, $row->amount, $row->fee, $row->net, $row->sourceId, $row->paymentId, $row->status]);
        self::assertSame([FeeType::StripeFee, 154], [$row->feeDetails[0]->type ?? null, $row->feeDetails[0]->amount ?? null]);
        self::assertSame(['limit' => 100, 'created' => ['gte' => self::SINCE], 'type' => 'charge', 'expand' => ['data.source']], $this->http->params('get', '/v1/balance_transactions'));
    }

    public function test_a_kind_the_package_does_not_know_is_kept_in_the_vendors_words(): void
    {
        $this->http->respond('get', '/v1/balance_transactions', self::page('/v1/balance_transactions', [[
            'id' => 'txn_2', 'object' => 'balance_transaction', 'type' => 'climate_order_purchase', 'reporting_category' => 'climate_order',
            'amount' => -50, 'fee' => 0, 'net' => -50, 'currency' => 'gbp', 'created' => self::SINCE + 3, 'available_on' => self::SINCE + 3,
            'status' => 'available', 'source' => null, 'fee_details' => [],
        ]]));

        $row = $this->gateway->listBalanceTransactions(new ListBalanceTransactionsRequest(self::since()))->transactions[0] ?? null;

        self::assertSame([BalanceTransactionType::Other, 'climate_order_purchase', null, null], [$row?->type, $row?->rawType, $row?->sourceId, $row?->paymentId]);
    }

    public function test_a_payments_balance_row_is_read_through_its_latest_charge(): void
    {
        $this->http->respond('get', '/v1/payment_intents/pi_1', [
            'id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 2900, 'currency' => 'gbp',
            'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_1', 'balance_transaction' => [
                'id' => 'txn_9', 'object' => 'balance_transaction', 'type' => 'charge', 'reporting_category' => 'charge', 'amount' => 2900, 'fee' => 64, 'net' => 2836,
                'currency' => 'gbp', 'created' => self::SINCE, 'available_on' => self::SINCE, 'status' => 'available', 'source' => 'ch_1', 'fee_details' => [],
            ]],
        ]);

        $row = $this->gateway->findPaymentBalanceTransaction(new GetPaymentRequest('pi_1'));

        self::assertSame(['txn_9', 64, 'ch_1', 'pi_1'], [$row?->id, $row?->fee, $row?->sourceId, $row?->paymentId]);
        self::assertSame(['expand' => ['latest_charge.balance_transaction']], $this->http->params('get', '/v1/payment_intents/pi_1'));
    }

    public function test_a_hold_not_yet_captured_has_no_balance_row(): void
    {
        $this->http->respond('get', '/v1/payment_intents/pi_1', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 2900, 'currency' => 'gbp',
            'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'balance_transaction' => null]]);

        self::assertNull($this->gateway->findPaymentBalanceTransaction(new GetPaymentRequest('pi_1')));
    }

    public function test_a_charge_ids_balance_row_is_read_off_the_charge(): void
    {
        $this->http->respond('get', '/v1/charges/ch_1', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => null, 'balance_transaction' => [
            'id' => 'txn_8', 'object' => 'balance_transaction', 'type' => 'charge', 'reporting_category' => 'charge', 'amount' => 2900, 'fee' => 64, 'net' => 2836,
            'currency' => 'gbp', 'created' => self::SINCE, 'available_on' => self::SINCE, 'status' => 'available', 'source' => 'ch_1', 'fee_details' => [],
        ]]);

        self::assertSame('txn_8', $this->gateway->findPaymentBalanceTransaction(new GetPaymentRequest('ch_1'))?->id);
    }

    public function test_a_missing_payment_has_no_balance_row_to_find(): void
    {
        $this->http->respondError('get', '/v1/payment_intents/pi_missing', 404, 'resource_missing', 'No such payment_intent');

        $this->expectException(PaymentNotFoundException::class);
        $this->gateway->findPaymentBalanceTransaction(new GetPaymentRequest('pi_missing'));
    }

    public function test_a_captured_payment_names_the_card_off_its_latest_charge(): void
    {
        $this->http->respond('post', '/v1/payment_intents/pi_1/capture', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 8900, 'amount_received' => 8900, 'currency' => 'gbp',
            'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]]]);

        $payment = $this->gateway->capturePayment(new CapturePaymentRequest('pi_1'));

        self::assertSame(['visa', '4242', 8900], [$payment->cardBrand, $payment->cardLast4, $payment->amountCaptured]);
        self::assertSame(['expand' => ['latest_charge']], $this->http->params('post', '/v1/payment_intents/pi_1/capture'));
    }

    public function test_a_payment_read_back_names_its_card_and_one_not_expanded_names_none(): void
    {
        $this->http->respond('get', '/v1/payment_intents/pi_1', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 8900, 'currency' => 'gbp',
            'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'amex', 'last4' => '0005']]]]);
        $this->http->respond('get', '/v1/payment_intents/pi_2', ['id' => 'pi_2', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 8900, 'currency' => 'gbp', 'latest_charge' => 'ch_2']);

        $expanded = $this->gateway->getPayment(new GetPaymentRequest('pi_1'));
        $bare = $this->gateway->getPayment(new GetPaymentRequest('pi_2'));

        self::assertSame(['amex', '0005'], [$expanded->cardBrand, $expanded->cardLast4]);
        self::assertSame([null, null], [$bare->cardBrand, $bare->cardLast4]);
    }

    public function test_payouts_to_our_bank_are_listed(): void
    {
        $this->http->respond('get', '/v1/payouts', self::page('/v1/payouts', [[
            'id' => 'po_1', 'object' => 'payout', 'status' => 'canceled', 'amount' => 12000, 'currency' => 'gbp', 'created' => self::SINCE + 1,
            'arrival_date' => self::SINCE + 172800, 'automatic' => true, 'balance_transaction' => 'txn_po', 'failure_code' => null, 'description' => 'STRIPE PAYOUT',
        ]]));

        $payout = $this->gateway->listPayouts(new ListSinceRequest(self::since()))->payouts[0] ?? null;

        self::assertSame(['po_1', PayoutStatus::Cancelled, 12000, 'txn_po', true, self::SINCE + 172800], [$payout?->id, $payout?->status, $payout?->amount, $payout?->balanceTransactionId, $payout?->automatic, $payout?->arrivalDate->getTimestamp()]);
    }

    private static function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable()->setTimestamp(self::SINCE);
    }

    /**
     * @param list<array<string, mixed>> $data
     *
     * @return array<string, mixed>
     */
    private static function page(string $url, array $data, bool $hasMore = false): array
    {
        return ['object' => 'list', 'url' => $url, 'has_more' => $hasMore, 'data' => $data];
    }
}
