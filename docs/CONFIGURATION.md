# Configuration

`Spamtroll\Sdk\ClientConfig` is the immutable bag of settings every
`Client` instance reads from. Its properties are `readonly`, so this is
enforced rather than merely promised. Construct it once and inject.

```php
use Spamtroll\Sdk\ClientConfig;

$config = new ClientConfig(
    baseUrl: 'https://api.spamtroll.io/api/v1',
    timeout: 3,
    maxRetries: 2,
    retryBaseDelayMs: 250,
    userAgent: null,           // SDK fills in 'spamtroll-php-sdk/<version>'
    scoreDenominator: 30.0,
    totalBudgetMs: 6000,
);
```

## Field reference

| Field | Default | What it does |
|---|---|---|
| `baseUrl` | `https://api.spamtroll.io/api/v1` | Root URL for all requests. Trailing slash stripped. **Must be `http` or `https`** — anything else throws `InvalidConfigurationException` (see below). |
| `timeout` | `3` (seconds) | Per-attempt timeout, connect + read. Floor 1. Also clamped down to whatever is left of `totalBudgetMs`. |
| `maxRetries` | `2` | Total attempts, not retries. First attempt counts. Floor 1 (= no retries). A POST that gets a 5xx is retried **once** regardless of this value — see below. |
| `retryBaseDelayMs` | `250` | Linear backoff: attempt N waits `N * retryBaseDelayMs` ms. `0` disables sleeping (useful in tests). Clamped to the remaining budget. |
| `totalBudgetMs` | `6000` | Ceiling on the **whole call**, retries and sleeps included. `0` disables the ceiling. |
| `userAgent` | `null` → `spamtroll-php-sdk/<version>` | Header sent on every request. Host plugins should prepend their own identifier. |
| `scoreDenominator` | `30.0` | Divisor applied to the raw score to produce the 0.0–1.0 normalised score. Floor > 0. |

## Worst-case latency

`timeout × maxRetries` is **not** a latency bound: the backoff sleeps sit
on top of it. This matters because the scan runs inside the HTTP request
the visitor is waiting on, and PHP-FPM's `request_terminate_timeout` is
often 10–15 s — a worker killed mid-retry means a 502 and a lost post,
which is worse than blocking the content.

`totalBudgetMs` is the actual ceiling: the SDK checks it before each
retry, clamps the sleep to what is left, and clamps each attempt's
timeout to the remaining budget.

| Preset | timeout | maxRetries | backoff | budget | Worst case |
|---|---:|---:|---:|---:|---:|
| **Interactive (default)** | 3 s | 2 | 250 ms | 6000 ms | **~6.25 s** |
| Background worker | 15 s | 5 | 1000 ms | 0 (off) | ~85 s |
| Tests | any | any | 0 ms | any | no sleeping |
| *0.9.x defaults, for reference* | *5 s* | *3* | *500 ms* | *n/a* | *16.5 s* |

Read that last row before restoring the old values: three five-second
attempts plus 1.5 s of backoff is 16.5 s of a browser waiting on a
comment form.

## Environment-specific recommendations

### Forum / WordPress / IPS plugin (production)

```php
new ClientConfig(
    userAgent: 'my-plugin/' . MY_PLUGIN_VERSION . ' spamtroll-php-sdk/' . \Spamtroll\Sdk\Version::VERSION,
);
```

The defaults are tuned for exactly this case, so pass only the user
agent. Always prepend your plugin identifier — it makes scan logs in the
Spamtroll backend useful for working out which integration is slow.

### Background job / queue worker

```php
new ClientConfig(
    timeout: 15,
    maxRetries: 5,
    retryBaseDelayMs: 1000,
    totalBudgetMs: 0,   // no ceiling: nobody is waiting
);
```

Longer timeout, more retries, slower backoff, no budget. Background jobs
can wait — they should pull on the API reliably rather than fail fast.
Never use this profile on a request a visitor is blocked on.

### Tests

```php
new ClientConfig(
    retryBaseDelayMs: 0, // never sleep
);
```

`0` here is the difference between a 100-test suite finishing in 0.1 s
and 30 s. The SDK's own test suite uses it.

### Custom backend (staging, self-hosted)

```php
new ClientConfig(
    baseUrl: 'https://staging.spamtroll.io/api/v1',
);
```

Don't add a trailing slash; the SDK strips it for you.

`baseUrl` must be `http://` or `https://`. Anything else — `file://`,
`gopher://`, a bare hostname — throws
`Spamtroll\Sdk\Exception\InvalidConfigurationException`. This is not
pedantry: `baseUrl` is an admin-editable text field in every integration,
and without the check `file:///etc/passwd` was a request the SDK would
happily perform, with the file's contents landing in `$response->data`
and in your logs. Use `http://` only for local development.

`InvalidConfigurationException` extends `SpamtrollException`, so the
single catch your integration already has around its scan path covers a
typo in a settings field too.

## Retry policy

- Connection failures and timeouts: retried up to `maxRetries`.
- `GET` + 5xx: retried up to `maxRetries`.
- `POST` + 5xx: retried **once**, whatever `maxRetries` says. The backend
  charges the caller's daily scan quota *before* running the scan, so a
  500 has already been paid for; retrying three times bills a customer
  three scans for one failed scan during an outage they did not cause.
- 401, 402, 429 and other 4xx: never retried.

Every POST carries an `Idempotency-Key` generated once per call and
reused across that call's retries.

## What is *not* configurable

By design:

- The exact set of HTTP headers sent (`X-API-Key`, `Content-Type`,
  `Accept`, `User-Agent`, `Idempotency-Key`). The SDK owns these.
- TLS verification. Always on. If you need to disable it (you don't), do
  it in your `HttpClientInterface` adapter.
- Followed redirects. The SDK never follows redirects, and adapters must
  not either — a redirect carries `X-API-Key` to whatever host `Location`
  names, and platform API keys have no expiry.
- The retry policy above.

## When to deviate from `scoreDenominator`

The default `30.0` came from the IPS plugin's empirically-tuned mapping.
The WordPress plugin originally used `15.0`, which collapsed "borderline
spam" and "definitely spam" into the same `1.0` bucket and hid signal
from admins picking thresholds. We standardised on `30.0` in v0.9.0.

**Don't change it unless you know why** — and note that since 0.10.0 the
blocking decision comes from `getStatus()`, not from the normalised
score, so the denominator only affects what you *display*.
