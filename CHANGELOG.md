# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
