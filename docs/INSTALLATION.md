# Installation

## Requirements

- PHP **8.0 or newer** (production minimum). The SDK itself runs on 8.0;
  the dev tooling (Pest, peck, php-cs-fixer) requires PHP 8.3+ but is only
  needed to contribute to the SDK, not to use it.
- `ext-curl`
- `ext-json`

## Composer

```bash
composer require spamtroll/php-sdk
```

That is the canonical install path. The package has **zero runtime
dependencies** beyond the two extensions above — installing the SDK
into a project that already pulls in Guzzle, Symfony HttpClient, or any
other HTTP stack is safe and won't pin or fight versions.

## Without Composer

Drop the contents of `src/` into your project, then include the
fallback PSR-4 autoloader that ships with the SDK:

```php
require_once __DIR__ . '/path/to/spamtroll-php-sdk/autoload.php';
```

This is the path WordPress and IPS plugins take when they bundle the
SDK inside their release archive — a single `require_once` is enough
to make every `Spamtroll\Sdk\…` class loadable, no Composer needed on
the target server.

## Verifying the install

```php
<?php

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\Version;

require __DIR__ . '/vendor/autoload.php';

echo Version::VERSION, "\n";

$client = new Client('your-api-key');
$response = $client->testConnection();
echo $response->isConnectionValid() ? "ok\n" : "fail: {$response->error}\n";
```

If the script prints the SDK version followed by `ok`, you're set.

## Troubleshooting

- **`Class "Spamtroll\Sdk\Client" not found`** — Composer's autoloader
  isn't being included. Verify `require __DIR__ . '/vendor/autoload.php'`
  runs before any SDK call. In bundled-vendor setups (WP/IPS plugin
  release archives), the host plugin's bootstrap should `require_once`
  the bundled autoloader before its own classes are loaded.
- **`ext-curl` is not installed** — install the curl PHP extension via
  your distribution's package manager (`apt install php-curl`,
  `dnf install php-curl`, etc.) and restart PHP-FPM / Apache.
- **TLS handshake errors on legacy servers** — the SDK enforces
  `CURLOPT_SSL_VERIFYPEER=true` and won't follow redirects. If your
  forum host has an outdated CA bundle, fix the host (preferred) or
  inject a custom `HttpClientInterface` adapter that disables peer
  verification. See [HTTP_ADAPTERS.md](HTTP_ADAPTERS.md).
