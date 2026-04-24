# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
