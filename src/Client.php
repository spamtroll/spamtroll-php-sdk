<?php

declare(strict_types=1);

namespace Spamtroll\Sdk;

use SensitiveParameter;
use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\NotConfiguredException;
use Spamtroll\Sdk\Exception\ServerException;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Spamtroll\Sdk\Http\CurlHttpClient;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Internal\ErrorEnvelope;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Request\FeedbackRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;
use Spamtroll\Sdk\Response\Response;
use Throwable;

final class Client
{
    /**
     * Bodies larger than this are treated as unparseable instead of being
     * decoded. A captive portal, a proxy error page, or a misconfigured
     * baseUrl can stream far more than an API response ever contains, and
     * exhausting memory_limit raises an Error no fail-open catch survives.
     */
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * A POST /scan/check that reaches the backend consumes a scan from the
     * caller's daily quota *before* the scan runs, so a 500 has already been
     * paid for. Retrying it three times bills a customer three times for one
     * failed scan, during an outage they did not cause. One retry covers a
     * genuinely transient fault; more is just billing them for our downtime.
     */
    private const POST_MAX_SERVER_ERROR_ATTEMPTS = 2;

    private ClientConfig $config;

    private HttpClientInterface $http;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
        ?ClientConfig $config = null,
        ?HttpClientInterface $http = null,
    ) {
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
     * Scan content and never throw. This is the method integrations should
     * call.
     *
     * Every failure — missing API key, DNS, timeout, 401, 5xx, quota
     * exhausted, garbage body — comes back as a CheckSpamResponse with
     * `wasSkipped() === true`, `getSkipReason()` set and `isSpam() === false`.
     * A blocking verdict can only originate from a successful scan, so an
     * integration written against this method cannot fail closed by
     * forgetting a catch block.
     *
     * Inspect getFailure() to log what went wrong.
     */
    public function checkSpamOrHam(CheckSpamRequest $request): CheckSpamResponse
    {
        try {
            return $this->checkSpam($request);
        } catch (Throwable $e) {
            // Throwable, not SpamtrollException: host HTTP adapters have been
            // observed leaking their platform's own exception types, and an
            // Error from a broken adapter must not block a comment either.
            return CheckSpamResponse::failOpen($e, $this->config->scoreDenominator);
        }
    }

    /**
     * Scan content, throwing on anything that prevents a verdict.
     *
     * Prefer checkSpamOrHam() unless you have a specific reason to handle
     * the exception hierarchy yourself. If you do call this, catch
     * `\Throwable` and let the content through — see docs/ERROR_HANDLING.md.
     *
     * @throws SpamtrollException
     */
    public function checkSpam(CheckSpamRequest $request): CheckSpamResponse
    {
        [$success, $code, $decoded, $error, $errorCode] = $this->dispatch(
            'POST',
            '/scan/check',
            $request->toArray(),
        );

        return new CheckSpamResponse($success, $code, $decoded, $error, $this->config->scoreDenominator, $errorCode);
    }

    /**
     * Send a moderator's correction back to the backend, and never throw.
     *
     * Feedback runs from a moderation UI, not from the scan path, but the
     * same reasoning applies: failing to record a correction must not break
     * the screen the moderator is looking at. Check `success` (and
     * `getErrorCode()`) if you want to tell them it did not land.
     */
    public function trySubmitFeedback(FeedbackRequest $request): Response
    {
        try {
            return $this->submitFeedback($request);
        } catch (Throwable $e) {
            return new Response(
                false,
                0,
                [],
                ErrorEnvelope::truncate($e->getMessage()),
                $e instanceof SpamtrollException ? $e->apiErrorCode : null,
            );
        }
    }

    /**
     * Send a moderator's correction back to the backend.
     *
     * This is the only route by which a wrong verdict is corrected and the
     * model retrained; `CheckSpamResponse::getSubmissionId()` supplies the
     * identifier. Note the limits the backend applies: 20 requests per
     * minute per API key, and 100 corrections per platform per day, both
     * answered with 429.
     * A submission belonging to another platform comes back as 404, not 403.
     *
     * @throws SpamtrollException
     */
    public function submitFeedback(FeedbackRequest $request): Response
    {
        [$success, $code, $decoded, $error, $errorCode] = $this->dispatch(
            'POST',
            '/scan/feedback',
            $request->toArray(),
        );

        return new Response($success, $code, $decoded, $error, $errorCode);
    }

    /**
     * Verify the API key and connectivity. Intended for an admin
     * "Test Connection" button, so the caller wants the exception: an
     * invalid key must be reported, not swallowed.
     *
     * @throws SpamtrollException
     */
    public function testConnection(): Response
    {
        [$success, $code, $decoded, $error, $errorCode] = $this->dispatch('GET', '/scan/status', null);

        return new Response($success, $code, $decoded, $error, $errorCode);
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array{0: bool, 1: int, 2: array<string, mixed>, 3: ?string, 4: ?string}
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
            // JSON_INVALID_UTF8_SUBSTITUTE, not a throw: forum and mail
            // content routinely carries latin-1 leftovers, and refusing to
            // encode them means that content is never scanned at all —
            // a filter bypass costing an attacker one illegal byte.
            $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
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

        if ($method === 'POST') {
            // One key per logical call, reused across retries, so a retried
            // scan can be recognised as the same scan rather than a new one.
            $headers['Idempotency-Key'] = self::idempotencyKey();
        }

        $startedAt = microtime(true);
        $lastException = null;

        for ($attempt = 0; $attempt < $this->config->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $remainingMs = $this->remainingBudgetMs($startedAt);
                if ($remainingMs !== null && $remainingMs <= 0) {
                    break;
                }

                $delayMs = $attempt * $this->config->retryBaseDelayMs;
                if ($remainingMs !== null) {
                    $delayMs = min($delayMs, $remainingMs);
                }
                if ($delayMs > 0) {
                    usleep((int) ($delayMs * 1000));
                }

                $remainingMs = $this->remainingBudgetMs($startedAt);
                if ($remainingMs !== null && $remainingMs <= 0) {
                    break;
                }
            }

            try {
                $http = $this->http->send($method, $url, $headers, $body, $this->attemptTimeout($startedAt));
            } catch (ConnectionException $e) {
                $lastException = $e;
                continue;
            }

            if ($http->statusCode < 100) {
                // Not an HTTP status: the adapter had no response to report.
                // Treat it as a transport failure and retry, rather than as
                // a server saying "no".
                $lastException = ConnectionException::fromMessage(
                    'adapter returned no HTTP status (' . $http->statusCode . ')',
                );
                continue;
            }

            $decoded = self::decodeBody($http->body);

            if ($http->statusCode >= 200 && $http->statusCode < 300) {
                return [true, $http->statusCode, $decoded, null, null];
            }

            if ($http->statusCode === 401) {
                throw AuthenticationException::invalidApiKey();
            }

            $errorMessage = ErrorEnvelope::message($decoded);
            $errorCode = ErrorEnvelope::code($decoded);

            // 402 Payment Required = quota exhausted. Not an exception:
            // running out of scans is a normal operational state, and the
            // plugin fails open on it (CheckSpamResponse::wasSkipped()).
            // 429 is the same deal — the caller backs off, it does not block.
            if ($http->statusCode === 402 || $http->statusCode === 429) {
                return [false, $http->statusCode, $decoded, $errorMessage, $errorCode];
            }

            if ($http->statusCode >= 500) {
                $lastException = new ServerException($errorMessage, $http->statusCode, $errorCode, $decoded);

                if ($method === 'POST' && $attempt + 1 >= self::POST_MAX_SERVER_ERROR_ATTEMPTS) {
                    break;
                }
                continue;
            }

            // Everything else the SDK does not treat specially — other 4xx,
            // and 3xx, which is reachable exactly because redirects are never
            // followed. Surfaced as an unsuccessful Response, never retried:
            // none of these changes answer if asked again.
            return [false, $http->statusCode, $decoded, $errorMessage, $errorCode];
        }

        throw $lastException ?? new SpamtrollException('Request failed after retries', 0);
    }

    /**
     * Milliseconds left of the whole-call budget, or null when unbounded.
     *
     * `timeout × maxRetries` is not a latency bound — the sleeps between
     * attempts are on top of it. A visitor's request is blocked for the
     * entire sum, so the SDK needs a ceiling it can actually promise.
     */
    private function remainingBudgetMs(float $startedAt): ?int
    {
        if ($this->config->totalBudgetMs <= 0) {
            return null;
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        return (int) round($this->config->totalBudgetMs - $elapsedMs);
    }

    private function attemptTimeout(float $startedAt): int
    {
        $remainingMs = $this->remainingBudgetMs($startedAt);
        if ($remainingMs === null) {
            return $this->config->timeout;
        }

        return max(1, min($this->config->timeout, (int) ceil($remainingMs / 1000)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(string $body): array
    {
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function idempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
