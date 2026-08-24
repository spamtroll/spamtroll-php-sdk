<?php

declare(strict_types=1);

use Spamtroll\Sdk\Exception\ServerException;
use Spamtroll\Sdk\Request\FeedbackRequest;
use Spamtroll\Sdk\Response\Response;

it('posts a moderator correction to /scan/feedback', function (): void {
    $http = fakeHttp()->queueJson(200, [
        'success' => true,
        'data' => ['message' => 'Feedback processed successfully'],
    ]);
    $client = makeClient($http);

    $response = $client->submitFeedback(
        FeedbackRequest::spam('7c9e6679-7425-40de-944b-e07fc1f90ae7', 'obvious pharma spam'),
    );

    $call = $http->lastCall();

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->success)->toBeTrue()
        ->and($response->getMessage())->toBe('Feedback processed successfully')
        ->and($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('https://api.spamtroll.io/api/v1/scan/feedback')
        ->and($call['headers'])->toHaveKey('Idempotency-Key');

    expect(json_decode((string) $call['body'], true))->toBe([
        'submission_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'correct_label' => 'spam',
        'reviewer_notes' => 'obvious pharma spam',
    ]);
});

it('omits reviewer notes when there are none', function (): void {
    $http = fakeHttp()->queueJson(200, ['success' => true, 'data' => []]);

    makeClient($http)->submitFeedback(FeedbackRequest::ham('7c9e6679-7425-40de-944b-e07fc1f90ae7'));

    expect(json_decode((string) $http->lastCall()['body'], true))->toBe([
        'submission_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'correct_label' => 'ham',
    ]);
});

it('surfaces a submission owned by another platform as a 404', function (): void {
    $http = fakeHttp()->queueJson(404, ['success' => false, 'error' => [
        'code' => 'NOT_FOUND',
        'message' => 'submission not found or not owned by this platform',
    ]]);

    $response = makeClient($http)->submitFeedback(FeedbackRequest::spam('nope'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(404)
        ->and($response->getErrorCode())->toBe('NOT_FOUND');
});

it('surfaces the daily feedback quota as a 429', function (): void {
    // Two independent sources: the 20/min limiter and the 100/platform/day
    // quota. Both arrive as 429, and neither should throw.
    $http = fakeHttp()->queueJson(429, ['success' => false, 'error' => [
        'code' => 'RATE_LIMITED',
        'message' => 'daily feedback limit exceeded',
    ]]);

    $response = makeClient($http)->submitFeedback(FeedbackRequest::spam('sub-1'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(429)
        ->and($response->getErrorCode())->toBe('RATE_LIMITED')
        ->and($http->callCount())->toBe(1);
});

it('never throws from trySubmitFeedback', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Failed to process feedback']])
        ->queueJson(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Failed to process feedback']]);

    $response = makeClient($http)->trySubmitFeedback(FeedbackRequest::spam('sub-1'));

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(0)
        ->and($response->error)->toBe('Failed to process feedback')
        ->and($response->getErrorCode())->toBe('INTERNAL_ERROR');
});

it('still throws from submitFeedback so callers can choose', function (): void {
    $http = fakeHttp()
        ->queueJson(500, ['error' => true, 'message' => 'boom'])
        ->queueJson(500, ['error' => true, 'message' => 'boom']);

    makeClient($http)->submitFeedback(FeedbackRequest::spam('sub-1'));
})->throws(ServerException::class);
