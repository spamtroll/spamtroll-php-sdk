<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Http;

use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;

/**
 * Thin HTTP client contract used by the SDK.
 *
 * Implementations MUST translate network-level failures into
 * {@see ConnectionException} (or {@see TimeoutException} for timeouts).
 * HTTP error responses (4xx/5xx) are NOT errors at this layer — return them
 * via {@see HttpResponse} and let {@see \Spamtroll\Sdk\Client} decide what
 * to do.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException On connection failure.
     * @throws TimeoutException On request timeout.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
    ): HttpResponse;
}
