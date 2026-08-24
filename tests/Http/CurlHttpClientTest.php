<?php

declare(strict_types=1);

use Spamtroll\Sdk\Http\CurlHttpClient;

/*
|--------------------------------------------------------------------------
| Transport portability
|--------------------------------------------------------------------------
|
| The protocol allow-list options only exist on builds linked against
| libcurl >= 7.85.0. Referencing a constant that is not there raises Error,
| which walks straight past catch (\Exception) — the exact failure mode this
| whole SDK is built to avoid, fired while constructing the transport.
|
| These tests exercise builds this machine does not have, by injecting the
| constant probe.
|
*/

/** A build with neither pair — libcurl older than 7.19.4, or a future one that dropped both. */
function buildWithoutProtocolOptions(): callable
{
    return static fn (string $name): bool => !str_starts_with($name, 'CURLOPT_PROTOCOLS')
        && !str_starts_with($name, 'CURLOPT_REDIR_PROTOCOLS')
        && !str_starts_with($name, 'CURLPROTO_');
}

/** A build with libcurl < 7.85: the deprecated integer pair only. */
function buildWithLegacyProtocolOptions(): callable
{
    return static fn (string $name): bool => !str_ends_with($name, '_STR');
}

/** A build with libcurl >= 7.85: everything. */
function buildWithModernProtocolOptions(): callable
{
    return static fn (string $name): bool => true;
}

it('keeps the security guarantee on every build, allow-list or not', function (string $build): void {
    // The allow-list is the THIRD line of defence. ClientConfig rejects a
    // non-http(s) baseUrl, and FOLLOWLOCATION=false means curl never visits a
    // URL the caller did not supply. Losing the allow-list must not touch
    // either of those.
    $options = CurlHttpClient::securityOptions(($build)());

    expect($options)->toHaveKey('CURLOPT_FOLLOWLOCATION')
        ->and($options['CURLOPT_FOLLOWLOCATION'])->toBeFalse()
        ->and($options['CURLOPT_SSL_VERIFYPEER'])->toBeTrue()
        ->and($options['CURLOPT_SSL_VERIFYHOST'])->toBe(2)
        ->and($options['CURLOPT_MAXFILESIZE'])->toBe(CurlHttpClient::MAX_RESPONSE_BYTES);
})->with([
    'buildWithoutProtocolOptions',
    'buildWithLegacyProtocolOptions',
    'buildWithModernProtocolOptions',
]);

it('asks for no protocol allow-list when the build has neither pair', function (): void {
    $options = CurlHttpClient::securityOptions(buildWithoutProtocolOptions());

    expect($options)->not->toHaveKey('CURLOPT_PROTOCOLS')
        ->and($options)->not->toHaveKey('CURLOPT_PROTOCOLS_STR')
        ->and($options)->not->toHaveKey('CURLOPT_REDIR_PROTOCOLS')
        ->and($options)->not->toHaveKey('CURLOPT_REDIR_PROTOCOLS_STR');
});

it('uses the modern string pair when libcurl is 7.85 or newer', function (): void {
    $options = CurlHttpClient::securityOptions(buildWithModernProtocolOptions());

    // Exactly one pair: both map to the same field inside libcurl, so setting
    // the deprecated one afterwards would overwrite the modern one.
    expect($options['CURLOPT_PROTOCOLS_STR'])->toBe('http,https')
        ->and($options['CURLOPT_REDIR_PROTOCOLS_STR'])->toBe('https')
        ->and($options)->not->toHaveKey('CURLOPT_PROTOCOLS')
        ->and($options)->not->toHaveKey('CURLOPT_REDIR_PROTOCOLS');
});

it('falls back to the deprecated integer pair on older libcurl', function (): void {
    $options = CurlHttpClient::securityOptions(buildWithLegacyProtocolOptions());

    expect($options['CURLOPT_PROTOCOLS'])->toBe(CURLPROTO_HTTP | CURLPROTO_HTTPS)
        ->and($options['CURLOPT_REDIR_PROTOCOLS'])->toBe(CURLPROTO_HTTPS)
        ->and($options)->not->toHaveKey('CURLOPT_PROTOCOLS_STR');
});

it('only ever names options that exist on the running build', function (): void {
    // send() resolves these with constant(); a name that is not defined here
    // would be a fatal Error at request time.
    foreach (array_keys(CurlHttpClient::securityOptions()) as $option) {
        expect(defined($option))->toBeTrue("curl option {$option} is not defined on this build");
    }
});

it('never references a version-gated curl constant by name in src/', function (): void {
    // The regression guard. PHPStan caught the original defect on a runner
    // whose libcurl predates 7.85; this catches it on any machine, including
    // one where the constant happens to exist.
    $alwaysAvailable = [
        'CURLE_OPERATION_TIMEOUTED', 'CURLINFO_HEADER_SIZE', 'CURLINFO_HTTP_CODE',
        'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_HEADER', 'CURLOPT_HTTPHEADER', 'CURLOPT_MAXFILESIZE',
        'CURLOPT_POSTFIELDS', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_SSL_VERIFYHOST',
        'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_TIMEOUT', 'CURLOPT_URL',
    ];

    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'),
    ) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // token_get_all so that constant names inside strings and docblocks
        // — where they are harmless, and deliberate — are not flagged.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (str_starts_with($token[1], 'CURL') && !in_array($token[1], $alwaysAvailable, true)) {
                $offenders[] = $file->getFilename() . ':' . $token[2] . ' ' . $token[1];
            }
        }
    }

    expect($offenders)->toBe([]);
});
