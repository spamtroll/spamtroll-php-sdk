<?php

declare(strict_types=1);

namespace Spamtroll\Sdk;

final class ClientConfig
{
    public const DEFAULT_BASE_URL = 'https://api.spamtroll.io/api/v1';
    public const DEFAULT_TIMEOUT = 5;
    public const DEFAULT_MAX_RETRIES = 3;
    public const DEFAULT_RETRY_BASE_DELAY_MS = 500;
    public const DEFAULT_SCORE_DENOMINATOR = 30.0;

    public string $baseUrl;

    public int $timeout;

    public int $maxRetries;

    public int $retryBaseDelayMs;

    public ?string $userAgent;

    public float $scoreDenominator;

    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryBaseDelayMs = self::DEFAULT_RETRY_BASE_DELAY_MS,
        ?string $userAgent = null,
        float $scoreDenominator = self::DEFAULT_SCORE_DENOMINATOR,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(1, $maxRetries);
        $this->retryBaseDelayMs = max(0, $retryBaseDelayMs);
        $this->userAgent = $userAgent;
        $this->scoreDenominator = $scoreDenominator > 0 ? $scoreDenominator : self::DEFAULT_SCORE_DENOMINATOR;
    }
}
