<?php

declare(strict_types=1);

use Spamtroll\Sdk\Response\CheckSpamResponse;

it('falls back to safe defaults when the payload is empty', function (): void {
    $response = new CheckSpamResponse(true, 200, []);

    expect($response->getStatus())->toBe(CheckSpamResponse::STATUS_SAFE)
        ->and($response->getSpamScore())->toBe(0.0)
        ->and($response->getRawSpamScore())->toBe(0.0)
        ->and($response->getSymbols())->toBe([])
        ->and($response->getSymbolDetails())->toBe([])
        ->and($response->getThreatCategories())->toBe([])
        ->and($response->getSubmissionId())->toBeNull()
        ->and($response->getRequestId())->toBeNull()
        ->and($response->isSpam())->toBeFalse();
});

it('unwraps the API envelope when success is true', function (): void {
    $response = new CheckSpamResponse(true, 200, [
        'success' => true,
        'data' => ['status' => 'blocked', 'spam_score' => 30],
    ]);

    expect($response->getStatus())->toBe(CheckSpamResponse::STATUS_BLOCKED)
        ->and($response->getSpamScore())->toBe(1.0)
        ->and($response->isSpam())->toBeTrue();
});

it('falls back to a flat payload when no envelope is present', function (): void {
    $response = new CheckSpamResponse(true, 200, ['status' => 'suspicious', 'spam_score' => 9]);

    expect($response->getStatus())->toBe(CheckSpamResponse::STATUS_SUSPICIOUS)
        ->and($response->getSpamScore())->toEqualWithDelta(9 / 30, 0.00001)
        ->and($response->isSpam())->toBeFalse();
});

it('clamps and zeroes the score at the boundaries', function (): void {
    expect((new CheckSpamResponse(true, 200, ['spam_score' => 0]))->getSpamScore())->toBe(0.0);
    expect((new CheckSpamResponse(true, 200, ['spam_score' => 15]))->getSpamScore())
        ->toEqualWithDelta(0.5, 0.00001);
    expect((new CheckSpamResponse(true, 200, ['spam_score' => 30]))->getSpamScore())->toBe(1.0);
    expect((new CheckSpamResponse(true, 200, ['spam_score' => 999]))->getSpamScore())->toBe(1.0);
    expect((new CheckSpamResponse(true, 200, ['spam_score' => -5]))->getSpamScore())->toBe(0.0);
});

it('honours a custom score denominator', function (): void {
    $response = new CheckSpamResponse(true, 200, ['spam_score' => 15], null, 15.0);

    expect($response->getSpamScore())->toBe(1.0);
});

it('extracts symbol names from string and array entries', function (): void {
    $response = new CheckSpamResponse(true, 200, [
        'symbols' => [
            'SIMPLE_STRING',
            ['name' => 'OBJECT_SYMBOL', 'score' => 3.5],
            ['no_name' => true],
        ],
    ]);

    expect($response->getSymbols())->toBe(['SIMPLE_STRING', 'OBJECT_SYMBOL', ''])
        ->and($response->getSymbolDetails())->toHaveCount(3);
});

it('extracts threat categories', function (): void {
    $response = new CheckSpamResponse(true, 200, [
        'threat_categories' => ['phishing', 'malware'],
    ]);

    expect($response->getThreatCategories())->toBe(['phishing', 'malware']);
});

it('reports not-spam when the response is unsuccessful', function (): void {
    $response = new CheckSpamResponse(false, 500, ['status' => 'blocked']);

    expect($response->isSpam())->toBeFalse();
});

it('returns the submission id and the request id', function (): void {
    $response = new CheckSpamResponse(true, 200, [
        'request_id' => 'req-1',
        'data' => ['submission_id' => 'sub-1', 'status' => 'safe'],
        'success' => true,
    ]);

    expect($response->getRequestId())->toBe('req-1')
        ->and($response->getSubmissionId())->toBe('sub-1');
});

