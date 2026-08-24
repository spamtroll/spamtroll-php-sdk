# HTTP adapters

The SDK never opens a socket directly. Every request goes through a
small interface, `Spamtroll\Sdk\Http\HttpClientInterface`, that callers
can implement to delegate to whatever HTTP stack the host environment
already provides. This is the integration seam that keeps the SDK
zero-dependency while still letting WordPress, IPS, Drupal and bespoke
apps plug in their native transport — and inherit all of that platform's
HTTP filters, proxy support, and TLS configuration for free.

## The contract

```php
namespace Spamtroll\Sdk\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @throws \Spamtroll\Sdk\Exception\ConnectionException On connection failure.
     * @throws \Spamtroll\Sdk\Exception\TimeoutException    On request timeout.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
    ): HttpResponse;
}
```

`HttpResponse` is a plain readonly DTO:

```php
final class HttpResponse
{
    public readonly int $statusCode;
    public readonly string $body;
    /** @var array<string, string> lowercased name => value */
    public readonly array $headers;
}
```

### Three rules adapters MUST follow

1. **Translate transport failures into SDK exceptions — all of them.** A
   failed DNS lookup, a refused connection, or a TLS handshake error is a
   `ConnectionException`; an exceeded timeout is a `TimeoutException`
   (which extends `ConnectionException`). Nothing outside the
   `SpamtrollException` hierarchy may leave `send()`. Catch `\Throwable`
   and wrap it: your stack throws more types than its documentation
   lists. Guzzle's `MalformedUriException` extends only
   `\InvalidArgumentException`; `\IPS\Http\Url::external()` throws
   `\IPS\Http\Url\Exception`, which is not the `Request\Exception` the
   docs mention. Either one, uncaught, travels straight out of the
   comment hook.
2. **Do NOT translate HTTP error responses into exceptions.** A 401,
   429, 500 or any other status returned by the server is a *successful
   round-trip* from the adapter's point of view — return it via
   `HttpResponse` and let `Client` decide. Throwing on 5xx from the
   adapter defeats the SDK's retry logic. Conversely, never report "no
   response" as `statusCode` 0: `HttpResponse::$statusCode` must be a
   real HTTP status, and the absence of one is a `ConnectionException`.
3. **Never follow redirects.** The API does not redirect. A redirect
   replays the request — `X-API-Key` included — against whatever host the
   `Location` header names, and platform API keys have no expiry: they
   are revocable only by rotation, so one misdirected hop leaks the key
   permanently. Guzzle strips `Authorization` and `Cookie` across origins
   but **not** custom headers like `X-API-Key`; WordPress's Requests
   library forwards request headers to the next hop; IPS follows up to
   five hops. Every one of these defaults is wrong for us, so switch
   redirects off **explicitly** in every adapter.

Bounding the response size is strongly recommended too. An
out-of-memory `Error` is not catchable, so a captive portal streaming
megabytes takes the whole host request down with it — a fail-closed
outcome triggered by a remote party.

## Default — `CurlHttpClient`

Used when `Client` is constructed without an adapter. Built directly on
ext-curl, no third-party deps. Sets `CURLOPT_SSL_VERIFYPEER=true`,
`CURLOPT_FOLLOWLOCATION=false`, restricts the protocol allow-list to
http+https where the build supports it, caps the body with
`CURLOPT_MAXFILESIZE`, and maps
`CURLE_OPERATION_TIMEOUTED` to `TimeoutException`, every other curl error
to `ConnectionException`.

## WordPress adapter

```php
class Spamtroll_Wp_Http_Client implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        $args = [
            'method'              => $method,
            'timeout'             => $timeout,
            'sslverify'           => true,
            'headers'             => $headers,
            // Rule 3. wp_remote_request() follows five redirects by default
            // and Requests carries the request headers to the next hop, so
            // without this X-API-Key leaves for the redirect target.
            'redirection'         => 0,
            // An error page or captive portal can stream far more than an
            // API response ever contains.
            'limit_response_size' => 1048576,
        ];
        if ($method === 'POST' && $body !== null) {
            $args['body'] = $body;
        }

        try {
            $response = wp_remote_request($url, $args);
        } catch (\Throwable $t) {
            // Rule 1: nothing but SDK exceptions leaves send().
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($t->getMessage());
        }

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $lower = strtolower($message);
            // Best-effort: WP_Error messages are translated, so a non-English
            // site loses the distinction. Harmless — TimeoutException extends
            // ConnectionException, so retry and catch behave identically.
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                throw \Spamtroll\Sdk\Exception\TimeoutException::afterSeconds($timeout);
            }
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($message);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 100) {
            // wp_remote_retrieve_response_code() returns '' for a malformed
            // response. Rule 2: that is "no response", not "the server said no".
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage('no HTTP status in the WordPress response');
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(',', $value) : (string) $value;
        }

        return new \Spamtroll\Sdk\Http\HttpResponse(
            $status,
            (string) wp_remote_retrieve_body($response),
            $headers,
        );
    }
}
```

