# Spamtroll PHP SDK

[![Latest Version](https://img.shields.io/packagist/v/spamtroll/php-sdk.svg)](https://packagist.org/packages/spamtroll/php-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/spamtroll/php-sdk.svg)](https://packagist.org/packages/spamtroll/php-sdk)
[![CI](https://github.com/spamtroll/spamtroll-php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/spamtroll/spamtroll-php-sdk/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/spamtroll/php-sdk.svg)](LICENSE)

Zero-dependency PHP client for the [Spamtroll](https://spamtroll.io) spam
detection API.

Drop it into a WordPress plugin, an IPS Community Suite application, a
framework app, or a plain PHP script — the core SDK has no runtime
dependencies beyond `ext-curl` and `ext-json`. Host platforms can swap in
their own HTTP transport (so WP calls go through `wp_remote_*` and respect
admin filters, IPS calls go through `\IPS\Http\Url`) by implementing one
interface.

## Requirements

- PHP 8.2 or newer
- `ext-curl`, `ext-json`, `ext-mbstring`

## Installation

```bash
composer require spamtroll/php-sdk
```

Without Composer (e.g. a bundled plugin), drop `src/` somewhere in your
project and include the fallback autoloader:

```php
require_once __DIR__ . '/path/to/spamtroll-php-sdk/autoload.php';
```

## Quick start

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Request\CheckSpamRequest;

$client = new Client('your-api-key');

$response = $client->checkSpamOrHam(
    new CheckSpamRequest(
        content: $comment,
        source: CheckSpamRequest::SOURCE_COMMENT,
        ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
        username: $author,
        email: $authorEmail,
    )
);

if ($response->wasSkipped()) {
    error_log('spamtroll: skipped (' . $response->getSkipReason() . ')');
    // fail open — let the content through
} elseif ($response->shouldBlock()) {
    // block
} elseif ($response->shouldModerate()) {
    // send to moderation
}
```

`checkSpamOrHam()` **never throws**. Every failure — no API key, DNS,
timeout, 401, 5xx, quota exhausted, an HTML error page — comes back as a
response that reports `wasSkipped()` and never blocks. A blocking verdict
can only come from a successful scan, so an integration written against
this method cannot fail closed by forgetting a `catch`.

The verdict is the server's `status`, read through `shouldBlock()` /
`shouldModerate()`. `getSpamScore()` is for display: the platform's
thresholds live in the backend, not in your plugin.

## Configuration

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;

$config = new ClientConfig(
    baseUrl: 'https://api.spamtroll.io/api/v1',
    timeout: 3,
    maxRetries: 2,
    retryBaseDelayMs: 250,
    userAgent: 'my-plugin/1.0 spamtroll-php-sdk/' . \Spamtroll\Sdk\Version::VERSION,
    scoreDenominator: 30.0,
    totalBudgetMs: 6000,
);

$client = new Client('your-api-key', $config);
```

### What the fields mean

| Field | Default | Notes |
|---|---|---|
| `baseUrl` | `https://api.spamtroll.io/api/v1` | Trailing slash stripped. Must be http(s). |
| `timeout` | `3` | Per-attempt seconds (connect + read). |
| `maxRetries` | `2` | Total attempts, not retries. First attempt counts. |
| `retryBaseDelayMs` | `250` | Backoff: `attempt * base` ms before attempt 2+. Set `0` to disable (tests). |
| `totalBudgetMs` | `6000` | Ceiling on the whole call, retries and sleeps included. `0` disables it. |
| `userAgent` | `spamtroll-php-sdk/{version}` | Host integrations should prepend their own identifier. |
| `scoreDenominator` | `30.0` | Maps raw API score (0…∞) to normalized 0.0–1.0 via `min(1, raw / denominator)`. Display only. |

Worst case with the defaults is **~6.25 s**. The 0.9.x defaults (5 s × 3
attempts + 1.5 s backoff) could block a visitor's request for 16.5 s,
long enough for PHP-FPM to kill the worker mid-retry and lose the post.

## Score normalization

The score is a **display value**; the verdict is `getStatus()`. The
backend scores on an open-ended additive scale where a raw score of
**15** is "definitely spam" and **30** is twice the threshold. The SDK
normalizes via `min(1.0, raw / scoreDenominator)`:

| Raw | Normalized (denominator 30) |
|---:|---:|
| 0 | 0.00 |
| 7.5 | 0.25 |
| 15 | 0.50 |
| 22.5 | 0.75 |
| 30+ | 1.00 |

Use `getSpamScore()` for the 0–1 value and `getRawSpamScore()` for the
native scale. Don't build a blocking threshold out of either — the
platform's thresholds live in the backend and the server already applied
them.

## Custom HTTP adapter

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Http\HttpResponse;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;

final class MyHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        // 1. Translate every transport failure into
        //    ConnectionException / TimeoutException. Catch \Throwable.
        // 2. 4xx/5xx are NOT errors here — return them via HttpResponse.
        // 3. Never follow redirects: they carry X-API-Key to another host.
    }
}

$client = new Client('your-api-key', null, new MyHttpClient());
```

WordPress and IPS integrations ship adapters that delegate to
`wp_remote_*` and `\IPS\Http\Url` respectively, so platform-level filters
(proxy, SSL overrides, request inspection) still apply.

## Error handling

`checkSpamOrHam()` never throws — prefer it and you can skip this
section. `checkSpam()` and `testConnection()` throw:

| Exception | When |
|---|---|
| `NotConfiguredException` | Empty API key. |
| `InvalidConfigurationException` | `baseUrl` is not http(s) (thrown from `ClientConfig`). |
| `AuthenticationException` | HTTP 401 — invalid API key. |
| `ConnectionException` | Connection failure after all retries. |
| `TimeoutException` | Timeout after all retries (extends `ConnectionException`). |
| `ServerException` | HTTP 5xx after all retries. |
| `SpamtrollException` | Base for all SDK exceptions. |

Non-fatal error responses — HTTP 402, 429, other 4xx — are returned as a
`Response` with `success === false`, `wasSkipped() === true` and a
machine-readable `getErrorCode()`.

## Documentation

- [Installation](docs/INSTALLATION.md) — requirements, Composer + manual install.
- [Usage](docs/USAGE.md) — every Client method, request fields, examples.
- [Configuration](docs/CONFIGURATION.md) — `ClientConfig` field-by-field, environment-specific recommendations.
- [HTTP adapters](docs/HTTP_ADAPTERS.md) — interface contract, reference adapters for WordPress / IPS / Guzzle.
- [Error handling](docs/ERROR_HANDLING.md) — exception hierarchy, fail-open patterns.
- [Response schema](docs/RESPONSE_SCHEMA.md) — `CheckSpamResponse` getters, score normalisation, envelope handling.
- [Contributing](docs/CONTRIBUTING.md) — local setup, quality gate, release checklist.

## Development

```bash
composer install
composer qa            # cs-fixer + phpstan + peck + pest
composer test          # tests only
composer test:coverage # tests with coverage
composer lint:fix      # auto-format
```

See [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) for the full quality
gate, including the `aspell` dependency required by `composer peck`.

## License

MIT — see [LICENSE](LICENSE).
