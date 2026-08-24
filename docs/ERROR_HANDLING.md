# Error handling

## The short version

Call `checkSpamOrHam()`. It never throws. Branch on `wasSkipped()`.

```php
$response = $client->checkSpamOrHam($request);

if ($response->wasSkipped()) {
    error_log(sprintf(
        'spamtroll: skipped (%s) %s',
        $response->getSkipReason(),
        $response->error ?? '',
    ));
    return $content; // fail open
}

if ($response->shouldBlock()) {
    return $this->reject($content);
}
if ($response->shouldModerate()) {
    return $this->queueForModeration($content);
}
return $content;
```

Everything below is for callers who use `checkSpam()` instead and want to
handle the exception hierarchy themselves.

## Why the SDK owns fail-open

Spamtroll going down must not take a forum down with it. That rule used
to live in six plugins, each of which had to remember a `try/catch`
around the scan, catch `\Throwable` and not just `SpamtrollException`,
and treat "no answer" as ham rather than as spam. A rule enforced by six
separate memories fails eventually, and it fails silently — the defect
only shows during an API outage.

Since 0.10.0 it lives in one place. `checkSpamOrHam()` catches
`\Throwable` and returns `CheckSpamResponse::failOpen()`, and
`shouldBlock()` is true only for an explicit `blocked` verdict from a
successful scan. There is no path through the SDK that turns a failure
into a block.

## Skip categories — switch on these

`getSkipCategory()` returns one of **eight** values (plus the empty string
when a verdict was reached), and the set is closed. Every skipped response
maps to exactly one, and a backend error code that did not exist when your
plugin shipped lands in an existing bucket rather than inventing a
ninth. Use it for control flow and for anything you persist.

The axis is cut by *what you should do about it*. Each row selects a
different action; no two select the same one. That is the test a category
has to pass to exist.

| Category | Constant | When | What a plugin should do |
|---|---|---|---|
| `not_configured` | `SKIP_CATEGORY_NOT_CONFIGURED` | No API key; an unusable base URL | Nothing. Do not warn, do not count it against a circuit breaker — the site simply is not set up yet |
| `auth` | `SKIP_CATEGORY_AUTH` | HTTP 401 (key rejected), 403 (account blocked, platform disabled) | Stop calling immediately and tell the **site owner**, pointing at the key or the dashboard. Permanent until a human acts, so retrying is waste |
| `quota` | `SKIP_CATEGORY_QUOTA` | HTTP 402 — the account is out of scans | Fail open and tell the admin; `getQuotaUsage()` has the numbers |
| `rate_limit` | `SKIP_CATEGORY_RATE_LIMIT` | HTTP 429 | Fail open and back off |
| `rejected` | `SKIP_CATEGORY_REJECTED` | Any other 4xx: 400, 404, 422 | Fail open, log for the **developer**. This normally means the caller sent something wrong — it is not an admin's problem |
| `redirected` | `SKIP_CATEGORY_REDIRECTED` | Any 3xx. The SDK never follows redirects, so you see them | Fail open and tell the **site owner their API URL redirects** — normally `http://` where `https://` was meant, or a missing/extra trailing slash. Do not retry: it will answer the same way forever |
| `transport` | `SKIP_CATEGORY_TRANSPORT` | No answer at all: DNS, timeout, refused, 5xx after retries, an adapter that threw | Fail open, log, count it against your circuit breaker if you have one |
| `no_verdict` | `SKIP_CATEGORY_NO_VERDICT` | HTTP 2xx with no classification in the body | Fail open and log; suspect a proxy or captive portal |
| — | `SKIP_CATEGORY_NONE` (`''`) | A verdict was reached | Act on `shouldBlock()` / `shouldModerate()` |

Note what is deliberately **not** grouped together:

- **`not_configured` is not `transport`.** A fresh install with no key is
  not an outage. Sharing a bucket means a circuit breaker opens against a
  key that does not exist and every unconfigured site shows "cannot reach
  the API".
- **`auth` is not `transport`.** A 401 is not the absence of an answer; it
  is a very specific answer that will not change until someone edits the
  key. Sending that admin to their hosting provider is the wrong outcome.
- **`auth` is not `rejected`.** 403 (account blocked) is fixed by the site
  owner in the dashboard; 422 is fixed by the plugin's developer in code.
  Different person, different urgency, different channel.
- **`redirected` is not `no_verdict`.** Both mean something sits between you
  and the API, but a 3xx names a URL the owner can correct and will repeat
  forever; an unparseable 2xx is usually a captive portal or a proxy the
  owner may not control, and it often clears on its own. Different notice,
  different retry posture.

Categories are keyed on the SDK's exception hierarchy and the HTTP status,
never on whether a call happened to throw — that is an internal detail of
`Client::dispatch()` and it is not something a plugin should inherit.

## Skip reasons — log these

`getSkipReason()` is the detailed string behind the category, and its set
is **open**: it carries the backend's own error code, lower-cased, and the
backend may add codes at any time. That makes it right for a log line and
wrong for a `switch` or a narrow database column. Log it verbatim.

