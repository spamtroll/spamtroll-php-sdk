# Usage

The SDK exposes a single entry point: `Spamtroll\Sdk\Client`. Every
operation goes through it.

## Quick start

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Request\CheckSpamRequest;

$client = new Client('your-api-key');

$response = $client->checkSpamOrHam(new CheckSpamRequest(
    content: $commentBody,
    source: CheckSpamRequest::SOURCE_COMMENT,
    ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
    username: $authorName,
    email: $authorEmail,
));

if ($response->wasSkipped()) {
    // No verdict — API unreachable, quota exhausted, rate limited.
    // Let the content through and log why.
    error_log('spamtroll: skipped (' . $response->getSkipReason() . ') ' . ($response->error ?? ''));
} elseif ($response->shouldBlock()) {
    // Server verdict: blocked.
} elseif ($response->shouldModerate()) {
    // Server verdict: suspicious — send to the moderation queue.
}
```

`checkSpamOrHam()` **never throws**. Whatever goes wrong — no API key,
DNS failure, timeout, 401, 5xx, quota exhausted, a captive portal
answering with HTML — you get a `CheckSpamResponse` that reports
`wasSkipped() === true` and `shouldBlock() === false`.

That is the whole point: **only a successful scan can produce a blocking
verdict**. An integration written against this method cannot fail closed
by forgetting a `catch`.

`checkSpam()` still exists and still throws, for callers that want to
handle the exception hierarchy themselves. If you use it, catch
`\Throwable` and let the content through — see
[ERROR_HANDLING.md](ERROR_HANDLING.md).

## With a custom configuration

```php
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;

$client = new Client(
    apiKey: 'your-api-key',
    config: new ClientConfig(
        timeout: 3,
        maxRetries: 2,
        retryBaseDelayMs: 250,
        totalBudgetMs: 6000,
        userAgent: 'my-plugin/1.2.3 spamtroll-php-sdk/' . \Spamtroll\Sdk\Version::VERSION,
        scoreDenominator: 30.0,
    ),
);
```

See [CONFIGURATION.md](CONFIGURATION.md) for what every field does, the
worst-case latency each preset implies, and when to deviate.

## With a custom HTTP transport

```php
use Spamtroll\Sdk\Client;

$client = new Client(
    apiKey: 'your-api-key',
    http: $myHttpAdapter, // implements HttpClientInterface
);
```

This is the integration point for WordPress (`wp_remote_*`) and IPS
(`\IPS\Http\Url`). See [HTTP_ADAPTERS.md](HTTP_ADAPTERS.md) for the
contract — including the rule that adapters must never follow redirects —
and reference implementations.

## All Client methods

| Method | Returns | What it does |
|---|---|---|
| `checkSpamOrHam(CheckSpamRequest)` | `CheckSpamResponse` | **The method integrations should call.** Submits content to `/scan/check` and never throws; every failure becomes a skipped, non-blocking response. |
| `checkSpam(CheckSpamRequest)` | `CheckSpamResponse` | Same call, but throws on anything that prevents a verdict. Wrap it in `try/catch (\Throwable)`. |
| `trySubmitFeedback(FeedbackRequest)` | `Response` | Sends a moderator's correction to `/scan/feedback`. Never throws. |
| `submitFeedback(FeedbackRequest)` | `Response` | Same call, throwing variant. |
| `testConnection()` | `Response` | Hits `/scan/status` with a GET. For admin "Test Connection" buttons. Throws — an invalid key must be reported, not swallowed, so wrap it. |
| `isConfigured()` | `bool` | True if the API key is non-empty. Cheap, no network. |
| `getConfig()` | `ClientConfig` | The active configuration object. |

There is no account-usage method. The backend has no usage endpoint
reachable with an API key — `/api/v1/billing/usage` requires a JWT. The
only quota information an integration can see is the `usage` block inside
a 402 response, available through `getQuotaUsage()`.

## CheckSpamRequest fields

| Field | Required | Notes |
|---|---|---|
| `content` | yes | Plain-text body. Strip HTML before passing. Truncated to 64 KiB (`MAX_CONTENT_BYTES`); `isContentTruncated()` tells you when that happened. |
| `source` | yes (default `generic`) | See the table below. It changes how the backend scores the content, so getting it right matters. |
| `ipAddress` | **strongly recommended** | The author's IP. **Omit it and the backend substitutes the IP the request came from — your own web server.** Every IP-based stage then scores your server instead of the spammer, your server accumulates the reputation, and `hosting_check` adds points to every single scan because your host is in a datacentre. |
| `username` | no | Author display name. Used by the registration stage. |
| `email` | no | Author email. Used for blocklist correlation. |
| `rawMessage` | mail integrations: yes | The full RFC 822 message, **byte for byte** — no CRLF normalisation, no header rewriting. This is what enables real cryptographic DKIM verification. Without it the auth stage falls back to header-only checking, which an attacker defeats by pasting any `DKIM-Signature` header. |
| `headers` | mail integrations: recommended | E-mail headers (`From`, `Subject`, `List-Id`, `Authentication-Results`, …) as a `array<string, string>`. These are message headers, not HTTP headers. |

### Source constants

| Constant | Value | Effect on the backend |
|---|---|---|
| `SOURCE_EMAIL` | `email` | Switches RETVec to the **e-mail** model. Mail integrations must use this; anything else gets the web model and a systematically shifted classification. |
| `SOURCE_REGISTRATION` | `registration` | Skips content analysis, RETVec and Bayes, and skips the AI stage entirely. |
| `SOURCE_COMMENT` | `comment` | Web model, full pipeline. |
| `SOURCE_FORUM` | `forum` | Web model, full pipeline. |
| `SOURCE_CONTACT_FORM` | `contact_form` | Web model, full pipeline. |
| `SOURCE_MESSAGE` | `message` | Web model. Not branched on by the backend today; may drive per-source symbol weights. |
| `SOURCE_GENERIC` | `generic` | Default. Same as above. |

`CheckSpamRequest::toArray()` returns the canonical wire format —
`content`, `source`, plus any non-empty optional fields. Empty optional
fields are *omitted*, not sent as empty strings.

## Moderator feedback

When a human overrules a verdict, tell the backend. This is the only
route by which a wrong classification is corrected, and a `spam` label
also trains the platform's Bayes classifier.

```php
use Spamtroll\Sdk\Request\FeedbackRequest;

// $submissionId came from CheckSpamResponse::getSubmissionId().
$result = $client->trySubmitFeedback(
    FeedbackRequest::spam($submissionId, 'moderator marked as spam'),
);

if (!$result->success) {
    error_log('spamtroll: feedback not recorded — ' . ($result->error ?? ''));
}
```

`getSubmissionId()` can be `null`: the field is `omitempty`, and the
backend still answers 200 when it could not persist the submission.
Store it when it is there, and hide the "report" button when it is not.

Backend limits worth knowing before you wire this to a bulk action:

| Limit | Value | Response |
|---|---|---|
| Rate limiter | 20 requests/minute per API key | 429 |
| Daily quota | 100 corrections per platform per day | 429, `error.code = RATE_LIMITED` |
| Unknown submission, or one owned by another platform | — | **404**, not 403 |
| `correct_label` other than `spam` / `ham` | — | 422 |

## Reading the response

See [RESPONSE_SCHEMA.md](RESPONSE_SCHEMA.md) for every getter, why
`getStatus()` is the verdict and `getSpamScore()` is not, and the
envelope handling.
