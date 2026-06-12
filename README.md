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
- `STRIPE_CHECKOUT_SUCCESS_URL`
- `STRIPE_CHECKOUT_CANCEL_URL`

Or configure explicitly in `config/packages/stetodd_stripe_gateway.yaml`:

```yaml
stetodd_stripe_gateway:
    secret_key: '%env(STRIPE_SECRET_KEY)%'
    checkout_success_url: '%env(STRIPE_CHECKOUT_SUCCESS_URL)%'
    checkout_cancel_url: '%env(STRIPE_CHECKOUT_CANCEL_URL)%'
    webhook_path: /webhook/stripe
```

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
