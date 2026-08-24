<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Http;

use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;

/**
 * Zero-dependency HTTP client built on ext-curl.
 *
 * Default for {@see \Spamtroll\Sdk\Client} when no adapter is injected.
 * Host integrations (WordPress, IPS) ship their own adapter to respect
 * platform-level HTTP filters (proxy, SSL overrides, request inspection).
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * Hard ceiling on the buffered response. An API response is a few
     * kilobytes; anything approaching this is a proxy error page, a captive
     * portal, or a wrong baseUrl. Running out of memory raises an Error,
     * which no fail-open catch can recover from, so the transfer is aborted
     * instead.
     */
    public const MAX_RESPONSE_BYTES = 1048576;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw ConnectionException::fromMessage('curl_init() failed');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, self::MAX_RESPONSE_BYTES);

        // Defence in depth against a baseUrl that is not what it looks like:
        // even with the scheme validated in ClientConfig, curl must never be
        // allowed to speak file://, gopher:// or friends.
        self::restrictProtocols($ch);

        if ($headers !== []) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                throw TimeoutException::afterSeconds($timeout);
            }
            throw ConnectionException::fromMessage($error !== '' ? $error : 'cURL error ' . $errno);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($statusCode < 100) {
            // curl reported success without an HTTP status line — a non-HTTP
            // protocol answered. Report it as a transport failure so the
            // caller never mistakes it for a verdict.
            throw ConnectionException::fromMessage('no HTTP response received from ' . self::hostOf($url));
        }

        $rawString = (string) $raw;
        // headerSize comes straight from curl_getinfo() so it is always
        // within bounds (or 0 when no response body was read).
        $rawHeaders = substr($rawString, 0, $headerSize);
        $rawBody = substr($rawString, $headerSize);

        return new HttpResponse($statusCode, $rawBody, self::parseHeaders($rawHeaders));
    }

    /**
     * @param \CurlHandle $ch
     */
    private static function restrictProtocols($ch): void
    {
        // CURLOPT_PROTOCOLS_STR exists from libcurl 7.85 / PHP 8.2; the
        // integer pair stays for older libcurl builds. Setting both is safe.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        }
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    }

    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'the configured base URL';
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if ($lines === false) {
            return $headers;
        }
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
