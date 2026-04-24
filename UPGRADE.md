# Upgrade Guide

This document lists breaking changes between major versions and how to
migrate. Minor and patch releases are backwards-compatible per SemVer.

## From zero — initial adopters (IPS + WordPress plugins)

Both host plugins previously shipped their own copies of the API client
(`IPS\spamtroll\Api\Client` / `Spamtroll_Api_Client`). Migration notes live
in each plugin's `CHANGELOG.md` under the version that adopted the SDK.

### Known behavioral changes vs the pre-SDK clients

- **Score normalization.** The WordPress plugin previously divided the raw
  score by `15.0`; the SDK uses `30.0` (matching the IPS plugin's mapping).
  Raw score `15` now normalizes to `0.5` (was `1.0` in WP). Review your
  configured `spam_threshold` / `suspicious_threshold` after the upgrade.
- **Retry defaults.** The WordPress plugin previously made a single attempt
  and failed open. The SDK retries 5xx and connection errors 3× with
  backoff. Fail-open behavior in the host plugin still applies once the
  SDK gives up.
- **Exception namespaces.** Hosts must now catch
  `Spamtroll\Sdk\Exception\SpamtrollException` (or its subclasses) instead
  of `IPS\spamtroll\Api\Exception` / `Spamtroll_Api_Exception`.
- **HTTP transport.** The host plugin supplies an `HttpClientInterface`
  adapter. The IPS adapter delegates to `\IPS\Http\Url`; the WP adapter
  delegates to `wp_remote_*` — both honor their platform's request filters.
