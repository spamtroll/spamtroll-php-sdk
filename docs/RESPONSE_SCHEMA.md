# Response schema

The SDK ships two response types:

- `Response` — generic envelope, returned by `testConnection()`.
- `CheckSpamResponse` — `/scan/check` payload, returned by
  `checkSpamOrHam()` and `checkSpam()`.

Both expose the same base properties:

```php
public bool $success;          // round-trip succeeded AND HTTP 2xx
public int $httpCode;          // HTTP status code from the server (0 = never got one)
public array $data;            // decoded JSON body (raw)
public ?string $error;         // human-readable error text, non-null when success === false
public ?string $errorCode;     // machine-readable backend code, when the backend sent one
```

All of them are `readonly`.

## CheckSpamResponse

### The verdict comes from the server

`getStatus()` returns one of three values, and it is **the** decision:

| Constant | String | Meaning |
|---|---|---|
| `STATUS_BLOCKED` | `"blocked"` | At or above the platform's spam threshold. Block it. |
| `STATUS_SUSPICIOUS` | `"suspicious"` | Between the suspicious and spam thresholds. Send to moderation. |
| `STATUS_SAFE` | `"safe"` | Below the suspicious threshold. Allow. |

The server applies the platform's configured thresholds, which the SDK
does not know — they are per-platform settings in the backend database,
not constants. **Do not re-derive a verdict from `getSpamScore()`.** Four
plugins did exactly that before 0.10.0, each with its own hard-coded
cut-off, and each drifted away from the platform's real configuration.

Predicates, in the shape a plugin actually needs:

```php
$response->hasVerdict();      // did the server classify this at all?
$response->shouldBlock();     // status === 'blocked'
$response->shouldModerate();  // status === 'suspicious'
$response->isBlocked();       // same as shouldBlock()
$response->isSuspicious();
$response->isSafe();
$response->isSpam();          // alias of isBlocked(), kept for readability
```

`getStatus()` returns `safe` whenever `hasVerdict()` is false. That is
the fail-open default, and it means a plugin that only checks
`shouldBlock()` is already correct on every failure path.

### Skips

```php
$response->wasSkipped();      // exact inverse of hasVerdict()
$response->getSkipCategory(); // closed set of five: switch on this, store this
$response->getSkipReason();   // open string: 'transport_error', 'quota_exceeded', … — log this
$response->getFailure();      // ?\Throwable — set when the call itself failed
$response->isQuotaExceeded(); // HTTP 402 with error.code = QUOTA_EXCEEDED
$response->getQuotaUsage();   // ['current' => 200, 'limit' => 200, 'plan' => 'free', 'reset_at' => …]
```

See [ERROR_HANDLING.md](ERROR_HANDLING.md) for the full table of skip
reasons.

### Score normalisation

The backend scores on an open-ended additive scale where the configured
spam threshold (default 15) means "definitely spam". The SDK normalises
into `0.0–1.0`:

```
normalised = min(1.0, raw / scoreDenominator)
```

| Raw | Normalised (denominator 30) |
|---:|---:|
| 0 | 0.00 |
| 7.5 | 0.25 |
| 15 | 0.50 |
| 22.5 | 0.75 |
| 30+ | 1.00 |

`getSpamScore()` returns the normalised value; `getRawSpamScore()`
returns the raw scale. Both return `0.0` when there is no verdict.

**These are display values.** Show them in a moderation UI, use them to
sort a queue, put them in a log line. Do not use them to decide whether
to block — that is `getStatus()`.

### Symbols and threat categories

Detection symbols are the rules that fired during the scan. Each symbol
may come back as a plain string or as an object with a name and a score:

```json
{
  "symbols": [
    "RBL_STOPFORUMSPAM",
    {"name": "BAYES_SPAM_HIGH", "score": 4.5, "category": "bayes"}
  ]
}
```

`getSymbols()` flattens to a `string[]` of names for quick display.
`getSymbolDetails()` returns the full mixed array if you need scores.
`getThreatCategories()` returns the categories of the symbols that scored
above zero (`ip`, `content`, `bayes`, …) — the backend iterates a map to
build it, so **the order is not stable**. Both return `[]` when the call
was not successful.

> Symbol and category names are server-supplied strings. The SDK
> truncates them to 128 characters, but does **not** escape them. Escape
> before rendering in HTML.

### Identifiers

| Method | Source | What it is |
|---|---|---|
| `getSubmissionId()` | `data.submission_id` | UUID of the stored scan. **May be null**: the field is `omitempty`, and the backend still returns 200 when it could not persist the submission. |
| `getRequestId()` | `error.request_id`, then top-level `request_id` | Request-tracing identifier. Quote it in support tickets. |
| `getMessage()` | `error.message`, then `message`, then `data.message` | Human-readable status message. |
| `getErrorCode()` | `error.code` | Machine-readable code. Branch on this. |

> Before 0.10.0 `getRequestId()` looked only at the top level, where the
> backend never puts it — so it returned `null` every time.

### Envelope handling

The API can return either:

```json
{ "success": true, "data": { "status": "blocked", "spam_score": 18 } }
```

or, for legacy/flat responses:

```json
{ "status": "blocked", "spam_score": 18 }
```

`CheckSpamResponse` handles both: it inspects `data.success`, and if true
and `data.data` is an array, unwraps; otherwise it falls back to the
whole payload.

## Response (base)

Returned by `testConnection()`.

```php
$response->isConnectionValid();   // success && 200 <= httpCode < 300
$response->getRequestId();
$response->getMessage();
$response->getErrorCode();
```

`testConnection()` throws, because an admin clicking "Test Connection"
needs to be told what is wrong — and the most common reason for clicking
it, a bad API key, is an `AuthenticationException`. Wrap it:

```php
try {
    $response = $client->testConnection();
    echo $response->isConnectionValid()
        ? 'API is reachable.'
        : 'Failed: ' . ($response->error ?? 'unknown');
} catch (\Spamtroll\Sdk\Exception\AuthenticationException $e) {
    echo 'Invalid API key.';
} catch (\Throwable $e) {
    echo 'Could not reach the API: ' . $e->getMessage();
}
```
