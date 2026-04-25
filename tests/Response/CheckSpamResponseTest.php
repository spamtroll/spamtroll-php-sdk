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
