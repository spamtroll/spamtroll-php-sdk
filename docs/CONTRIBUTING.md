# Contributing

Thanks for thinking about contributing. The SDK is small, deliberately
boring, and tries to stay zero-dependency for production users. This
page documents the development setup and the bar for changes.

## Local setup

The SDK runs on PHP 8.0 in production, but the **dev tooling
(Pest, peck, php-cs-fixer) requires PHP 8.3+**. You need PHP 8.3 to
run the full test suite locally. CI runs the test matrix on
8.0 → 8.4.

```bash
git clone https://github.com/spamtroll/spamtroll-php-sdk.git
cd spamtroll-php-sdk
composer install
```

You also need `aspell` + `aspell-en` for the spell-check:

```bash
sudo apt install aspell aspell-en       # Debian / Ubuntu
brew install aspell                     # macOS
```

If you don't install aspell, `composer peck` will fail locally — CI
will still run it for you in pull requests, so this is optional.

## Quality gate

Before opening a PR, run:

```bash
composer qa
```

That runs in order:

1. `composer lint` — php-cs-fixer dry-run. Failure means run
   `composer lint:fix`.
2. `composer stan` — PHPStan level 9. Failure must be fixed (no
   baseline tolerated for new code).
3. `composer peck` — aspell-based spell-check. Failure either means a
   real typo or a domain word that should be added to `peck.json`.
4. `composer test` — full Pest suite (unit + arch).

CI runs the same set on every push and PR. We won't merge a red CI.

## Coding standards

- **PSR-12** enforced by php-cs-fixer (`@PSR12 + @PSR12:risky +
  @PHP80Migration:risky`). All code declares `strict_types=1`.
- **PHPStan level 9** clean, with `phpstan-strict-rules` enabled.
- **PHPDoc array generics** required (`array<string, mixed>`, not
  `array`). Tuples typed via `array{0: bool, ...}`.
- Public APIs documented in `docs/`. Adding a public method without a
  matching docs entry is fair grounds to be asked to update docs.

## Tests

Pest, in `tests/`. Naming convention:

- `tests/<Domain>Test.php` for functional tests (`it('does X', …)`).
- `tests/ArchTest.php` for arch rules (uses Pest's arch plugin).
- `tests/Fake/*.php` for test doubles (helper classes, not test cases).

Every public method on `Client` and every getter on the `Response`
hierarchy has at least one functional test. The
`Spamtroll\Sdk\Tests\Fake\FakeHttpClient` lets you queue responses
without touching the network.

Cover both happy path and failure modes. The SDK's whole job is being
robust to API failures, so a feature without a "what if the API
returns garbage" test isn't done.

## Versioning

Strict SemVer from `v1.0.0` onwards. We're currently in `0.x` while
the WordPress and IPS plugin integrations bake.

- Patch (`0.9.0` → `0.9.1`) — bug fixes, doc updates, internal
  refactors. No public API changes.
- Minor (`0.9.0` → `0.10.0`) — additive changes (new methods, new
  config fields with defaults). Backwards-compatible.
- Major (`0.x` → `1.0.0` and beyond) — breaking changes. Documented
  in [UPGRADE.md](../UPGRADE.md). Deprecated in a previous minor with
  `@deprecated` plus `trigger_error(..., E_USER_DEPRECATED)` whenever
  feasible.

Changing the PHP minimum is a minor bump (Symfony / Doctrine
convention). Changing `scoreDenominator` default would be a major
because it shifts every consumer's calibrated thresholds.

## Release checklist

1. Bump `Spamtroll\Sdk\Version::VERSION`.
2. Move the `[Unreleased]` section in `CHANGELOG.md` under a new
   version heading with today's date.
3. `composer qa` — must be green.
4. Commit, tag `v<version>`, push tag.
5. Packagist auto-syncs the tag within ~10 seconds via the GitHub
   webhook.

## Reporting issues

Opening an issue:

- For bugs: include the SDK version, PHP version, and a minimal
  reproduction. The smaller the repro, the faster it gets fixed.
- For feature requests: explain the use case first; the SDK leans
  towards "small surface area" so additions need a real-world story.

For security issues, **do not open a public issue**. Email the
maintainer per `SECURITY.md` (or `composer.json` `support.email`
once added).
