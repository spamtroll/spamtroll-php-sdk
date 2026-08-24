<?php

declare(strict_types=1);

use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;
use Spamtroll\Sdk\Tests\Fake\FakeHttpClient;

/*
|--------------------------------------------------------------------------
| Fail-open contract
|--------------------------------------------------------------------------
|
| The project rule is absolute: when the API cannot answer, the content is
| ham. checkSpamOrHam() is where the SDK owns that rule instead of leaving
| it to six separate plugins to remember.
|
| Every failure mode the backend and the transport can produce goes through
| the same three assertions: nothing propagates, nothing is spam, and the
| caller can tell it was skipped.
|
*/

/**
 * @return array<string, callable(FakeHttpClient): void>
 */
function failureModes(): array
{
    return [
        'connection refused' => static fn (FakeHttpClient $http) => $http
            ->queueException(ConnectionException::fromMessage('connection refused'))
            ->queueException(ConnectionException::fromMessage('connection refused')),
        'timeout' => static fn (FakeHttpClient $http) => $http
            ->queueException(TimeoutException::afterSeconds(3))
            ->queueException(TimeoutException::afterSeconds(3)),
        'invalid api key (401)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(401, ['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key']]),
        'account blocked (403)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(403, ['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'Account is blocked']]),
        'validation error (422)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(422, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Content is required']]),
        'route missing (404, legacy envelope)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(404, ['error' => true, 'message' => 'Cannot POST /api/v1/scan/chek']),
        'rate limited (429, legacy envelope)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(429, ['error' => true, 'message' => 'Rate limit exceeded. Maximum 100 requests per minute.']),
        'quota exhausted (402)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(402, ['success' => false, 'error' => ['code' => 'QUOTA_EXCEEDED', 'message' => 'Daily scan limit reached.']]),
        'server error (500)' => static fn (FakeHttpClient $http) => $http
            ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']])
            ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Scan failed']]),
        'gateway html error page' => static fn (FakeHttpClient $http) => $http
            ->queueResponse(502, '<html><body>502 Bad Gateway</body></html>')
            ->queueResponse(502, '<html><body>502 Bad Gateway</body></html>'),
        'captive portal answering 200 with html' => static fn (FakeHttpClient $http) => $http
            ->queueResponse(200, '<html><body>Sign in to continue</body></html>'),
        'empty body on 200' => static fn (FakeHttpClient $http) => $http
            ->queueResponse(200, ''),
        'adapter reports no http status' => static fn (FakeHttpClient $http) => $http
            ->queueResponse(0, 'SECRET-CANARY')
            ->queueResponse(0, 'SECRET-CANARY'),
        'adapter leaks a foreign exception' => static fn (FakeHttpClient $http) => $http
            ->queueException(new RuntimeException('IPS\\Http\\Url\\Exception: malformed url')),
        'adapter leaks an Error' => static fn (FakeHttpClient $http) => $http
            ->queueException(new TypeError('wp_remote_request(): Argument #1 must be of type string')),
        'redirect to another host' => static fn (FakeHttpClient $http) => $http
            ->queueResponse(302, '', ['location' => 'https://evil.example/scan/check']),
    ];
}

it('never throws and never blocks, whatever the failure', function (string $mode): void {
    $http = fakeHttp();
    failureModes()[$mode]($http);

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest('hello world', 'comment'));

    expect($response)->toBeInstanceOf(CheckSpamResponse::class)
        ->and($response->isSpam())->toBeFalse()
        ->and($response->shouldBlock())->toBeFalse()
        ->and($response->shouldModerate())->toBeFalse()
        ->and($response->getStatus())->toBe(CheckSpamResponse::STATUS_SAFE)
        ->and($response->hasVerdict())->toBeFalse()
        ->and($response->getSpamScore())->toBe(0.0)
        ->and($response->wasSkipped())->toBeTrue()
        ->and($response->getSkipReason())->not->toBe('');
})->with(array_keys(failureModes()));

it('never throws when the API key was never configured', function (): void {
    $client = new Client('', new ClientConfig(retryBaseDelayMs: 0), new FakeHttpClient());

    $response = $client->checkSpamOrHam(new CheckSpamRequest('hello'));

    expect($response->isSpam())->toBeFalse()
        ->and($response->wasSkipped())->toBeTrue()
        ->and($response->getSkipReason())->toBe(CheckSpamResponse::SKIP_TRANSPORT_ERROR)
        ->and($response->getFailure())->toBeInstanceOf(\Spamtroll\Sdk\Exception\NotConfiguredException::class);
});

it('keeps the underlying failure for the caller to log', function (): void {
    $http = fakeHttp()
        ->queueException(TimeoutException::afterSeconds(3))
        ->queueException(TimeoutException::afterSeconds(3));

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest('x'));

    expect($response->getFailure())->toBeInstanceOf(TimeoutException::class)
        ->and($response->error)->toContain('timed out')
        ->and($response->getSkipReason())->toBe(CheckSpamResponse::SKIP_TRANSPORT_ERROR);
});

it('still returns a real verdict when the API answers', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['status' => 'blocked', 'spam_score' => 21.5, 'submission_id' => 'sub-9'],
    ]);

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest('buy pills'));

    expect($response->hasVerdict())->toBeTrue()
        ->and($response->isSpam())->toBeTrue()
        ->and($response->shouldBlock())->toBeTrue()
        ->and($response->wasSkipped())->toBeFalse()
        ->and($response->getSkipReason())->toBe('')
        ->and($response->getSubmissionId())->toBe('sub-9');
});

it('reports quota exhaustion as a skip, not as a transport failure', function (): void {
    $http = fakeHttp()->queueJson(402, [
        'success' => false,
        'error' => [
            'code' => 'QUOTA_EXCEEDED',
            'message' => 'Daily scan limit reached. Upgrade your plan at /dashboard/billing.',
            'usage' => ['current' => 200, 'limit' => 200, 'plan' => 'free'],
        ],
    ]);

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest('x'));

    expect($response->wasSkipped())->toBeTrue()
        ->and($response->isQuotaExceeded())->toBeTrue()
        ->and($response->getSkipReason())->toBe('quota_exceeded')
        ->and($response->getFailure())->toBeNull()
        ->and($response->getQuotaUsage())->toMatchArray(['current' => 200, 'limit' => 200]);
});

it('treats an unrecognised 402 code as a skip as well', function (): void {
    // The skip decision follows the response class (402 = the backend
    // declined to scan), not one hard-coded code string. A new billing code
    // must not silently turn into "scan produced no symbols".
    $http = fakeHttp()->queueJson(402, [
        'success' => false,
        'error' => ['code' => 'PLAN_DOWNGRADED', 'message' => 'Plan no longer covers scanning'],
    ]);

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest('x'));

    expect($response->wasSkipped())->toBeTrue()
        ->and($response->isQuotaExceeded())->toBeFalse()
        ->and($response->getSkipReason())->toBe('plan_downgraded');
});

it('scans content that is not valid UTF-8 instead of refusing it', function (): void {
    // A single illegal byte used to make json_encode() fail, which threw and
    // meant the content was never scanned at all — a bypass costing an
    // attacker one byte.
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => ['status' => 'blocked', 'spam_score' => 20]]);

    $response = makeClient($http)->checkSpamOrHam(new CheckSpamRequest("caf\xE9 latin1 body", 'comment'));

    expect($response->hasVerdict())->toBeTrue()
        ->and($response->isSpam())->toBeTrue()
        ->and($http->callCount())->toBe(1);

    $body = $http->lastCall()['body'];
    expect($body)->toBeString()
        ->and(json_decode((string) $body, true))->toBeArray();
});
