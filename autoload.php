<?php
/**
 * Manual PSR-4 autoloader for spamtroll/php-sdk.
 *
 * Use this only in environments where Composer isn't available (e.g. when
 * bundling the SDK inside a host plugin that ships its own bootstrap).
 * Include once; subsequent includes are a no-op.
 */

declare(strict_types=1);

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
