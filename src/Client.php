<?php

declare(strict_types=1);

namespace Spamtroll\Sdk;

use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\NotConfiguredException;
use Spamtroll\Sdk\Exception\ServerException;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Spamtroll\Sdk\Http\CurlHttpClient;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;
use Spamtroll\Sdk\Response\Response;
use Spamtroll\Sdk\Response\UsageResponse;

final class Client
{
    private string $apiKey;

    private ClientConfig $config;

    private HttpClientInterface $http;

    public function __construct(
        string $apiKey,
        ?ClientConfig $config = null,
        ?HttpClientInterface $http = null,
    ) {
        $this->apiKey = $apiKey;
        $this->config = $config ?? new ClientConfig();
        $this->http = $http ?? new CurlHttpClient();
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getConfig(): ClientConfig
    {
        return $this->config;
    }

    /**
     * @throws SpamtrollException
     */
    public function testConnection(): Response
    {
        [$success, $code, $decoded, $error] = $this->dispatch('GET', '/scan/status', null);
        return new Response($success, $code, $decoded, $error);
    }

    /**
     * @throws SpamtrollException
     */
    public function getAccountUsage(): UsageResponse
    {
        [$success, $code, $decoded, $error] = $this->dispatch('GET', '/account/usage', null);
        return new UsageResponse($success, $code, $decoded, $error);
    }

    /**
     * @throws SpamtrollException
     */
    public function checkSpam(CheckSpamRequest $request): CheckSpamResponse
    {
        [$success, $code, $decoded, $error] = $this->dispatch('POST', '/scan/check', $request->toArray());
        return new CheckSpamResponse($success, $code, $decoded, $error, $this->config->scoreDenominator);
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array{0: bool, 1: int, 2: array<string, mixed>, 3: ?string}
     *
     * @throws SpamtrollException
     */
    private function dispatch(string $method, string $endpoint, ?array $data): array
    {
        if (!$this->isConfigured()) {
            throw NotConfiguredException::create();
        }

        $url = $this->config->baseUrl . $endpoint;

        $body = null;
        if ($method === 'POST' && $data !== null) {
            $encoded = json_encode($data);
            if ($encoded === false) {
                throw new SpamtrollException('Failed to encode request data as JSON', 0);
            }
            $body = $encoded;
        }

        $headers = [
            'X-API-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent ?? 'spamtroll-php-sdk/' . Version::VERSION,
        ];

        $lastException = null;

        for ($attempt = 0; $attempt < $this->config->maxRetries; $attempt++) {
            if ($attempt > 0 && $this->config->retryBaseDelayMs > 0) {
                // Exponential-ish: attempt 1 → base, attempt 2 → 2× base, etc.
                usleep($attempt * $this->config->retryBaseDelayMs * 1000);
            }

            try {
                $http = $this->http->send($method, $url, $headers, $body, $this->config->timeout);
            } catch (ConnectionException $e) {
                $lastException = $e;
                continue;
            }

            $decoded = json_decode($http->body, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            if ($http->statusCode >= 200 && $http->statusCode < 300) {
                return [true, $http->statusCode, $decoded, null];
            }

            if ($http->statusCode === 401) {
                throw AuthenticationException::invalidApiKey();
            }

            $errorMessage = $this->extractError($decoded);

            if ($http->statusCode === 429) {
                return [false, $http->statusCode, $decoded, $errorMessage];
            }

            if ($http->statusCode >= 500) {
                $lastException = new ServerException($errorMessage, $http->statusCode, null, $decoded);
                continue;
            }

            // Other 4xx — surface as an unsuccessful Response, no retry.
            return [false, $http->statusCode, $decoded, $errorMessage];
        }

        throw $lastException ?? new SpamtrollException('Request failed after retries', 0);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractError(array $decoded): string
    {
        if (isset($decoded['error'])) {
            return $this->stringify($decoded['error']);
        }
        if (isset($decoded['message'])) {
            return $this->stringify($decoded['message']);
        }
        return 'API error';
    }

    /**
     * @param mixed $value
     */
    private function stringify($value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value);
        return $encoded === false ? 'API error' : $encoded;
    }
}
