<?php

declare(strict_types=1);

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\NotConfiguredException;
use Spamtroll\Sdk\Exception\ServerException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;
use Spamtroll\Sdk\Tests\Fake\FakeHttpClient;
use Spamtroll\Sdk\Version;

it('throws when the API key is empty', function (): void {
    $client = new Client('', new ClientConfig(retryBaseDelayMs: 0), new FakeHttpClient());

    $client->checkSpam(new CheckSpamRequest('anything'));
})->throws(NotConfiguredException::class);

it('sends checkSpam as POST with JSON body and the expected headers', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['status' => 'safe', 'spam_score' => 0],
    ]);
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('hello', 'comment', '1.2.3.4', 'bob', 'bob@example.com'));

    $call = $http->lastCall();

    expect($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('https://api.spamtroll.io/api/v1/scan/check')
        ->and($call['headers']['X-API-Key'])->toBe('test-key')
        ->and($call['headers']['Content-Type'])->toBe('application/json')
        ->and($call['headers']['Accept'])->toBe('application/json')
        ->and($call['headers']['User-Agent'])->toBe('spamtroll-php-sdk/' . Version::VERSION);

    expect($call['body'])->toBeString();
    expect(json_decode((string) $call['body'], true))->toBe([
        'content' => 'hello',
        'source' => 'comment',
        'ip_address' => '1.2.3.4',
        'username' => 'bob',
        'email' => 'bob@example.com',
    ]);
});

it('returns a CheckSpamResponse with normalised score on success', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['status' => 'blocked', 'spam_score' => 15, 'submission_id' => 'abc-123'],
    ]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('spam'));

    expect($response)->toBeInstanceOf(CheckSpamResponse::class)
        ->and($response->success)->toBeTrue()
        ->and($response->httpCode)->toBe(200)
        ->and($response->getStatus())->toBe(CheckSpamResponse::STATUS_BLOCKED)
        ->and($response->getSpamScore())->toBe(0.5)
        ->and($response->getRawSpamScore())->toBe(15.0)
        ->and($response->getSubmissionId())->toBe('abc-123')
        ->and($response->isSpam())->toBeTrue();
});

it('throws AuthenticationException on HTTP 401', function (): void {
    $http = fakeHttp()->queueJson(401, ['error' => 'bad key']);
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('x'));
})->throws(AuthenticationException::class);

it('returns a CheckSpamResponse with isQuotaExceeded=true on 402 QUOTA_EXCEEDED', function (): void {
    // Backend's 402 envelope: {success:false, error:{code, message, usage}}.
    // Plugins read isQuotaExceeded() before deciding whether !success means
    // "treat as ham" (fail-open) versus a real transport error.
    $http = fakeHttp()->queueJson(402, [
        'success' => false,
        'error' => [
            'code' => 'QUOTA_EXCEEDED',
            'message' => 'Daily scan limit reached. Upgrade your plan at /dashboard/billing.',
            'usage' => [
                'current' => 200,
                'limit' => 200,
                'plan' => 'free',
                'reset_at' => '2026-04-27T00:00:00Z',
            ],
        ],
    ]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('hello'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(402)
        ->and($response->isQuotaExceeded())->toBeTrue()
        ->and($response->wasSkipped())->toBeTrue()
        ->and($response->getSkipReason())->toBe('quota_exceeded')
        ->and($response->isSpam())->toBeFalse() // fail-open: not spam even though success=false
        ->and($response->getQuotaUsage())->toMatchArray([
            'current' => 200,
            'limit' => 200,
            'plan' => 'free',
        ])
        ->and($http->callCount())->toBe(1);
});

it('returns a Response with success=false on 429 without retrying', function (): void {
    // The HTTP rate limiter answers with the legacy envelope, where `error`
    // is the boolean flag and the text lives in `message`.
    $http = fakeHttp()
        ->queueJson(429, ['error' => true, 'message' => 'Rate limit exceeded. Maximum 100 requests per minute.'])
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(429)
        ->and($response->error)->toBe('Rate limit exceeded. Maximum 100 requests per minute.')
        ->and($response->getErrorCode())->toBeNull()
        ->and($response->getSkipReason())->toBe('rate_limited')
        ->and($http->callCount())->toBe(1);
});

it('returns a Response with success=false on other 4xx without retrying', function (): void {
    // Standard envelope: `error` is an object, so the readable text and the
    // machine code both have to be dug out of it.
    $http = fakeHttp()->queueJson(422, ['success' => false, 'error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => 'Content is required',
        'request_id' => 'req-77',
    ]]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(422)
        ->and($response->error)->toBe('Content is required')
        ->and($response->getErrorCode())->toBe('VALIDATION_ERROR')
        ->and($response->getRequestId())->toBe('req-77')
        ->and($http->callCount())->toBe(1);
});

it('retries a GET 5xx up to maxRetries before throwing ServerException', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'boom']])
        ->queueJson(502, ['error' => true, 'message' => 'bad gateway'])
        ->queueJson(503, ['error' => true, 'message' => 'unavailable']);
    $client = makeClient($http, new ClientConfig(maxRetries: 3, retryBaseDelayMs: 0));

    try {
        $client->testConnection();
        test()->fail('Expected ServerException');
    } catch (ServerException $e) {
        expect($e->httpCode)->toBe(503)
            ->and($http->callCount())->toBe(3);
    }
});

