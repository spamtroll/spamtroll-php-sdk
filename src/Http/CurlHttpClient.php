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

        if ($headers) {
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

        $rawString = (string) $raw;
        $rawHeaders = substr($rawString, 0, $headerSize);
        $rawBody = substr($rawString, $headerSize);

        return new HttpResponse($statusCode, $rawBody === false ? '' : $rawBody, self::parseHeaders($rawHeaders === false ? '' : $rawHeaders));
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}
