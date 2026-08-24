# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.10.0] - 2026-08-24

This release makes fail-open a property of the SDK instead of a rule six
plugins had to remember, and removes a method that never worked. It is a
`0.x` minor, which SemVer allows to break compatibility — see
[UPGRADE.md](UPGRADE.md) for the migration, and read it before bumping
the constraint in a plugin.

### Added

- **`Client::checkSpamOrHam()` — the method integrations should call.** It
  never throws. Every failure (no API key, DNS, timeout, HTTP 401, 5xx
  after retries, quota exhausted, an adapter leaking a foreign exception
  or an `Error`, a 2xx carrying HTML) returns a `CheckSpamResponse` with
  `wasSkipped() === true` and `shouldBlock() === false`. A blocking
  verdict can only originate from a successful scan.
- `CheckSpamResponse::failOpen()`, `getFailure()`, `hasVerdict()`,
  `isBlocked()`, `isSuspicious()`, `isSafe()`, `shouldBlock()`,
  `shouldModerate()`. The server's `status` is now the primary value;
  four plugins had been re-deriving a verdict from the normalised score
  with their own hard-coded cut-offs because `status` was awkward to
  reach.
- `CheckSpamResponse::getSkipCategory()` — a **closed** set of five values
  (`transport`, `quota`, `rate_limit`, `rejected`, `no_verdict`, plus the
  empty string when there is a verdict) sitting alongside the deliberately
  open `getSkipReason()`. The reason string carries the backend's own error
  code and the backend adds codes whenever it likes, which makes it right
  for a log line and wrong for a `switch` or a narrow column. Without a
  shared closed axis every integration invents its own enum over the reason
  string, and six dictionaries drift apart; this is the shared one.
- `Response::$errorCode` / `getErrorCode()` — the backend's machine code
  (`QUOTA_EXCEEDED`, `VALIDATION_ERROR`, `FORBIDDEN`, …) so plugins can
  branch on something other than prose.
- `ClientConfig::$totalBudgetMs` (default `6000`) — a ceiling on the whole
  call, retries and backoff included. `timeout × maxRetries` never was one.
- `CheckSpamRequest`: `SOURCE_EMAIL` (switches the backend to the e-mail
  RETVec model — mail integrations were silently getting the web model),
  `SOURCE_CONTACT_FORM`, `$rawMessage` (enables real cryptographic DKIM
  verification instead of the forgeable header-only fallback), `$headers`,
  `isContentTruncated()`.
- `Exception\InvalidConfigurationException` for a `baseUrl` that is not
  http(s). Extends `SpamtrollException`, so an existing single catch
  covers it.
- `Client::submitFeedback()` / `trySubmitFeedback()` and
  `Request\FeedbackRequest`, covering `POST /scan/feedback`. This is the
  only route by which a moderator's correction reaches the model, and a
  `spam` label also trains the platform's Bayes classifier —
  `getSubmissionId()` had been returning an identifier with nothing to do
  with it. `trySubmitFeedback()` never throws, because failing to record
  a correction must not break the moderation screen.
- `dev/prove-regression.sh` — runs the suite against `src/` at a base ref
  and fails if it stays green.
- `tests/FailOpenContractTest.php` — sixteen failure modes, each asserted
  not to throw, block or moderate.

### Changed

- **BREAKING: minimum PHP raised from 8.0 to 8.2.** 8.0 and 8.1 are past
  end of security support. Installs on older PHP stay on 0.9.x.
  `autoload.php` now refuses to register below 8.2 with a readable
  message, which is the only protection plugins bundling the SDK without
  Composer have. `ext-mbstring` is now a declared requirement.
- **BREAKING: `wasSkipped()` is the exact inverse of `hasVerdict()`.** It
  used to mean "HTTP 402" and nothing else. It now covers every
  no-verdict branch — transport failure, 402, 429, any 4xx, and a 2xx
  whose body carried no classification. `getSkipReason()` gained the
  values `transport_error`, `rate_limited`, `payment_required`,
  `unparseable_response`, `http_<code>` and any lower-cased backend error
  code.
- **BREAKING: `getStatus()` returns `safe` whenever there is no verdict**,
  where it previously read `data.status` from an unsuccessful response.
  `getSpamScore()` / `getRawSpamScore()` return `0.0` in the same case.
- **BREAKING: interactive defaults lowered** — `timeout` 5 → 3,
  `maxRetries` 3 → 2, `retryBaseDelayMs` 500 → 250. Worst-case blocking
  time drops from 16.5 s to ~6.25 s. The old values remain documented as
  the background-worker preset.
