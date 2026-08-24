# Upgrade Guide

This document lists behavioural and API changes between releases and how
to migrate.

The SDK is at `0.x`. Per SemVer §4 a `0.y.z` minor may break
compatibility, and 0.10.0 does. Until `1.0.0`, treat every minor as
potentially breaking and read this file before bumping a constraint.

> Earlier text here promised "minor and patch releases are
> backwards-compatible per SemVer". That promise was already broken by
> 0.9.3, which changed `Client::dispatch()` to stop throwing on HTTP 402
> and shipped it as a patch. It is withdrawn rather than repeated.

## 0.9.x → 0.10.0

### 0. Before anything: PHP 8.2

`composer require spamtroll/php-sdk:^0.10` fails on PHP 8.0/8.1 — that is
deliberate; both are past end of security support. A plugin that bundles
`src/` without Composer gets a `RuntimeException` from `autoload.php`
naming the running version, instead of a parse error later.

Sites that cannot move off PHP 8.0/8.1 stay on 0.9.x. They will not get
the fixes below, which is the trade being made.

### 1. Switch the scan path to `checkSpamOrHam()`

This is the whole point of the release. `checkSpam()` still exists and
still throws; `checkSpamOrHam()` never does.

```diff
-try {
-    $response = $client->checkSpam($request);
-} catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
-    $this->logger->warning($e->getMessage());
-    return $content;                       // fail open
-} catch (\Throwable $t) {                  // easy to forget — and fatal when you do
-    return $content;
-}
+$response = $client->checkSpamOrHam($request);
+
+if ($response->wasSkipped()) {
+    $this->logger->warning('spamtroll skipped: {reason} {error}', [
+        'reason' => $response->getSkipReason(),
+        'error'  => $response->error ?? '',
+    ]);
+    return $content;                       // fail open
+}
```

`getFailure()` returns the underlying `\Throwable` when the skip came
from a transport failure, so nothing is lost from your logs.

### 2. `wasSkipped()` now means "no verdict", not "HTTP 402"

If you branch on it, re-read that branch. It used to be a synonym for
`isQuotaExceeded()`; it now covers transport failures, 402, 429, every
4xx, and a 2xx whose body carried no classification.

- Code that did `if ($response->wasSkipped()) { /* quota hint in the UI */ }`
  should switch to `isQuotaExceeded()`.
- Code that did `if (!$response->success) { fail open }` can now use
  `wasSkipped()` and get the unparseable-2xx case for free.

`getSkipReason()` gained values: `transport_error`, `rate_limited`,
`payment_required`, `unparseable_response`, `http_<code>`, and any
lower-cased backend error code. That set is **open** — do not `switch` on
it and do not constrain a column to it.

For control flow and storage use `getSkipCategory()` instead, which is a
closed set of seven: `not_configured`, `auth`, `quota`, `rate_limit`,
`rejected`, `transport`, `no_verdict` (and `''` when there is a verdict).
Each one selects a different action — see the table in
[ERROR_HANDLING.md](docs/ERROR_HANDLING.md).

If you already maintain your own enum of post-call failure reasons, map it
onto these seven and drop that part of your dictionary; the point is that
the six integrations share one. What the SDK **cannot** express is any
reason you never called it at all — content exempted, empty body, your own
circuit breaker already open. Those stay yours, because no value derived
from a response can carry them. Keep them, and keep them distinguishable
from `transport`: "the breaker is open" and "the API is down" look the
same in a log and mean different things.

### 3. `getStatus()` is the verdict; the score is display

`getStatus()` now returns `safe` whenever there is no verdict, and
`getSpamScore()` / `getRawSpamScore()` return `0.0` in the same case.
Reading `getStatus()` off an unsuccessful response used to hand back
whatever `data.status` happened to contain.

Replace re-derived verdicts with the server's:

```diff
-if ($response->getSpamScore() >= 0.7) {
+if ($response->shouldBlock()) {
     $this->block($content);
-} elseif ($response->getSpamScore() >= 0.4) {
+} elseif ($response->shouldModerate()) {
     $this->moderate($content);
 }
```

The thresholds live in the platform's backend settings. A hard-coded
cut-off in a plugin drifts away from them the moment an admin changes
one.

### 4. Latency defaults changed — check what you pass

| | 0.9.x | 0.10.0 |
|---|---:|---:|
| `timeout` | 5 | 3 |
| `maxRetries` | 3 | 2 |
| `retryBaseDelayMs` | 500 | 250 |
| `totalBudgetMs` | — | 6000 |

Two things to check:

1. **If you pass `ClientConfig::DEFAULT_*` constants** (the Joomla and
   phpBB factories do), your effective values change with no code change.
   That is usually what you want; confirm it is.
