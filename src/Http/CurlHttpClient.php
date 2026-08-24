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

        // Resolved by name, not by bare constant: the protocol allow-list
        // options only exist on builds linked against a new enough libcurl,
        // and a bare reference to a constant that is not there is an Error,
        // not an Exception — it would sail past any fail-open catch.
        foreach (self::securityOptions() as $option => $value) {
            $constant = constant($option);
            if (!is_int($constant)) {
                // Unreachable in practice: securityOptions() only returns
                // names it has already probed with defined(), and every curl
                // option constant is an int. Refusing the request is still
                // the right response — silently dropping one of these would
                // mean making the call without TLS verification or without
                // the redirect ban. Client turns this into a fail-open skip.
                curl_close($ch);

                throw ConnectionException::fromMessage(
                    'curl option ' . $option . ' is not usable on this build',
                );
            }
            curl_setopt($ch, $constant, $value);
        }

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
     * The transport's security posture, as curl option **name** => value.
     *
     * Returning names rather than constants is the point. The protocol
     * allow-list options are version-gated:
     *
     * - `CURLOPT_PROTOCOLS_STR` / `CURLOPT_REDIR_PROTOCOLS_STR` were added in
     *   libcurl **7.85.0**, and php-src registers them behind
     *   `#if LIBCURL_VERSION_NUM >= 0x075500`. So their presence depends on
     *   the libcurl the curl extension was *built* against, not on the PHP
     *   version: PHP 8.4 on a distro shipping libcurl 7.81 does not have
     *   them. GitHub's own Linux runner is such a build.
     * - `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` (libcurl 7.19.4) are
     *   currently registered unconditionally, but libcurl deprecated them in
     *   7.85.0. When they eventually go, an unguarded reference to *those*
     *   becomes the crash instead. Neither pair may be named directly.
     *
     * Referencing a constant that does not exist raises `Error` in PHP 8 —
     * which is exactly the class of throwable that walks past
     * `catch (\Exception)`, and it would fire while building the transport,
     * before any fail-open path could run.
     *
     * **The allow-list is the third line of defence, and losing it does not
     * weaken the first two.** A request only reaches a non-HTTP scheme if it
     * gets past (1) `ClientConfig`, which rejects any `baseUrl` that is not
     * http(s), and (2) `CURLOPT_FOLLOWLOCATION => false`, which means curl
     * never visits a URL the caller did not supply. Both are unconditional
     * and appear in the always-present block below.
     *
     * @param null|callable(string): bool $isDefined Constant probe; injected by tests to
     *                                               exercise builds this machine does not have.
     *
     * @return array<string, mixed> Option name => value.
     *
     * @internal
     */
    public static function securityOptions(?callable $isDefined = null): array
    {
        $isDefined ??= static fn (string $name): bool => defined($name);

        // Unconditional. These carry the guarantee on every build.
        $options = [
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXFILESIZE' => self::MAX_RESPONSE_BYTES,
        ];

        // Prefer the modern pair, and use exactly one pair: both map to the
        // same field inside libcurl, so setting the deprecated one afterwards
        // would quietly overwrite the modern one with a coarser set.
        if ($isDefined('CURLOPT_PROTOCOLS_STR') && $isDefined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options['CURLOPT_PROTOCOLS_STR'] = 'http,https';
            $options['CURLOPT_REDIR_PROTOCOLS_STR'] = 'https';

            return $options;
        }

        if ($isDefined('CURLOPT_PROTOCOLS')
            && $isDefined('CURLOPT_REDIR_PROTOCOLS')
            && $isDefined('CURLPROTO_HTTP')
            && $isDefined('CURLPROTO_HTTPS')
        ) {
            $http = (int) constant('CURLPROTO_HTTP');
            $https = (int) constant('CURLPROTO_HTTPS');

            $options['CURLOPT_PROTOCOLS'] = $http | $https;
            $options['CURLOPT_REDIR_PROTOCOLS'] = $https;
        }

        // Neither pair available: no allow-list, and no crash. Lines one and
        // two above still stand.
        return $options;
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
