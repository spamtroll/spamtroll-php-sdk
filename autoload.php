<?php
/**
 * Manual PSR-4 autoloader for spamtroll/php-sdk.
 *
 * Use this only in environments where Composer isn't available (e.g. when
 * bundling the SDK inside a host plugin that ships its own bootstrap).
 * Include once; subsequent includes are a no-op.
 */

declare(strict_types=1);

// Composer refuses to install the SDK on an unsupported PHP; a bundled copy
// has no such gate. Fail with a sentence someone can act on instead of a
// parse error from src/ on the first autoloaded class.
if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException(
        'spamtroll/php-sdk requires PHP 8.2 or newer; this server runs ' . PHP_VERSION
    );
}

(static function (): void {
    $prefix = 'Spamtroll\\Sdk\\';
    $baseDir = __DIR__ . '/src/';

    spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
})();
