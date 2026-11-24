<?php

declare(strict_types=1);

use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Request\BatchCheckRequest;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\BatchCheckResponse;
use Spamtroll\Sdk\Response\CheckSpamResponse;

function batchOf(int $count): BatchCheckRequest
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new CheckSpamRequest('message ' . $i, CheckSpamRequest::SOURCE_COMMENT, '203.0.113.' . $i);
    }

    return new BatchCheckRequest($items);
}

it('posts every item to /scan/batch and returns one result per item', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['results' => [
            ['status' => 'blocked', 'spam_score' => 20.0, 'submission_id' => 'aaa'],
            ['status' => 'safe', 'spam_score' => 1.0],
        ]],
    ]);

    $response = makeClient($http)->checkBatch(batchOf(2));
    $call = $http->lastCall();

    expect($response)->toBeInstanceOf(BatchCheckResponse::class)
        ->and($response->success)->toBeTrue()
        ->and($response->getResults())->toHaveCount(2)
        ->and($response->getResults()[0]->shouldBlock())->toBeTrue()
        ->and($response->getResults()[0]->getSubmissionId())->toBe('aaa')
        ->and($response->getResults()[1]->isSafe())->toBeTrue()
        ->and($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('https://api.spamtroll.io/api/v1/scan/batch')
        ->and($call['headers'])->toHaveKey('Idempotency-Key');

    $body = json_decode((string) $call['body'], true);
    expect($body['items'])->toHaveCount(2)
        ->and($body['items'][1]['content'])->toBe('message 2')
        ->and($body['items'][1]['ip_address'])->toBe('203.0.113.2');
});

it('skips a single item the backend could not scan', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['results' => [
            ['status' => 'error'],
            ['status' => 'suspicious', 'spam_score' => 9.0],
        ]],
    ]);

    $results = makeClient($http)->checkBatchOrHam(batchOf(2))->getResults();

    expect($results[0]->wasSkipped())->toBeTrue()
        ->and($results[0]->shouldBlock())->toBeFalse()
        ->and($results[1]->shouldModerate())->toBeTrue();
});

it('skips the items the backend left out of the answer', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['results' => [['status' => 'blocked', 'spam_score' => 20.0]]],
    ]);

    $results = makeClient($http)->checkBatchOrHam(batchOf(3))->getResults();

    expect($results)->toHaveCount(3)
        ->and($results[0]->shouldBlock())->toBeTrue()
        ->and($results[1]->wasSkipped())->toBeTrue()
        ->and($results[2]->wasSkipped())->toBeTrue()
        ->and($results[2]->shouldBlock())->toBeFalse();
});

it('returns skipped results when the quota is used up', function (): void {
    $http = fakeHttp()->queueJson(402, [
        'success' => false,
        'error' => ['code' => 'QUOTA_EXCEEDED', 'message' => 'Daily quota exceeded'],
    ]);

    $response = makeClient($http)->checkBatch(batchOf(2));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(402)
        ->and($response->getResults())->toHaveCount(2)
        ->and($response->getResults()[0]->wasSkipped())->toBeTrue()
        ->and($response->getResults()[0]->getSkipCategory())->toBe(CheckSpamResponse::SKIP_CATEGORY_QUOTA);
});

it('never blocks anything when checkBatchOrHam fails', function (): void {
    $http = fakeHttp()->queueException(ConnectionException::fromMessage('connection refused'));
    $client = makeClient($http);

    $response = $client->checkBatchOrHam(batchOf(2));

    expect($response->success)->toBeFalse()
        ->and($response->getResults())->toHaveCount(2);
    foreach ($response->getResults() as $result) {
        expect($result->wasSkipped())->toBeTrue()->and($result->shouldBlock())->toBeFalse();
    }
});

it('does not block on a bad API key with checkBatchOrHam, but checkBatch throws', function (): void {
    $http = fakeHttp()->queueResponse(401, '{"success":false}')->queueResponse(401, '{"success":false}');
    $client = makeClient($http);

    expect($client->checkBatchOrHam(batchOf(1))->getResults()[0]->shouldBlock())->toBeFalse();
    expect(fn () => $client->checkBatch(batchOf(1)))->toThrow(AuthenticationException::class);
});

it('skips every item for an HTML answer with status 200', function (): void {
    $http = fakeHttp()->queueResponse(200, '<html>captive portal</html>');

    $results = makeClient($http)->checkBatchOrHam(batchOf(2))->getResults();

    expect($results[0]->wasSkipped())->toBeTrue()
        ->and($results[1]->shouldBlock())->toBeFalse();
});

it('rejects an empty batch', function (): void {
    expect(fn () => new BatchCheckRequest([]))->toThrow(InvalidArgumentException::class);
});

it('accepts the maximum number of items and rejects one more', function (): void {
    expect(batchOf(BatchCheckRequest::MAX_ITEMS)->items)->toHaveCount(100);
    expect(fn () => batchOf(BatchCheckRequest::MAX_ITEMS + 1))->toThrow(InvalidArgumentException::class);
});

it('rejects an item that is not a CheckSpamRequest', function (): void {
    // @phpstan-ignore-next-line argument.type
    expect(fn () => new BatchCheckRequest(['hello']))->toThrow(InvalidArgumentException::class);
});

it('keeps the order of the items and re-indexes the list', function (): void {
    $request = new BatchCheckRequest([
        5 => new CheckSpamRequest('first'),
        9 => new CheckSpamRequest('second'),
    ]);

    expect(array_keys($request->items))->toBe([0, 1])
        ->and($request->toArray()['items'][1]['content'])->toBe('second');
});
