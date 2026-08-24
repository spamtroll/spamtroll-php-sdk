# Contributing

Thanks for thinking about contributing. The SDK is small, deliberately
boring, and tries to stay zero-dependency for production users. This
page documents the development setup and the bar for changes.

## Local setup

PHP **8.2+** for everything — runtime, tests and tooling. CI runs the
test matrix on 8.2, 8.3 and 8.4 against both `--prefer-lowest` and
`--prefer-stable` dependencies.

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

If you don't install aspell, `composer peck` will fail locally. This is
optional: peck runs in CI as **advisory only** (`continue-on-error`). A
dictionary-based spell checker flags every new domain word — a symbol
name, a backend error code — as a misspelling until someone adds it to
`peck.json`, and blocking merges on that punishes unrelated pull requests
for the dictionary's gaps. Fix real typos; add real words to `peck.json`.

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
   Advisory in CI, see above.
4. `composer test` — full Pest suite (unit + arch + the fail-open
   contract).

CI runs the same set on every push and PR. We won't merge a red CI.

## Coding standards

- **PSR-12** enforced by php-cs-fixer (`@PSR12 + @PSR12:risky +
  @PHP82Migration:risky`). All code declares `strict_types=1`.
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

Anything touching the scan path also belongs in
`tests/FailOpenContractTest.php`, which walks every failure mode and
asserts that none of them throws, blocks or moderates. If you add a new
way for a call to fail, add it to that matrix.

### Prove the test would have caught it

A test that passes against the broken code proves nothing. Before
opening a PR for a bug fix:

```bash
bash dev/prove-regression.sh          # defaults to comparing against main
```

It extracts `src/` at the base ref, points the current suite at it
through `tests/bootstrap.php`'s `SPAMTROLL_SRC_DIR` override, and fails
if the suite stays green.

## Versioning

We are in `0.x`, where SemVer §4 allows a minor to break compatibility —
and 0.10.0 uses that allowance. Every breaking change is listed in
[UPGRADE.md](../UPGRADE.md) with the migration.

- Patch (`0.10.0` → `0.10.1`) — bug fixes, doc updates, internal
  refactors. No public API changes.
- Minor (`0.10.0` → `0.11.0`) — additive changes, and, while we are
  pre-1.0, breaking ones. Both go in `UPGRADE.md`.
- `1.0.0` — the point at which the API is declared stable and breaking
  changes require a major. Deprecate first with `@deprecated` plus
  `trigger_error(..., E_USER_DEPRECATED)` whenever feasible.

A behavioural change is a breaking change even when no signature moves.
0.9.3 shipped "Client::dispatch() no longer throws on HTTP 402" as a
patch; every plugin pinned to `^0.9` got it with no warning and no
`UPGRADE.md` entry. Don't repeat that.

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
