<?php

declare(strict_types=1);

namespace Stetodd\StripeGatewayBundle\Tests;

use Stripe\HttpClient\ClientInterface;

/**
 * Answers stripe-php's requests from canned JSON keyed by method and path, and
 * remembers the parameters each request was sent with.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<string, array{int, string}> */
    private array $responses = [];

    /** @var array<string, array<array-key, mixed>> */
    private array $params = [];

    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var list<string> every request made, as "method path", oldest first */
    private array $requests = [];

    private ?string $lastKey = null;

    /** @param array<string, mixed> $body */
    public function respond(string $method, string $path, array $body): void
    {
        $this->responses[$method.' '.$path] = [200, json_encode($body, \JSON_THROW_ON_ERROR)];
    }

    public function respondError(string $method, string $path, int $status, string $code, string $message): void
    {
        $this->responses[$method.' '.$path] = [$status, json_encode(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message]], \JSON_THROW_ON_ERROR)];
    }

    /** @return array<array-key, mixed> */
    public function lastParams(): array
    {
        return $this->lastKey === null ? [] : $this->params[$this->lastKey];
    }

    /** @return array<array-key, mixed> */
    public function params(string $method, string $path): array
    {
        return $this->params[$method.' '.$path] ?? [];
    }

    /** @return list<string> */
    public function headers(string $method, string $path): array
    {
        return $this->headers[$method.' '.$path] ?? [];
    }

    /** @return list<string> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, \PHP_URL_PATH);
        $key = $method.' '.$path;
        $this->requests[] = $key;
        if (!isset($this->responses[$key])) {
            throw new \LogicException(sprintf('No canned Stripe response for %s', $key));
        }

        $this->params[$key] = $params;
        $this->headers[$key] = array_values($headers);
        $this->lastKey = $key;
        [$status, $body] = $this->responses[$key];

        return [$body, $status, []];
    }
}
