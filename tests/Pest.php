<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest auto-discovers and runs the *.php files under `tests/`. We don't
| bind a base TestCase here because the SDK has no shared fixtures and
| every test gets a fresh FakeHttpClient.
|
*/

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toHaveStatusCode', function (int $code) {
    /** @var \Spamtroll\Sdk\Response\Response $response */
    $response = $this->value;
    expect($response->httpCode)->toBe($code);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function fakeHttp(): \Spamtroll\Sdk\Tests\Fake\FakeHttpClient
{
    return new \Spamtroll\Sdk\Tests\Fake\FakeHttpClient();
}

function makeClient(
    \Spamtroll\Sdk\Tests\Fake\FakeHttpClient $http,
    ?\Spamtroll\Sdk\ClientConfig $config = null,
    string $apiKey = 'test-key',
): \Spamtroll\Sdk\Client {
    return new \Spamtroll\Sdk\Client(
        $apiKey,
        $config ?? new \Spamtroll\Sdk\ClientConfig(retryBaseDelayMs: 0),
        $http,
    );
}
