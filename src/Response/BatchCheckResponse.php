<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Throwable;

/**
 * The answer to POST /scan/batch.
 *
 * `getResults()` always has one CheckSpamResponse for every item you sent, in
 * the same order, and the same fail-open rules apply to each of them. If the
 * whole call fails, or the backend could not scan one item, that result has
 * `wasSkipped() === true` and `shouldBlock() === false`. Only an explicit
 * `blocked` verdict from the backend can block an item.
 */
final class BatchCheckResponse extends Response
{
    /** @var list<CheckSpamResponse> */
    private array $results;

    /**
     * @param array<string, mixed> $data Decoded JSON body, as received.
     * @param int $itemCount How many items the request had.
     */
    public function __construct(
        bool $success,
        int $httpCode,
        array $data,
        ?string $error,
        int $itemCount,
        float $scoreDenominator = ClientConfig::DEFAULT_SCORE_DENOMINATOR,
        ?string $errorCode = null,
        ?Throwable $failure = null,
    ) {
        parent::__construct($success, $httpCode, $data, $error, $errorCode);

        $this->results = $success && $failure === null
            ? self::parseResults($data, $itemCount, $scoreDenominator)
            : self::failedResults($httpCode, $data, $error, $itemCount, $scoreDenominator, $errorCode, $failure);
    }

    /**
     * The response for a call that threw: every item is skipped, nothing is blocked.
     */
    public static function failOpen(
        Throwable $failure,
        int $itemCount,
        float $scoreDenominator = ClientConfig::DEFAULT_SCORE_DENOMINATOR,
    ): self {
        return new self(
            false,
            0,
            [],
            $failure->getMessage(),
            $itemCount,
            $scoreDenominator,
            $failure instanceof SpamtrollException ? $failure->apiErrorCode : null,
            $failure,
        );
    }

    /**
     * One result for each item sent, in the same order.
     *
     * @return list<CheckSpamResponse>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<CheckSpamResponse>
     */
    private static function parseResults(array $data, int $itemCount, float $scoreDenominator): array
    {
        $payload = isset($data['success'], $data['data']) && $data['success'] === true && is_array($data['data'])
            ? $data['data']
            : $data;
        $items = isset($payload['results']) && is_array($payload['results'])
            ? array_values($payload['results'])
            : [];

        $results = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $item = $items[$i] ?? null;
            $status = is_array($item) ? ($item['status'] ?? null) : null;

            // The backend reports a single item it could not scan as
            // {"status":"error"} inside a 200. A missing or odd item is
            // treated the same way: no verdict, so nothing is blocked.
            if (!is_array($item) || !in_array($status, [
                CheckSpamResponse::STATUS_BLOCKED,
                CheckSpamResponse::STATUS_SUSPICIOUS,
                CheckSpamResponse::STATUS_SAFE,
            ], true)) {
                $results[] = CheckSpamResponse::failOpen(
                    new SpamtrollException('The backend did not scan this batch item', 200),
                    $scoreDenominator,
                );
                continue;
            }

            $results[] = new CheckSpamResponse(true, 200, $item, null, $scoreDenominator);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<CheckSpamResponse>
     */
    private static function failedResults(
        int $httpCode,
        array $data,
        ?string $error,
        int $itemCount,
        float $scoreDenominator,
        ?string $errorCode,
        ?Throwable $failure,
    ): array {
        $results = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $results[] = new CheckSpamResponse(false, $httpCode, $data, $error, $scoreDenominator, $errorCode, $failure);
        }

        return $results;
    }
}
