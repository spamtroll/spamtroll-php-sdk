# Usage

The SDK exposes a single entry point: `Spamtroll\Sdk\Client`. Every
operation goes through it.

## Quick start

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Request\CheckSpamRequest;

$client = new Client('your-api-key');

$response = $client->checkSpam(new CheckSpamRequest(
    content: $commentBody,
    source: CheckSpamRequest::SOURCE_COMMENT,
    ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
    username: $authorName,
    email: $authorEmail,
));

if ($response->isSpam()) {
    // Score >= spam threshold — block.
} elseif ($response->getSpamScore() >= 0.4) {
    // Suspicious — send to moderation.
}
```

## With a custom configuration

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;

$client = new Client(
    apiKey: 'your-api-key',
    config: new ClientConfig(
        timeout: 5,
        maxRetries: 3,
        retryBaseDelayMs: 500,
        userAgent: 'my-plugin/1.2.3 spamtroll-php-sdk/' . \Spamtroll\Sdk\Version::VERSION,
        scoreDenominator: 30.0,
    ),
);
```

See [CONFIGURATION.md](CONFIGURATION.md) for what every field does and
when to deviate from the defaults.

## With a custom HTTP transport

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Http\HttpClientInterface;

$client = new Client(
    apiKey: 'your-api-key',
    http: $myHttpAdapter, // implements HttpClientInterface
);
```

This is the integration point for WordPress (`wp_remote_*`) and IPS
(`\IPS\Http\Url`). See [HTTP_ADAPTERS.md](HTTP_ADAPTERS.md) for the
contract and reference implementations.

## All Client methods

| Method | Returns | What it does |
|---|---|---|
| `checkSpam(CheckSpamRequest)` | `CheckSpamResponse` | Submits content (post body, registration data, comment) to `/scan/check`. The hot path. |
| `testConnection()` | `Response` | Hits `/scan/status` with a GET. Use it from admin UIs to verify the API key + connectivity. |
| `getAccountUsage()` | `UsageResponse` | Returns `requests_today`, `requests_limit`, `requests_remaining` from `/account/usage`. Cheap; safe to call on a dashboard. |
| `isConfigured()` | `bool` | True if the API key is non-empty. Cheap, no network. |
| `getConfig()` | `ClientConfig` | Returns the active configuration object. Useful for inspecting effective values in tests. |

## CheckSpamRequest fields

| Field | Required | Notes |
|---|---|---|
| `content` | yes | Plain-text body. Strip HTML before passing. |
| `source` | yes (default `generic`) | One of the `SOURCE_*` constants: `forum`, `comment`, `message`, `registration`, `generic`. |
| `ipAddress` | no | Client IP. Improves scoring. Empty strings are dropped (not sent as `""`). |
| `username` | no | Author display name. |
| `email` | no | Author email. Used for blocklist correlation. |

`CheckSpamRequest::toArray()` returns the canonical wire format —
`content`, `source`, plus any non-empty optional fields. Empty optional
fields are *omitted*, not sent as empty strings.

## Reading the response

See [RESPONSE_SCHEMA.md](RESPONSE_SCHEMA.md) for every getter, the
score normalisation formula, and the envelope-vs-flat payload handling.