- **BREAKING: `ClientConfig`, `Response`, `HttpResponse` and
  `CheckSpamRequest` properties are `readonly`.** `docs/CONFIGURATION.md`
  has described `ClientConfig` as immutable since 0.9.0 without it being
  true.
- **BREAKING: `baseUrl` must be `http` or `https`.** It is an
  admin-editable field in every integration, and without the check
  `file:///etc/passwd` was a request the SDK would perform, with the
  file's contents landing in `$response->data` and in plugin logs.
- A POST that receives a 5xx is retried **once**, regardless of
  `maxRetries`. The backend charges the daily scan quota before running
  the scan, so three attempts billed a customer three scans for one failed
  scan during an outage they did not cause.
- Every POST carries an `Idempotency-Key`, generated once per call and
  reused across that call's retries.
- The 8.0/8.1 CI legs are gone, along with the PHPUnit fallback they
  forced. Those legs never executed a single assertion: the tests are Pest
  DSL with no test classes, and `config.platform.php = 8.3` plus
  `--ignore-platform-req=php` meant they were not measuring 8.0
  compatibility either. Matrix is now 8.2/8.3/8.4, php-cs-fixer targets
  `@PHP82Migration`, PHPStan pins `phpVersion: 80200`, and peck runs
  advisory.

### Fixed

- **`$response->error` was `"1"` for every rate-limited request.** The HTTP
  rate limiter and the framework's error handler answer with
  `{"error": true, "message": "…"}`, and `(string) true` is `"1"`. The
  standard `{"error": {code, message, request_id}}` envelope fared no
  better: the whole object was JSON-encoded into the field. All three
  shapes are now parsed properly.
- **`getRequestId()` returned `null` 100% of the time.** The backend puts
  the identifier in `error.request_id`, never at the top level.
  `getMessage()` had the mirror-image bug.
- Content that is not valid UTF-8 no longer throws. `json_encode()`
  returning `false` meant such content was **never scanned** — a filter
  bypass costing an attacker one illegal byte. It is now encoded with
  `JSON_INVALID_UTF8_SUBSTITUTE`.
- An adapter reporting a status below 100 is treated as a transport
  failure and retried, instead of being reported as "the server said no".
- `CurlHttpClient` restricts protocols to http/https, caps the response
  body, and throws when curl succeeds without an HTTP status line.
- Server-supplied strings are truncated before they reach host logs and
  moderation panels (512 characters for error text, 128 for symbol and
  category names).
- `content` is truncated to the 64 KiB the backend is willing to scan.
  Padding a post past the scan timeout was a deterministic way to force a
  fail-open.
- The reference HTTP adapters in `docs/HTTP_ADAPTERS.md` followed
  redirects, carrying `X-API-Key` to whatever host a `Location` header
  named. All three are fixed, and "never follow redirects" is now a
  mandatory rule of `HttpClientInterface`.

### Removed

- **BREAKING: `Client::getAccountUsage()` and `Response\UsageResponse`.**
  They called `GET /account/usage`, which the backend does not have and
  never had — the only usage endpoint is `/api/v1/billing/usage`, behind a
  JWT and therefore unreachable with an API key. The call 404'd, was
  classified as "some other 4xx", and `UsageResponse` reported `0` for all
  three fields with no error anywhere. The existing test passed because
  `FakeHttpClient` returned a payload the real server never sends. Quota
  information is available from a 402 response through `getQuotaUsage()`.


## [0.9.3] - 2026-04-26

### Added
- `CheckSpamResponse::isQuotaExceeded()`, `wasSkipped()`, `getSkipReason()`, `getQuotaUsage()` for the new HTTP **402 QUOTA_EXCEEDED** response. Plugins on free / capped plans can now distinguish "user ran out of daily scans" from real transport errors and **fail open** (let the message through unscanned) instead of blocking legitimate content because billing ran out. The error envelope `{code, message, usage:{current,limit,plan,reset_at}}` is preserved so plugins can render a "you've used 200/200 today — upgrade" hint in their admin UI.
- `CheckSpamResponse::ERROR_QUOTA_EXCEEDED` constant for plugins that want to switch on the code directly.
- `isSpam()` now returns `false` when `wasSkipped()` is true even though `success` is false — keeps the fail-open contract idiomatic for plugins that only check the spam flag.

