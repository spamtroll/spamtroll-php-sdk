# Response schema

The SDK ships three response types, all extending the base
`Spamtroll\Sdk\Response\Response`:

- `Response` — generic envelope, returned by `testConnection()`.
- `CheckSpamResponse` — `/scan/check` payload, returned by `checkSpam()`.
- `UsageResponse` — `/account/usage` payload, returned by `getAccountUsage()`.

All three expose the same four base properties:

```php
public bool $success;          // round-trip succeeded AND HTTP 2xx
public int $httpCode;          // HTTP status code from the server
public array $data;            // decoded JSON body (raw)
public ?string $error;         // non-null when success === false
```

`$success` is the headline boolean. It says **the SDK got a usable
answer from the server**. A 4xx with `success=false` still means the
server replied — it just replied "no". A 5xx after retries doesn't
even reach this layer, it throws (see [ERROR_HANDLING.md](ERROR_HANDLING.md)).

## CheckSpamResponse

Returned by `checkSpam()`. The most common case in production code.

### Status enum

`getStatus(): string` returns one of three values:

| Constant | String | Meaning |
|---|---|---|
| `STATUS_BLOCKED` | `"blocked"` | Score is at or above the spam threshold. The SDK considers this definitively spam. |
| `STATUS_SUSPICIOUS` | `"suspicious"` | Score is between the suspicious and spam thresholds. Recommended action: send to moderation. |
| `STATUS_SAFE` | `"safe"` | Below the suspicious threshold. Recommended action: allow. |

The server makes this call. The SDK does not recompute the status from
the score — `getStatus()` reads `data.status` directly.

### Score normalisation

The Spamtroll backend scores on an open-ended additive scale where the
configured spam threshold (default 15) means "definitely spam". The
SDK normalises this into `0.0–1.0`:

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
returns the raw native scale if you need to display it. Use
`scoreDenominator` in `ClientConfig` to tune the mapping.

### Symbols and threat categories

Detection symbols are the rules that fired during the scan. Each
symbol may come back as a plain string (`"BAYES_SPAM_HIGH"`) or as an
object with a name and a score:

```json
{
  "symbols": [
    "RBL_STOPFORUMSPAM",
    {"name": "BAYES_SPAM_HIGH", "score": 4.5},
    {"name": "KEYWORD_SPAM_MATCH", "score": 1.2}
  ]
}
```

`getSymbols()` flattens to a `string[]` of names —
`['RBL_STOPFORUMSPAM', 'BAYES_SPAM_HIGH', 'KEYWORD_SPAM_MATCH']` — for
quick display. `getSymbolDetails()` returns the full mixed array if
you need scores for debugging.

`getThreatCategories()` returns a string list like `['phishing',
'malware']` — high-level grouping useful for admin filters.

### Identifiers

| Method | Source | What it is |
|---|---|---|
| `getSubmissionId()` | `data.data.submission_id` | UUID assigned to this scan. Use it to correlate a forum log entry with a scan in the Spamtroll backend. |
| `getRequestId()` | `data.request_id` (top level) | Optional request-tracing identifier. |
| `getMessage()` | `data.message` (top level) | Optional human-readable status message. |

### Convenience predicates

```php
$response->isSpam();              // success && status === 'blocked'
$response->isConnectionValid();   // success && 200 <= httpCode < 300
```

### Envelope handling

The API can return either:

```json
{ "success": true, "data": { "status": "blocked", "spam_score": 18, ... } }
```

or, for legacy/flat responses:

```json
{ "status": "blocked", "spam_score": 18, ... }
```

`CheckSpamResponse` handles both transparently — it inspects
`data.success`, and if true and `data.data` is an array, unwraps;
otherwise it falls back to the whole payload. Callers don't need to
care.

## UsageResponse

Returned by `getAccountUsage()`. Three fields:

```php
$response->getRequestsToday();      // int
$response->getRequestsLimit();      // int (per-day quota)
$response->getRequestsRemaining();  // int
$response->toArray();               // array{requests_today:int, requests_limit:int, requests_remaining:int}
```

The SDK reads these from either the top level of the body or the
nested `data` envelope, same as `CheckSpamResponse`.

## Response (base)

Returned by `testConnection()`. No domain getters — just the four base
fields plus:

```php
$response->isConnectionValid();   // success && 200 <= httpCode < 300
$response->getRequestId();        // ?string from data.request_id
$response->getMessage();          // ?string from data.message
```

Use `isConnectionValid()` from your admin "Test Connection" button:

```php
$response = $client->testConnection();
if ($response->isConnectionValid()) {
    echo "API is reachable.";
} else {
    echo "Failed: " . ($response->error ?? 'unknown');
}
```
