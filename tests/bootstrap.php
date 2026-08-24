<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
|
| Normally this is just Composer's autoloader. When SPAMTROLL_SRC_DIR is
| set, an autoloader for that directory is *prepended*, so the suite runs
| against a different copy of src/ than the working tree.
|
| dev/prove-regression.sh uses it to point the current tests at the source
| as it was before a fix, and insists the suite turns red. A test that
| passes against the defect is decoration, not a regression test.
|
*/

require dirname(__DIR__) . '/vendor/autoload.php';

$override = getenv('SPAMTROLL_SRC_DIR');

if (is_string($override) && $override !== '') {
    $baseDir = rtrim($override, '/') . '/';

    spl_autoload_register(static function (string $class) use ($baseDir): void {
        $prefix = 'Spamtroll\\Sdk\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
}
