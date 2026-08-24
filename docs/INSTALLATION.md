# Installation

## Requirements

- PHP **8.2 or newer**. PHP 8.0 and 8.1 are past end of security support
  and are not tested; installs on them stay on 0.9.x.
- `ext-curl`
- `ext-json`
- `ext-mbstring`

## Composer

```bash
composer require spamtroll/php-sdk
```

That is the canonical install path. The package has **zero runtime
dependencies** beyond the three extensions above — installing the SDK
into a project that already pulls in Guzzle, Symfony HttpClient, or any
other HTTP stack is safe and won't pin or fight versions.

## Without Composer

Drop the contents of `src/` into your project, then include the
fallback PSR-4 autoloader that ships with the SDK:

```php
require_once __DIR__ . '/path/to/spamtroll-php-sdk/autoload.php';
```

This is the path WordPress, IPS and DirectAdmin plugins take when they
bundle the SDK inside their release archive — a single `require_once` is
enough to make every `Spamtroll\Sdk\…` class loadable, no Composer needed
on the target server.

`autoload.php` throws a `RuntimeException` naming the running version if
PHP is older than 8.2. Composer enforces the minimum for normal installs;
a bundled copy has no such gate, and without the check the first
autoloaded class would produce a parse error and a white screen.

## Verifying the install

```php
<?php

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Version;

require __DIR__ . '/vendor/autoload.php';

echo Version::VERSION, "\n";

$client = new Client('your-api-key');

try {
    $response = $client->testConnection();
    echo $response->isConnectionValid() ? "ok\n" : "fail: {$response->error}\n";
} catch (\Throwable $e) {
    echo 'fail: ', $e->getMessage(), "\n";
}
```

If the script prints the SDK version followed by `ok`, you're set.

## Troubleshooting

- **`Class "Spamtroll\Sdk\Client" not found`** — Composer's autoloader
  isn't being included. Verify `require __DIR__ . '/vendor/autoload.php'`
  runs before any SDK call. In bundled-vendor setups, the host plugin's
  bootstrap should `require_once` the bundled autoloader before its own
  classes are loaded.
- **`spamtroll/php-sdk requires PHP 8.2 or newer`** — a bundled install on
  an old runtime. Upgrade PHP, or pin the plugin to an SDK 0.9.x release.
- **`composer require` says the package cannot be installed** — run
  `composer why-not spamtroll/php-sdk 0.10.0`; it is almost always the PHP
  constraint.
- **`ext-curl` is not installed** — install the curl PHP extension via
  your distribution's package manager (`apt install php-curl`,
  `dnf install php-curl`) and restart PHP-FPM / Apache.
- **TLS handshake errors on legacy servers** — the SDK enforces
  `CURLOPT_SSL_VERIFYPEER=true` and won't follow redirects. If your host
  has an outdated CA bundle, fix the host (preferred) or inject a custom
  `HttpClientInterface` adapter. See [HTTP_ADAPTERS.md](HTTP_ADAPTERS.md).
