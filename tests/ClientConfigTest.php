<?php

declare(strict_types=1);

use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\InvalidConfigurationException;
use Spamtroll\Sdk\Exception\SpamtrollException;

it('defaults to an interactive latency profile', function (): void {
    $config = new ClientConfig();

    // Worst case = timeout * attempts + backoff between them, capped by the
    // total budget. These four numbers are the promise docs/CONFIGURATION.md
    // makes to integrators, so they are pinned here.
    expect($config->timeout)->toBe(3)
        ->and($config->maxRetries)->toBe(2)
        ->and($config->retryBaseDelayMs)->toBe(250)
        ->and($config->totalBudgetMs)->toBe(6000);

    $worstCaseMs = $config->timeout * 1000 * $config->maxRetries
        + $config->retryBaseDelayMs;

    expect($worstCaseMs)->toBeLessThanOrEqual(7000);
});

it('rejects a base URL that is not http(s)', function (string $baseUrl): void {
    expect(fn () => new ClientConfig(baseUrl: $baseUrl))
        ->toThrow(InvalidConfigurationException::class);
})->with([
    'file:///etc/passwd',
    'gopher://example.com',
    'ftp://example.com/api',
    'api.spamtroll.io/api/v1',
    '',
    '   ',
]);

it('reports a bad base URL through the SDK exception hierarchy', function (): void {
    // A single catch (SpamtrollException) in a host plugin has to cover a
    // typo in an admin settings field too, or the site fatals on save.
    expect(fn () => new ClientConfig(baseUrl: 'file:///etc/passwd'))
        ->toThrow(SpamtrollException::class);
});

it('accepts http and https and strips the trailing slash', function (): void {
    expect((new ClientConfig(baseUrl: 'https://api.spamtroll.io/api/v1/'))->baseUrl)
        ->toBe('https://api.spamtroll.io/api/v1')
        ->and((new ClientConfig(baseUrl: 'http://localhost:8080/api/v1'))->baseUrl)
        ->toBe('http://localhost:8080/api/v1');
});

it('clamps nonsensical values instead of trusting them', function (): void {
    $config = new ClientConfig(
        timeout: 0,
        maxRetries: 0,
        retryBaseDelayMs: -1,
        scoreDenominator: 0.0,
        totalBudgetMs: -5,
    );

    expect($config->timeout)->toBe(1)
        ->and($config->maxRetries)->toBe(1)
        ->and($config->retryBaseDelayMs)->toBe(0)
        ->and($config->totalBudgetMs)->toBe(0)
        ->and($config->scoreDenominator)->toBe(ClientConfig::DEFAULT_SCORE_DENOMINATOR);
});

it('is immutable, as the documentation has always claimed', function (): void {
    $config = new ClientConfig();

    expect(fn () => $config->baseUrl = 'http://evil.example')->toThrow(Error::class);
});