### Changed
- `Client::dispatch()` no longer throws on HTTP 402 — quota exhaustion is a normal operational state for free-tier integrations, not an exception. Returns the same `(success=false, code=402, decoded, errorMessage)` tuple as 429 so callers can branch on `httpCode` / `isQuotaExceeded()`.

## [0.9.2] - 2026-04-25

### Added

- PHPStan level 9 + `phpstan-strict-rules` integration. Source code
  is fully clean; `tests/` is excluded because Pest's `it()`/`expect()`/
  `arch()` DSL needs a dedicated extension.
- php-cs-fixer config (`.php-cs-fixer.php`) enforcing
  `@PSR12 + @PSR12:risky + @PHP80Migration:risky` plus
  `declare_strict_types`, ordered imports, single quotes, trailing
  commas in multiline argument lists.
- Pest 2 as the test runner; the existing 28 PHPUnit tests are
  migrated to `it()`/`expect()` style, and a new `tests/ArchTest.php`
  pins seven structural rules (strict types, exception hierarchy,
  no debug helpers, etc.).
- peck (`peckphp/peck`) spell-check with a domain dictionary in
  `peck.json`. Runs in the CI QA job, where `aspell` is installed.
- Documentation suite under `docs/`: `INSTALLATION.md`,
  `USAGE.md`, `CONFIGURATION.md`, `HTTP_ADAPTERS.md`,
  `ERROR_HANDLING.md`, `RESPONSE_SCHEMA.md`, `CONTRIBUTING.md`.
- Composer scripts: `test`, `test:coverage`, `lint`, `lint:fix`,
  `stan`, `peck`, `qa` (composite).
- CI now runs a separate `qa` job on PHP 8.3 (PHPStan + cs-fixer
  dry-run + peck) on top of the existing test matrix
  (PHP 8.0–8.4 × composer lowest/highest).

### Changed

- `Spamtroll\Sdk\Exception\SpamtrollException::stringify` now uses
  `mixed` typed parameter (PHP 8.0 `mixed` keyword) instead of the
  untyped fallback. Internal change; no public API impact.
- `Spamtroll\Sdk\Exception\SpamtrollException::fromResponse` swaps
  `new static()` for `new self()` to satisfy PHPStan level 9
  ("Unsafe usage of new static()"). Same observable behaviour.
- `Spamtroll\Sdk\Exception\ConnectionException::fromMessage` swaps
  `new static()` for `new self()` for the same reason.
- `Spamtroll\Sdk\Http\CurlHttpClient::parseHeaders` now treats
  `preg_split === false` as an empty-headers case explicitly; the old
  `?:` short-ternary tripped strict-rules.
- `composer.json` requires PHP 8.0+ in production but pins
  `config.platform.php = 8.3` for development tooling. Pest 2 needs
  8.2+ transitively, peck needs 8.3+; production runtime is unchanged.

## [0.9.1] - 2026-04-24

### Changed

- README now shows Packagist version / PHP / license badges.

## [0.9.0] - 2026-04-24

### Added

- Initial extraction of the Spamtroll API client into a reusable SDK.
- `Spamtroll\Sdk\Client` with automatic retry on 5xx and connection failures
  (3 attempts, exponential-ish backoff).
- `Spamtroll\Sdk\Http\HttpClientInterface` thin adapter contract, with a
  zero-dependency `CurlHttpClient` default. WordPress and IPS host
  integrations ship their own adapters.
- `Spamtroll\Sdk\Request\CheckSpamRequest` with canonical `SOURCE_*`
  constants (`forum`, `comment`, `message`, `registration`, `generic`).
- `Spamtroll\Sdk\Response\CheckSpamResponse` with configurable score
  normalization (default denominator `30.0`, matching the IPS plugin's
  mapping). Exposes `getStatus()`, `getSpamScore()`, `getRawSpamScore()`,
  `getSymbols()`, `getSymbolDetails()`, `getThreatCategories()`,
  `getSubmissionId()`, `getRequestId()`, `isSpam()`.
- `Spamtroll\Sdk\Response\UsageResponse` for `/account/usage`.
- Exception hierarchy under `Spamtroll\Sdk\Exception\*`:
  `SpamtrollException` (base), `NotConfiguredException`,
  `ConnectionException`, `TimeoutException` (extends `ConnectionException`),
  `AuthenticationException`, `ServerException`.
- `autoload.php` fallback PSR-4 autoloader for environments without
  Composer.
- `FakeHttpClient` under `Spamtroll\Sdk\Tests\Fake` for integrators that
  want to stub the HTTP layer in their own test suites.