it('treats the server status as the verdict, not the score', function (): void {
    // Four plugins independently re-derived a verdict from the score because
    // the SDK made `status` awkward to reach. The server owns the platform's
    // thresholds; the SDK does not know them.
    $blocked = new CheckSpamResponse(true, 200, [
        'success' => true,
        'data' => ['status' => 'blocked', 'spam_score' => 1.0],
    ]);
    $suspicious = new CheckSpamResponse(true, 200, [
        'success' => true,
        'data' => ['status' => 'suspicious', 'spam_score' => 28.0],
    ]);
    $safe = new CheckSpamResponse(true, 200, [
        'success' => true,
        'data' => ['status' => 'safe', 'spam_score' => 14.9],
    ]);

    expect($blocked->isBlocked())->toBeTrue()
        ->and($blocked->shouldBlock())->toBeTrue()
        ->and($blocked->shouldModerate())->toBeFalse()
        ->and($blocked->getSpamScore())->toBeLessThan(0.1)

        ->and($suspicious->shouldModerate())->toBeTrue()
        ->and($suspicious->shouldBlock())->toBeFalse()
        ->and($suspicious->isSpam())->toBeFalse()
        ->and($suspicious->getSpamScore())->toBeGreaterThan(0.9)

        ->and($safe->isSafe())->toBeTrue()
        ->and($safe->shouldBlock())->toBeFalse()
        ->and($safe->shouldModerate())->toBeFalse();
});

it('has no verdict, and therefore no status, when the call failed', function (): void {
    $response = new CheckSpamResponse(false, 500, ['data' => ['status' => 'blocked']]);

    expect($response->hasVerdict())->toBeFalse()
        ->and($response->getStatus())->toBe(CheckSpamResponse::STATUS_SAFE)
        ->and($response->isSafe())->toBeTrue()
        ->and($response->wasSkipped())->toBeTrue()
        ->and($response->getRawSpamScore())->toBe(0.0)
        ->and($response->getSymbols())->toBe([]);
});

it('builds a fail-open response from a throwable', function (): void {
    $failure = new RuntimeException('adapter exploded');

    $response = CheckSpamResponse::failOpen($failure);

    expect($response->success)->toBeFalse()
        ->and($response->httpCode)->toBe(0)
        ->and($response->isSpam())->toBeFalse()
        ->and($response->hasVerdict())->toBeFalse()
        ->and($response->getStatus())->toBe(CheckSpamResponse::STATUS_SAFE)
        ->and($response->wasSkipped())->toBeTrue()
        ->and($response->getSkipReason())->toBe(CheckSpamResponse::SKIP_TRANSPORT_ERROR)
        ->and($response->getFailure())->toBe($failure)
        ->and($response->error)->toBe('adapter exploded');
});

it('finds the request id inside the error envelope', function (): void {
    // The backend never puts request_id at the top level; it lives in
    // error.request_id, which is the only handle support has on a report.
    $response = new CheckSpamResponse(false, 422, [
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Content is required', 'request_id' => 'req-42'],
    ]);

    expect($response->getRequestId())->toBe('req-42')
        ->and($response->getMessage())->toBe('Content is required')
        ->and($response->getErrorCode())->toBe('VALIDATION_ERROR');
});

it('truncates server-supplied labels before a moderator ever sees them', function (): void {
    $response = new CheckSpamResponse(true, 200, [
        'success' => true,
        'data' => [
            'status' => 'safe',
            'symbols' => [str_repeat('A', 5000)],
            'threat_categories' => [str_repeat('B', 5000)],
        ],
    ]);

    expect(mb_strlen($response->getSymbols()[0]))
        ->toBeLessThanOrEqual(CheckSpamResponse::MAX_LABEL_LENGTH + 1)
        ->and(mb_strlen($response->getThreatCategories()[0]))
        ->toBeLessThanOrEqual(CheckSpamResponse::MAX_LABEL_LENGTH + 1);
});
