# Error handling

The SDK splits errors into two camps:

1. **Exceptions** — something prevented the SDK from getting a
   meaningful answer. The host plugin almost always wants to log and
   *fail open* (let the content through, don't punish the user for an
   API problem).
2. **Unsuccessful `Response` objects** — the API returned a
   well-formed answer that says "no" (rate-limited, invalid input,
   429, miscellaneous 4xx). The host plugin can decide whether to
   back off, retry later, or surface the error to the admin.

## Exception hierarchy

```
RuntimeException
└── Spamtroll\Sdk\Exception\SpamtrollException        (catch-all base)
    ├── NotConfiguredException                        (empty API key — programmer error)
    ├── AuthenticationException                       (HTTP 401 — bad / expired API key)
    ├── ServerException                               (HTTP 5xx after exhausting retries)
    └── ConnectionException                           (network failure after retries)
        └── TimeoutException                          (request exceeded `timeout`)
```

If you only want a single catch path, catch `SpamtrollException`. Every
SDK error inherits from it.

## When does the SDK throw?

| Trigger | Exception | Retried? |
|---|---|---|
| Empty API key | `NotConfiguredException` | no |
| Connection error (DNS, refused, TLS) | `ConnectionException` | yes (`maxRetries`) |
| Timeout | `TimeoutException` | yes (`maxRetries`) |
| HTTP 401 | `AuthenticationException` | no |
| HTTP 5xx | `ServerException` | yes (`maxRetries`); thrown only if every attempt fails |
| `json_encode` of the request body fails | `SpamtrollException` (base) | no |

## When does the SDK return a Response with `success=false`?

| Trigger | Response state |
|---|---|
| HTTP 429 (rate-limited) | `success=false`, `httpCode=429`, `error="rate limited"` (or whatever the body said) |
| Other HTTP 4xx (400, 403, 404, etc.) | `success=false`, `httpCode=4xx`, `error=<message>` |

These are intentionally **not** thrown so that callers can react
without setting up `try/catch`. A WordPress comment hook can simply do:

```php
$response = $client->checkSpam($request);
if (!$response->success) {
    error_log('spamtroll: ' . $response->error);
    return $commentdata; // fail open
}
```

## Recommended fail-open pattern

For every host integration we ship, the rule is: **never block content
on an SDK exception**. Spamtroll going down should not take down a
forum. The reference pattern from the IPS plugin:

```php
try {
    $response = \IPS\spamtroll\Application::apiClient()
        ->checkSpam($request);

    if (!$response->success) {
        \IPS\Log::log('Spamtroll API error: ' . ($response->error ?? '?'), 'spamtroll');
        return $result; // post stays visible
    }

    // Apply hide() / moderate() / log() based on score.
} catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
    \IPS\Log::log('Spamtroll API exception: ' . $e->getMessage(), 'spamtroll');
} catch (\Throwable $t) {
    \IPS\Log::log('Spamtroll hook error: ' . $t->getMessage(), 'spamtroll');
}

return $result;
```

The `\Throwable` catch is the safety net — even if the SDK throws
something we didn't anticipate (e.g. an `Error` from a
hypothetical PHP-level fault), the hook still lets the post through.

## Inspecting an exception

`SpamtrollException` exposes three public fields beyond what
`RuntimeException` gives you:

```php
$e->httpCode;       // int  — 401, 5xx, or 0 for connection-level errors
$e->apiErrorCode;   // ?string — server-supplied code, e.g. 'INVALID_API_KEY'
$e->responseData;   // ?array<string, mixed> — full decoded body for log/debug
```

`AuthenticationException::invalidApiKey()` always returns
`apiErrorCode = 'INVALID_API_KEY'`. `NotConfiguredException::create()`
always returns `apiErrorCode = 'NOT_CONFIGURED'`. Other factory methods
(`ConnectionException::fromMessage`, `TimeoutException::afterSeconds`)
leave `apiErrorCode` null because the failure happened below the
application layer.

## Don't catch what you can't recover from

`AuthenticationException` is a configuration problem — catching it and
silently retrying is bad. Surface it to the admin (an admin notice in
WP, a flash message in IPS) so they fix the API key. Same for
`NotConfiguredException`: it means the host plugin tried to scan
before the admin entered a key.

The retryable failures (`Connection`, `Timeout`, `Server`) are already
retried inside the SDK. By the time they bubble out, retries are
exhausted — your job is to log and fail open, not to retry again.