2. **If you let an admin configure a timeout above 6 seconds** (WordPress
   allows up to 30, IPS up to 10), the new `totalBudgetMs` ceiling clamps
   each attempt to what is left of 6000 ms, silently overriding that
   setting. Either lower the maximum the UI offers, or pass a budget that
   matches it:

   ```php
   new ClientConfig(
       timeout: $adminTimeout,
       maxRetries: 2,
       totalBudgetMs: $adminTimeout * 2 * 1000 + 500,
   );
   ```

   Pass `totalBudgetMs: 0` to opt out entirely — appropriate for a queue
   worker, never for a request a visitor is waiting on.

### 5. `baseUrl` must be http(s)

`new ClientConfig(baseUrl: $adminSuppliedValue)` now throws
`InvalidConfigurationException` for anything that is not `http://` or
`https://`, including an empty string. Factories that pass an admin
setting straight through (Joomla, phpBB, IPS, WordPress) need either a
validated settings field or a catch:

```php
try {
    $config = new ClientConfig(baseUrl: $adminUrl, /* … */);
} catch (\Spamtroll\Sdk\Exception\InvalidConfigurationException $e) {
    // Show the admin a notice, and let the content through.
    return $content;
}
```

`InvalidConfigurationException` extends `SpamtrollException`, so an
existing single catch already covers it — but a factory that runs
*outside* your try block does not.

### 6. `getAccountUsage()` and `UsageResponse` are gone

They called an endpoint the backend does not have, and returned zeros.
If you displayed those numbers, you were displaying zeros.

The only quota data an API key can see comes from a 402 response:

```php
if ($response->isQuotaExceeded()) {
    $usage = $response->getQuotaUsage();   // current, limit, plan, reset_at
}
```

### 7. Value objects are `readonly`

`ClientConfig`, `Response`, `CheckSpamResponse`, `HttpResponse` and
`CheckSpamRequest` no longer allow assignment after construction. If you
mutated one — `$config->baseUrl = …`, or rewriting `$request->content`
before a second call — build a new instance instead.

### 8. `$response->error` and `getRequestId()` start working

Not a break, but it will change what your logs and admin screens show:

- `$response->error` used to be the string `"1"` for every rate-limited
  request and a raw JSON blob for validation errors. It is now the
  backend's actual message.
- `getRequestId()` used to return `null` every time. It now returns the
  identifier from `error.request_id`.
- New: `getErrorCode()`. Branch on that rather than on the message text.

If you assert on those strings in your plugin's tests, the assertions
need updating — and they were asserting the bug.

### 9. Adapters: check redirects

`HttpClientInterface` now requires that adapters never follow redirects.
`docs/HTTP_ADAPTERS.md` has corrected reference implementations for
WordPress (`'redirection' => 0`), IPS (`request($timeout, null, 0)`) and
Guzzle (`'allow_redirects' => false`). If your adapter was copied from
the old docs, it forwards `X-API-Key` to whatever host a `Location`
header names, and platform keys are revocable only by rotation.

Adapters must also throw rather than returning `statusCode` 0, and must
let nothing outside the `SpamtrollException` hierarchy escape `send()`.

### 10. New request fields for mail integrations

If you scan e-mail, you now have the fields the backend actually wants:

```php
new CheckSpamRequest(
    content: $plainTextBody,
    source: CheckSpamRequest::SOURCE_EMAIL,   // switches the RETVec model
    rawMessage: $rfc822Message,               // enables real DKIM verification
    headers: $messageHeaders,
);
```

Passing `source: 'email'` as a raw string already worked; the constant
just makes it discoverable. `rawMessage` is the substantive change —
without it the auth stage falls back to header-only checking, which an
attacker defeats by pasting a `DKIM-Signature` header.

## From zero — initial adopters (IPS + WordPress plugins)

Both host plugins previously shipped their own copies of the API client
(`IPS\spamtroll\Api\Client` / `Spamtroll_Api_Client`). Migration notes
live in each plugin's `CHANGELOG.md` under the version that adopted the
SDK.

### Known behavioural changes vs the pre-SDK clients

- **Score normalization.** The WordPress plugin previously divided the raw
  score by `15.0`; the SDK uses `30.0` (matching the IPS plugin's
  mapping). Raw score `15` normalizes to `0.5` (was `1.0` in WP). As of
  0.10.0 this only affects display — the verdict comes from `getStatus()`.
- **Exception namespaces.** Hosts catch
  `Spamtroll\Sdk\Exception\SpamtrollException` (or its subclasses)
  instead of `IPS\spamtroll\Api\Exception` / `Spamtroll_Api_Exception` —
  or, better, call `checkSpamOrHam()` and catch nothing.
- **HTTP transport.** The host plugin supplies an `HttpClientInterface`
  adapter. The IPS adapter delegates to `\IPS\Http\Url`; the WP adapter
  delegates to `wp_remote_*` — both honor their platform's request
  filters.
