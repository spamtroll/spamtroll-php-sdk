# HTTP adapters

The SDK never opens a socket directly. Every request goes through a
small interface, `Spamtroll\Sdk\Http\HttpClientInterface`, that callers
can implement to delegate to whatever HTTP stack the host environment
already provides. This is the integration seam that keeps the SDK
zero-dependency while still letting WordPress, IPS, and bespoke apps
plug in their native transport — and inherit all of that platform's
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

`HttpResponse` is a plain DTO:

```php
final class HttpResponse
{
    public int $statusCode;
    public string $body;
    /** @var array<string, string> */
    public array $headers;
}
```

### Two rules adapters MUST follow

1. **Translate connection-level failures into exceptions.** A failed
   DNS lookup, a refused connection, or a TLS handshake error is a
   `ConnectionException`. A request that exceeded the configured
   timeout is a `TimeoutException` (which extends
   `ConnectionException`). The SDK retries these.
2. **Do NOT translate HTTP error responses into exceptions.** A 401,
   429, 500, or any other status code returned by the server is a
   *successful round-trip* from the adapter's point of view — return
   it via `HttpResponse` and let `Client` decide what to do. Throwing
   on 5xx from the adapter would defeat the SDK's retry logic.

## Default — `CurlHttpClient`

Used when `Client` is constructed without an adapter. Built directly on
ext-curl, no third-party deps. Honours `CURLOPT_SSL_VERIFYPEER=true`
and refuses redirects. Maps `CURLE_OPERATION_TIMEOUTED` to
`TimeoutException`, every other curl error to `ConnectionException`.

## WordPress adapter

```php
class Spamtroll_Wp_Http_Client implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        $args = [
            'method'    => $method,
            'timeout'   => $timeout,
            'sslverify' => true,
            'headers'   => $headers,
        ];
        if ($method === 'POST' && $body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            $lower = strtolower($message);
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                throw \Spamtroll\Sdk\Exception\TimeoutException::afterSeconds($timeout);
            }
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($message);
        }

        return new \Spamtroll\Sdk\Http\HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
            [],
        );
    }
}
```

Why this matters: every request now flows through `wp_remote_*`, which
means WordPress's `http_request_args` and `pre_http_request` filters
fire on every Spamtroll call. A site admin who configured a proxy via
the WP UI gets that proxy honoured automatically. A request inspector
plugin like Query Monitor will show every Spamtroll call alongside the
site's own traffic.

## IPS adapter

```php
class _IpsHttpClient implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        try {
            $request = \IPS\Http\Url::external($url)->request($timeout);
            $request = $request->setHeaders($headers);

            if ($method === 'POST') {
                $ipsResponse = $request->post($body ?? '');
            } else {
                $ipsResponse = $request->get();
            }
        } catch (\IPS\Http\Request\Exception $e) {
            $message = $e->getMessage();
            if (stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false) {
                throw \Spamtroll\Sdk\Exception\TimeoutException::afterSeconds($timeout);
            }
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($message);
        }

        return new \Spamtroll\Sdk\Http\HttpResponse(
            (int) $ipsResponse->httpResponseCode,
            (string) $ipsResponse,
            [],
        );
    }
}
```

Same idea as the WP adapter — IPS's HTTP stack already handles proxy
configuration, certificate bundles, and the forum's transport
preferences, so the adapter just delegates.

## Guzzle adapter

If the host application already uses Guzzle, an adapter is six lines:

```php
final class GuzzleHttpAdapter implements \Spamtroll\Sdk\Http\HttpClientInterface
{
    public function __construct(private \GuzzleHttp\ClientInterface $guzzle) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): \Spamtroll\Sdk\Http\HttpResponse
    {
        try {
            $response = $this->guzzle->request($method, $url, [
                'headers' => $headers,
                'body'    => $body,
                'timeout' => $timeout,
                'http_errors' => false, // surface 4xx/5xx as Response, not exception
            ]);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw \Spamtroll\Sdk\Exception\ConnectionException::fromMessage($e->getMessage());
        }

        return new \Spamtroll\Sdk\Http\HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            array_map(fn (array $values) => implode(',', $values), $response->getHeaders()),
        );
    }
}
```

Note `http_errors => false` — without it Guzzle throws on 4xx/5xx,
which would violate rule 2 above and break retry logic.

## Testing your adapter

The SDK ships `Spamtroll\Sdk\Tests\Fake\FakeHttpClient` for unit
tests, but for adapter-level tests you can use the same pattern:
write a base `TestCase` that asserts the adapter's behaviour against
the contract (headers passed through, body returned verbatim,
`ConnectionException` raised on a closed socket, `TimeoutException`
raised when the timeout fires). Run it once per adapter
implementation. The SDK's own `tests/` is the reference shape.
