<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Webhook;

use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class StripeRequestParser extends AbstractRequestParser
{
    public function __construct(
        private SerializerInterface $serializer,
        private string $webhookPath = '/webhook/stripe',
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new PathRequestMatcher($this->webhookPath),
            new MethodRequestMatcher(Request::METHOD_POST),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): ?RemoteEvent
    {
        $payload = $request->getContent();

        $signature = $request->headers->get('stripe-signature', '');

        try {
            WebhookSignature::verifyHeader($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            throw new RejectWebhookException(406, 'Invalid Stripe webhook signature.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RejectWebhookException(400, 'Invalid Stripe webhook payload. Invalid json.');
        }

        $event = $this->serializer->deserialize($payload, WebhookEvent::class, 'json');

        if (!isset($event->type, $event->id)) {
            throw new RejectWebhookException(400, 'Invalid Stripe webhook payload.');
        }

        return new RemoteEvent($event->type, $event->id, $data);
    }
}