it('retries a POST 5xx exactly once, whatever maxRetries says', function (): void {
    // The backend bills a scan against the daily quota before it runs, so a
    // 500 has already been paid for. Three attempts would bill a customer
    // three times for one failed scan during an outage they did not cause.
    $http = fakeHttp()
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']]);
    $client = makeClient($http, new ClientConfig(maxRetries: 5, retryBaseDelayMs: 0));

    try {
        $client->checkSpam(new CheckSpamRequest('x'));
        test()->fail('Expected ServerException');
    } catch (ServerException $e) {
        expect($http->callCount())->toBe(2)
            ->and($e->apiErrorCode)->toBe('INTERNAL_ERROR');
    }
});

it('sends one idempotency key per call and reuses it across retries', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe', 'spam_score' => 0]]);
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('x'));

    expect($http->callCount())->toBe(2);
    $first = $http->calls[0]['headers']['Idempotency-Key'] ?? null;
    $second = $http->calls[1]['headers']['Idempotency-Key'] ?? null;

    expect($first)->toBeString()
        ->and($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($second)->toBe($first);
});

it('generates a fresh idempotency key for each call', function (): void {
    $http = fakeHttp()
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']])
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('one'));
    $client->checkSpam(new CheckSpamRequest('two'));

    expect($http->calls[1]['headers']['Idempotency-Key'])
        ->not->toBe($http->calls[0]['headers']['Idempotency-Key']);
});

it('does not send an idempotency key on GET requests', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => ['status' => 'running']]);
    $client = makeClient($http);

    $client->testConnection();

    expect($http->lastCall()['headers'])->not->toHaveKey('Idempotency-Key');
});

it('caps the per-attempt timeout at the remaining total budget', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http, new ClientConfig(timeout: 10, retryBaseDelayMs: 0, totalBudgetMs: 2000));

    $client->checkSpam(new CheckSpamRequest('x'));

    expect($http->lastCall()['timeout'])->toBe(2);
});

it('stops retrying once the total budget is spent', function (): void {
    $http = fakeHttp();
    for ($i = 0; $i < 6; $i++) {
        $http->queueException(ConnectionException::fromMessage('refused'));
    }
    $client = makeClient($http, new ClientConfig(
        timeout: 1,
        maxRetries: 6,
        retryBaseDelayMs: 150,
        totalBudgetMs: 300,
    ));

    $startedAt = microtime(true);
    try {
        $client->checkSpam(new CheckSpamRequest('x'));
        test()->fail('Expected ConnectionException');
    } catch (ConnectionException) {
        $elapsed = microtime(true) - $startedAt;
        expect($http->callCount())->toBeLessThan(6)
            ->and($elapsed)->toBeLessThan(1.0);
    }
});

it('recovers when an early 5xx is followed by a successful response', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['error' => 'transient'])
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe', 'spam_score' => 0]]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeTrue()
        ->and($http->callCount())->toBe(2);
});

it('retries on connection failures and recovers on success', function (): void {
    $http = fakeHttp()
        ->queueException(ConnectionException::fromMessage('dns fail'))
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeTrue()
        ->and($http->callCount())->toBe(2);
});

it('rethrows the final connection exception after exhausting retries', function (): void {
    $http = fakeHttp()
        ->queueException(ConnectionException::fromMessage('fail 1'))
        ->queueException(TimeoutException::afterSeconds(3));
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('x'));
})->throws(TimeoutException::class);

it('hits /scan/status with a GET when testConnection is invoked', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'status' => 'ok']);
    $client = makeClient($http);

    $response = $client->testConnection();

    expect($response->isConnectionValid())->toBeTrue()
        ->and($http->lastCall()['method'])->toBe('GET')
        ->and($http->lastCall()['url'])->toBe('https://api.spamtroll.io/api/v1/scan/status')
        ->and($http->lastCall()['body'])->toBeNull();
});

it('uses a custom user agent when configured', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => []]);
    $client = makeClient($http, new ClientConfig(userAgent: 'my-plugin/2.3.4', retryBaseDelayMs: 0));

    $client->checkSpam(new CheckSpamRequest('x'));

    expect($http->lastCall()['headers']['User-Agent'])->toBe('my-plugin/2.3.4');
});

it('respects a custom base URL', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => []]);
    $client = makeClient(
        $http,
        new ClientConfig(baseUrl: 'https://staging.spamtroll.io/api/v1/', retryBaseDelayMs: 0),
    );

    $client->checkSpam(new CheckSpamRequest('x'));

    expect($http->lastCall()['url'])->toBe('https://staging.spamtroll.io/api/v1/scan/check');
});

it('applies a custom score denominator to the response', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['status' => 'blocked', 'spam_score' => 15],
    ]);
    $client = makeClient($http, new ClientConfig(scoreDenominator: 15.0, retryBaseDelayMs: 0));

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->getSpamScore())->toBe(1.0);
});

it('omits optional fields from the JSON payload when not provided', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => []]);
    $client = makeClient($http);

    $client->checkSpam(new CheckSpamRequest('only content'));

    $body = $http->lastCall()['body'];
    expect($body)->toBeString();
    expect(json_decode((string) $body, true))->toBe([
        'content' => 'only content',
        'source' => 'generic',
    ]);
});
