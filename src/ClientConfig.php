<?php

declare(strict_types=1);

namespace Spamtroll\Sdk;

use Spamtroll\Sdk\Exception\InvalidConfigurationException;

final class ClientConfig
{
    public const DEFAULT_BASE_URL = 'https://api.spamtroll.io/api/v1';

    /**
     * Interactive defaults. A scan runs inside the HTTP request the visitor
     * is waiting on, so the whole call has to finish well inside a typical
     * PHP-FPM request_terminate_timeout (often 10–15 s). Worst case with
     * these values is ~6.25 s; see docs/CONFIGURATION.md for the table.
     */
    public const DEFAULT_TIMEOUT = 3;
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_RETRY_BASE_DELAY_MS = 250;
    public const DEFAULT_TOTAL_BUDGET_MS = 6000;

    public const DEFAULT_SCORE_DENOMINATOR = 30.0;

    public readonly string $baseUrl;

    public readonly int $timeout;

    public readonly int $maxRetries;

    public readonly int $retryBaseDelayMs;

    public readonly int $totalBudgetMs;

    public readonly ?string $userAgent;

    public readonly float $scoreDenominator;

    /**
     * @throws InvalidConfigurationException When baseUrl is not an http(s) URL.
     */
    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryBaseDelayMs = self::DEFAULT_RETRY_BASE_DELAY_MS,
        ?string $userAgent = null,
        float $scoreDenominator = self::DEFAULT_SCORE_DENOMINATOR,
        int $totalBudgetMs = self::DEFAULT_TOTAL_BUDGET_MS,
    ) {
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(1, $maxRetries);
        $this->retryBaseDelayMs = max(0, $retryBaseDelayMs);
        $this->totalBudgetMs = max(0, $totalBudgetMs);
        $this->userAgent = $userAgent;
        $this->scoreDenominator = $scoreDenominator > 0 ? $scoreDenominator : self::DEFAULT_SCORE_DENOMINATOR;
    }

    /**
     * baseUrl is typically an admin-editable text field. Without a scheme
     * check, `file:///etc/passwd` or `gopher://…` is a working request the
     * transport will happily perform, and the response body lands in plugin
     * logs. Only http(s) is ever a legitimate value here.
     *
     * @throws InvalidConfigurationException
     */
    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw InvalidConfigurationException::unsupportedScheme($scheme);
        }

        return $trimmed;
    }
}
