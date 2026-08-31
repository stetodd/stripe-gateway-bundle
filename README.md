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
