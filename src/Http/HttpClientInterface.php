<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Http;

use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;

/**
 * Thin HTTP client contract used by the SDK.
 *
 * Three rules every implementation MUST follow:
 *
 * 1. **Translate transport failures into exceptions.** DNS failure, refused
 *    connection, TLS error => {@see ConnectionException}; an exceeded timeout
 *    => {@see TimeoutException}. Nothing outside the
 *    {@see \Spamtroll\Sdk\Exception\SpamtrollException} hierarchy may leave
 *    send(): wrap your stack's own exception types, including the ones you
 *    did not expect.
 * 2. **Do NOT translate HTTP error responses into exceptions.** 4xx and 5xx
 *    are successful round-trips at this layer — return them via
 *    {@see HttpResponse} and let {@see \Spamtroll\Sdk\Client} decide.
 *    Also never report "no response" as status 0; throw instead.
 * 3. **Never follow redirects.** The API does not redirect. A redirect
 *    carries the `X-API-Key` header to whatever host the Location points at,
 *    and the key is long-lived, so a single misdirected hop leaks it
 *    permanently. Disable redirects explicitly — most HTTP stacks follow
 *    them by default.
 *
 * Implementations should also bound the response size they buffer; an
 * out-of-memory Error cannot be caught and takes the host request with it.
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
