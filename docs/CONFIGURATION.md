# Configuration

`Spamtroll\Sdk\ClientConfig` is the immutable bag of settings every
`Client` instance reads from. Construct it once and inject; the SDK
never mutates it after `Client::__construct`.

```php
use Spamtroll\Sdk\ClientConfig;

$config = new ClientConfig(
    baseUrl: 'https://api.spamtroll.io/api/v1',
    timeout: 5,
    maxRetries: 3,
    retryBaseDelayMs: 500,
    userAgent: null,           // SDK fills in 'spamtroll-php-sdk/<version>'
    scoreDenominator: 30.0,
);
```

## Field reference

| Field | Default | What it does |
|---|---|---|
| `baseUrl` | `https://api.spamtroll.io/api/v1` | Root URL for all requests. Trailing slash is stripped. |
| `timeout` | `5` (seconds) | Per-request timeout. Floor 1. Counts both connect and read. |
| `maxRetries` | `3` | Total attempts (not retries). First attempt counts. Floor 1 (= no retries). |
| `retryBaseDelayMs` | `500` | Linear backoff: attempt N waits `N * retryBaseDelayMs` ms. `0` disables sleeping (useful in tests). |
| `userAgent` | `null` → `spamtroll-php-sdk/<version>` | Header sent on every request. Host plugins should prepend their own identifier. |
| `scoreDenominator` | `30.0` | Divisor applied to the raw score to produce the 0.0–1.0 normalised score. Floor > 0. |

## Environment-specific recommendations

### Forum / WordPress / IPS plugin (production)

```php
new ClientConfig(
    timeout: 5,
    maxRetries: 3,
    retryBaseDelayMs: 500,
    userAgent: 'my-plugin/' . MY_PLUGIN_VERSION . ' spamtroll-php-sdk/' . \Spamtroll\Sdk\Version::VERSION,
);
```

The defaults are tuned for this case. Five seconds is enough budget
for the API to score and respond, three attempts cover transient
timeouts without doubling latency in the happy path. Always prepend
your plugin identifier to the user agent — it makes scan logs in the
Spamtroll backend useful for debugging which integration is slow.

### Background job / queue worker

```php
new ClientConfig(
    timeout: 15,
    maxRetries: 5,
    retryBaseDelayMs: 1000,
);
```

Longer timeout, more retries, slower backoff. Background jobs can wait
— they should pull on the API reliably rather than fail fast.

### Tests

```php
new ClientConfig(
    retryBaseDelayMs: 0, // never sleep
);
```

`0` here is the difference between a 100-test suite finishing in
0.1 s and 30 s. The SDK's own test suite uses this constant.

### Custom backend (staging, self-hosted)

```php
new ClientConfig(
    baseUrl: 'https://staging.spamtroll.io/api/v1',
);
```

Don't add a trailing slash; the SDK strips it for you.

## When to deviate from `scoreDenominator`

The default `30.0` came from the IPS plugin's empirically-tuned
mapping. The WordPress plugin originally used `15.0`, which collapsed
"borderline spam" and "definitely spam" into the same `1.0` bucket and
hid signal from admins picking thresholds. We standardised on `30.0`
in v0.9.0.

**Don't change it unless you know why.** The score-to-action mapping
in your plugin (typically a 0.7 spam threshold + 0.4 suspicious
threshold) was calibrated against the default. Lowering the
denominator makes everything look spammier; raising it makes
everything look safer. If you do change it, recalibrate the
thresholds at the same time.

## What is *not* configurable

By design:

- The exact set of HTTP headers sent (`X-API-Key`, `Content-Type`,
  `Accept`, `User-Agent`). The SDK owns these.
- TLS verification. Always on. If you need to disable it (you don't),
  do it in your `HttpClientInterface` adapter.
- Followed redirects. The SDK never follows redirects — the API does
  not redirect, and a redirect to an unexpected host would be a
  surprising security gotcha.
- The retry policy itself (which status codes are retried). 5xx and
  network failures are retried; 401, 429, and other 4xx are not. This
  is hard-coded because changing it would break the contract with
  host plugins that rely on 429 → "Response with success=false, no
  exception, no retry".