Why this matters: every request now flows through `wp_remote_*`, which
means WordPress's `http_request_args` and `pre_http_request` filters fire
on every Spamtroll call. A site admin who configured a proxy via the WP
UI gets that proxy honoured automatically, and Query Monitor shows every
Spamtroll call alongside the site's own traffic.

## IPS adapter

```php
class _IpsHttpClient implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    /** Third argument of request(): 0 switches redirect-following off. */
    public const FOLLOW_REDIRECTS = 0;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        try {
            $request = \IPS\Http\Url::external($url)->request($timeout, null, self::FOLLOW_REDIRECTS);
            $request = $request->setHeaders($headers);

            $ipsResponse = $method === 'POST' ? $request->post($body ?? '') : $request->get();
        } catch (\Throwable $t) {
            // Not just \IPS\Http\Request\Exception: \IPS\Http\Url::external()
            // raises \IPS\Http\Url\Exception for a URL it cannot parse, and
            // baseUrl comes from a text field in the ACP.
            $message = $t->getMessage();
            if (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                throw \Spamtroll\Sdk\Exception\TimeoutException::afterSeconds($timeout);
            }
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($message);
        }

        $status = (int) $ipsResponse->httpResponseCode;
        if ($status < 100) {
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage('no HTTP status in the IPS response');
        }

        $headers = [];
        foreach ((array) $ipsResponse->httpHeaders as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[mb_strtolower($name)] = (string) $value;
            }
        }

        return new \Spamtroll\Sdk\Http\HttpResponse($status, (string) $ipsResponse, $headers);
    }
}
```

This is the shape the shipped IPS plugin uses
(`sources/Api/IpsHttpClient.php`). IPS's HTTP stack already handles proxy
configuration, certificate bundles and the forum's transport
preferences, so the adapter just delegates.

## Guzzle adapter

```php
final class GuzzleHttpAdapter implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    public function __construct(private \GuzzleHttp\ClientInterface $guzzle) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        try {
            $response = $this->guzzle->request($method, $url, [
                'headers'         => $headers,
                'body'            => $body,
                'timeout'         => $timeout,
                'connect_timeout' => $timeout,
                'http_errors'     => false,  // surface 4xx/5xx as Response, not exception
                // Rule 3. Guzzle follows five redirects by default and strips
                // only Authorization and Cookie across origins — X-API-Key
                // rides along.
                'allow_redirects' => false,
            ]);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($e->getMessage());
        } catch (\Throwable $e) {
            // TransferException is not enough: MalformedUriException extends
            // \InvalidArgumentException and implements no Guzzle interface.
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($e->getMessage());
        }

        return new \Spamtroll\Sdk\Http\HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            // HttpResponse documents lowercase keys; Guzzle preserves the
            // server's casing, which makes Retry-After unreadable.
            array_change_key_case(
                array_map(static fn (array $values): string => implode(',', $values), $response->getHeaders()),
                CASE_LOWER,
            ),
        );
    }
}
```

### A note on the protocol allow-list

`CurlHttpClient` asks for the allow-list through
`CURLOPT_PROTOCOLS_STR` / `CURLOPT_REDIR_PROTOCOLS_STR`, falling back to the
deprecated integer pair, and asking for neither when the build has neither.
Those string options arrived in **libcurl 7.85.0** and php-src registers
them behind a `LIBCURL_VERSION_NUM` guard, so whether they exist depends on
the libcurl your curl extension was *built* against — not on your PHP
version. Plenty of current distributions, GitHub's Linux runner among them,
ship a PHP 8.2+ with an older libcurl.

Two things follow, and both apply to your adapter as much as to ours:

1. **Never name a version-gated curl constant directly.** In PHP 8 a missing
   constant raises `Error`, not `Exception` — it goes straight past
   `catch (\Exception)` and it fires while the request is being built, before
   any fail-open path exists. Probe with `defined()` and resolve with
   `constant()`.
2. **Do not let the allow-list carry the guarantee on its own.** It is the
   third line: `ClientConfig` refuses a non-http(s) `baseUrl`, and refusing
   redirects means the transport only ever visits the URL it was handed.
   Those two are unconditional, so a build without the allow-list is
   protected by exactly the same rules as one with it.

## Response headers

`Client` does not read `HttpResponse::$headers` today, so `Retry-After`
and the `X-RateLimit-*` family are not reachable through
`checkSpamOrHam()`. Adapters that need them (to back off intelligently
after a 429) can keep the last response's headers on the adapter
instance and read them straight after the call — the Drupal and IPS
plugins both do this. Populate the array correctly, with lowercased
keys, so that pattern keeps working.

## Testing your adapter

The SDK ships `Spamtroll\Sdk\Tests\Fake\FakeHttpClient` for unit tests.
For adapter-level tests, assert the contract directly: headers passed
through, body returned verbatim, `ConnectionException` on a closed
socket, `TimeoutException` when the timeout fires, **no redirect
followed** (point the adapter at a local server that 302s and assert the
second host never sees `X-API-Key`), and no non-SDK exception escaping
`send()` when the stack throws something unusual.
