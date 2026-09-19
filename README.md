# stetodd/stripe-gateway-bundle

Stripe implementation of [`stetodd/payment-gateway`](../payment-gateway) as a Symfony bundle.

## Install

```bash
composer require stetodd/stripe-gateway-bundle
```

Register in `config/bundles.php`:

```php
Stetodd\StripeGatewayBundle\StetoddStripeGatewayBundle::class => ['all' => true],
```

The bundle binds `Stetodd\PaymentGateway\PaymentGatewayInterface` to `StripePaymentGateway`. Override the alias in your own services config to swap implementations (e.g. the Simulator in tests).

## Configuration

Defaults read from env vars — set these and you need no bundle config at all:

- `STRIPE_SECRET_KEY`

Or configure explicitly in `config/packages/stetodd_stripe_gateway.yaml`:

```yaml
stetodd_stripe_gateway:
    secret_key: '%env(STRIPE_SECRET_KEY)%'
    webhook_path: /webhook/stripe
```

Checkout success/cancel URLs are per-request data: pass absolute URLs on
`CreateCheckoutSessionRequest` (`successUrl`, `cancelUrl`), the same way the
customer portal takes its `returnUrl`. The gateway appends
`session_id={CHECKOUT_SESSION_ID}` to the success URL, joining with `&` when the
URL already carries a query string.

## Webhooks

Route Stripe webhooks to the bundled parser in `config/packages/webhook.yaml`:

```yaml
framework:
    webhook:
        routing:
            stripe:
                service: Stetodd\StripeGatewayBundle\Webhook\StripeRequestParser
                secret: '%env(STRIPE_WEBHOOK_SECRET)%'
```

Consume events with a `#[AsRemoteEventConsumer('stripe')]` consumer in your app — that part is application-specific. `Stetodd\StripeGatewayBundle\Webhook\WebhookEvent` hydrates typed Stripe objects from the payload.

## One-off holds (v0.4)

`createPaymentHoldSession()` opens a Checkout Session in `payment` mode with `payment_intent_data[capture_method]=manual`, so the amount is authorised but not captured. The `checkout.session.completed` webhook's session carries the `payment_intent` id; capture it with `capturePayment()` on fulfilment or release it with `cancelPayment()`. Card authorisations last seven days.

## Refunds and checkout text (v0.5)

`refundPayment()` refunds a captured payment, all of it or a partial amount in minor units. Repeated partial refunds are allowed up to the amount captured. It refunds against the PaymentIntent (`pi_…`), or against the charge when given a `ch_…` id. If Stripe refuses up front (more than is left, a disputed or already-refunded charge), it throws `RefundFailedException`. A missing payment throws `PaymentNotFoundException`. Network errors propagate. A refund Stripe accepts can still fail later (`charge.refund.updated`), so read `Refund::$status`.

Give the request an `idempotencyKey` and it pays at most once (v0.7.2). The key is written into the refund's metadata as `idempotency_key`. Before creating anything, the gateway lists the payment's refunds and returns a pending or succeeded one that carries the key, with that refund's amount. The key is also sent as Stripe's `Idempotency-Key` header, which only helps two requests racing each other, because Stripe forgets it after 24 hours.

`findLatestSubscriptionPayment()` returns the subscription's most recent paid invoice as a `SubscriptionPayment`: the payment id to refund, amount paid, when it was paid, and the service period it bought. On Stripe API basil and later, the payment lives on invoice payments rather than `invoice.payment_intent`, so this makes two calls. An invoice paid entirely from credit balance returns `null`, because there's nothing to refund.

`CreateCheckoutSessionRequest` and `CreatePaymentHoldRequest` take an optional `CustomText` (`submit`, `afterSubmit`), sent as Checkout's `custom_text`.

Tests: `composer install && vendor/bin/phpunit`. Stripe is faked at the HTTP client, and nothing leaves the machine.