| Reason | Meaning |
|---|---|
| `transport_error` | The call itself failed: no API key, DNS, refused connection, timeout, 401, 5xx after retries, or an adapter that threw something unexpected. `getFailure()` has the throwable. |
| `quota_exceeded` | HTTP 402. The account's daily scan quota is spent. `getQuotaUsage()` returns `{current, limit, plan, reset_at}` for your admin UI. |
| `payment_required` | HTTP 402 without a recognised code. |
| `rate_limited` | HTTP 429. Back off; the backend allows 100 scans/minute per API key. |
| `validation_error`, `forbidden`, `not_found`, … | Lower-cased `error.code` from a 4xx. |
| `http_<code>` | A 4xx with no code in the body. |
| `unparseable_response` | HTTP 2xx whose body carried no verdict — an HTML error page, an empty body, a captive portal. |

## Exception hierarchy

```
RuntimeException
└── Spamtroll\Sdk\Exception\SpamtrollException        (catch-all base)
    ├── NotConfiguredException                        (empty API key — programmer error)
    ├── InvalidConfigurationException                 (baseUrl is not http(s))
    ├── AuthenticationException                       (HTTP 401 — bad / expired API key)
    ├── ServerException                               (HTTP 5xx after exhausting retries)
    └── ConnectionException                           (network failure after retries)
        └── TimeoutException                          (request exceeded `timeout`)
```

If you only want a single catch path, catch `SpamtrollException` — but
add `catch (\Throwable)` as well. A host HTTP adapter can leak its own
platform's exception types, and an `Error` from a broken adapter must not
block a comment either. `checkSpamOrHam()` does both for you.

## When does `checkSpam()` throw?

| Trigger | Exception | Retried? |
|---|---|---|
| Empty API key | `NotConfiguredException` | no |
| `baseUrl` is not http(s) | `InvalidConfigurationException` (thrown from `ClientConfig`) | no |
| Connection error (DNS, refused, TLS) | `ConnectionException` | yes (`maxRetries`) |
| Timeout | `TimeoutException` | yes (`maxRetries`) |
| Adapter reported no HTTP status | `ConnectionException` | yes (`maxRetries`) |
| HTTP 401 | `AuthenticationException` | no |
| HTTP 5xx | `ServerException` | GET: `maxRetries`. POST: one retry. Thrown only if every attempt fails |

## When does it return a Response with `success=false`?

| Trigger | Response state |
|---|---|
| HTTP 402 (quota exhausted) | `httpCode=402`, `wasSkipped()`, `isQuotaExceeded()`, `getQuotaUsage()` |
| HTTP 429 (rate limited) | `httpCode=429`, `wasSkipped()`, `getSkipReason() === 'rate_limited'` |
| Other HTTP 4xx (400, 403, 404, 422) | `httpCode=4xx`, `wasSkipped()`, `getErrorCode()` set when the backend sent one |

These are intentionally not thrown, so the caller can react without a
`try/catch`. None of them can produce a blocking verdict.

## Reading the error

The backend returns **three** different error bodies, and the SDK
normalises all of them:

```json
{"success": false, "error": {"code": "VALIDATION_ERROR", "message": "Content is required", "request_id": "…"}}
{"success": false, "error": {"code": "QUOTA_EXCEEDED", "message": "Daily scan limit reached.", "usage": {…}}}
{"error": true, "message": "Rate limit exceeded. Maximum 100 requests per minute."}
```

```php
$response->error;              // ?string — human-readable text, from any of the three shapes
$response->getErrorCode();     // ?string — 'VALIDATION_ERROR', 'QUOTA_EXCEEDED', … or null
$response->getRequestId();     // ?string — from error.request_id; quote it in support tickets
$response->getMessage();       // ?string — human-readable status message
```

Branch on `getErrorCode()`, never on `$response->error`: the text is
prose the backend can reword at any time.

> Before 0.10.0 `$response->error` was the string `"1"` for every
> rate-limited request (the legacy envelope's `error` field is the boolean
> `true`), and a raw JSON blob for the standard envelope. If your plugin
> logs or displays `$response->error`, it will start showing something
> readable.

## Inspecting an exception

`SpamtrollException` exposes three public fields beyond what
`RuntimeException` gives you:

```php
$e->httpCode;       // int  — 401, 5xx, or 0 for connection-level errors
$e->apiErrorCode;   // ?string — server-supplied code, e.g. 'INTERNAL_ERROR'
$e->responseData;   // ?array<string, mixed> — full decoded body for log/debug
```

## Don't catch what you can't recover from

`AuthenticationException` is a configuration problem. `checkSpamOrHam()`
correctly fails open on it — content must not be blocked because an admin
pasted the wrong key — but the admin still needs to be told. Surface a
`transport_error` skip with a 401 behind it as an admin notice, don't
just let it scroll past in a log.

Same for `NotConfiguredException`: it means the plugin tried to scan
before anyone entered a key. Use `isConfigured()` to skip the call
entirely and show a setup prompt.

## Untrusted output

`$response->error`, `getSymbols()` and `getThreatCategories()` are
**server-supplied strings**. The SDK truncates them (512 characters for
error text, 128 for labels), but it does not escape them. Escape them
before rendering in HTML — they end up in moderation panels.
