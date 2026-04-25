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
use Spamtroll\Sdk\Response\UsageResponse;
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

it('returns a Response with success=false on 429 without retrying', function (): void {
    $http = fakeHttp()
        ->queueJson(429, ['error' => 'rate limited'])
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(429)
        ->and($response->error)->toBe('rate limited')
        ->and($http->callCount())->toBe(1);
});

it('returns a Response with success=false on other 4xx without retrying', function (): void {
    $http = fakeHttp()->queueJson(400, ['error' => 'bad request']);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(400)
        ->and($response->error)->toBe('bad request')
        ->and($http->callCount())->toBe(1);
});

it('retries 5xx responses up to maxRetries before throwing ServerException', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['error' => 'boom'])
        ->queueJson(502, ['error' => 'bad gateway'])
        ->queueJson(503, ['error' => 'unavailable']);
    $client = makeClient($http);

    try {
        $client->checkSpam(new CheckSpamRequest('x'));
        test()->fail('Expected ServerException');
    } catch (ServerException $e) {
        expect($e->httpCode)->toBe(503)
            ->and($http->callCount())->toBe(3);
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
        ->queueException(TimeoutException::afterSeconds(5))
        ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
    $client = makeClient($http);

    $response = $client->checkSpam(new CheckSpamRequest('x'));

    expect($response->success)->toBeTrue()
        ->and($http->callCount())->toBe(3);
});

it('rethrows the final connection exception after exhausting retries', function (): void {
    $http = fakeHttp()
        ->queueException(ConnectionException::fromMessage('fail 1'))
        ->queueException(ConnectionException::fromMessage('fail 2'))
        ->queueException(TimeoutException::afterSeconds(5));
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

it('parses usage fields from getAccountUsage', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => [
            'requests_today' => 12,
            'requests_limit' => 1000,
            'requests_remaining' => 988,
        ],
    ]);
    $client = makeClient($http);

    $response = $client->getAccountUsage();

    expect($response)->toBeInstanceOf(UsageResponse::class)
        ->and($response->getRequestsToday())->toBe(12)
        ->and($response->getRequestsLimit())->toBe(1000)
        ->and($response->getRequestsRemaining())->toBe(988);
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
