<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Tests\Response;

use PHPUnit\Framework\TestCase;
use Spamtroll\Sdk\Response\CheckSpamResponse;

final class CheckSpamResponseTest extends TestCase
{
    public function test_defaults_when_data_empty(): void
    {
        $response = new CheckSpamResponse(true, 200, []);

        self::assertSame(CheckSpamResponse::STATUS_SAFE, $response->getStatus());
        self::assertSame(0.0, $response->getSpamScore());
        self::assertSame(0.0, $response->getRawSpamScore());
        self::assertSame([], $response->getSymbols());
        self::assertSame([], $response->getSymbolDetails());
        self::assertSame([], $response->getThreatCategories());
        self::assertNull($response->getSubmissionId());
        self::assertNull($response->getRequestId());
        self::assertFalse($response->isSpam());
    }

    public function test_unwraps_envelope_when_success_true(): void
    {
        $response = new CheckSpamResponse(true, 200, [
            'success' => true,
            'data' => ['status' => 'blocked', 'spam_score' => 30],
        ]);

        self::assertSame(CheckSpamResponse::STATUS_BLOCKED, $response->getStatus());
        self::assertSame(1.0, $response->getSpamScore());
        self::assertTrue($response->isSpam());
    }

    public function test_falls_back_to_flat_payload(): void
    {
        $response = new CheckSpamResponse(true, 200, ['status' => 'suspicious', 'spam_score' => 9]);

        self::assertSame(CheckSpamResponse::STATUS_SUSPICIOUS, $response->getStatus());
        self::assertEqualsWithDelta(9 / 30, $response->getSpamScore(), 0.00001);
        self::assertFalse($response->isSpam());
    }

    public function test_score_boundaries(): void
    {
        self::assertSame(0.0, (new CheckSpamResponse(true, 200, ['spam_score' => 0]))->getSpamScore());
        self::assertEqualsWithDelta(
            0.5,
            (new CheckSpamResponse(true, 200, ['spam_score' => 15]))->getSpamScore(),
            0.00001
        );
        self::assertSame(1.0, (new CheckSpamResponse(true, 200, ['spam_score' => 30]))->getSpamScore());
        self::assertSame(1.0, (new CheckSpamResponse(true, 200, ['spam_score' => 999]))->getSpamScore());
        self::assertSame(0.0, (new CheckSpamResponse(true, 200, ['spam_score' => -5]))->getSpamScore());
    }

    public function test_custom_denominator(): void
    {
        $response = new CheckSpamResponse(true, 200, ['spam_score' => 15], null, 15.0);
        self::assertSame(1.0, $response->getSpamScore());
    }

    public function test_symbols_from_strings_and_arrays(): void
    {
        $response = new CheckSpamResponse(true, 200, [
            'symbols' => [
                'SIMPLE_STRING',
                ['name' => 'OBJECT_SYMBOL', 'score' => 3.5],
                ['no_name' => true],
            ],
        ]);

        self::assertSame(['SIMPLE_STRING', 'OBJECT_SYMBOL', ''], $response->getSymbols());
        self::assertCount(3, $response->getSymbolDetails());
    }

    public function test_threat_categories(): void
    {
        $response = new CheckSpamResponse(true, 200, [
            'threat_categories' => ['phishing', 'malware'],
        ]);

        self::assertSame(['phishing', 'malware'], $response->getThreatCategories());
    }

    public function test_isSpam_false_when_success_false(): void
    {
        $response = new CheckSpamResponse(false, 500, ['status' => 'blocked']);
        self::assertFalse($response->isSpam());
    }

    public function test_submission_id_and_request_id(): void
    {
        $response = new CheckSpamResponse(true, 200, [
            'request_id' => 'req-1',
            'data' => ['submission_id' => 'sub-1', 'status' => 'safe'],
            'success' => true,
        ]);

        self::assertSame('req-1', $response->getRequestId());
        self::assertSame('sub-1', $response->getSubmissionId());
    }
}
