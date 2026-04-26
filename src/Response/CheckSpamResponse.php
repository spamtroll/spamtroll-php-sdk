<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

use Spamtroll\Sdk\ClientConfig;

final class CheckSpamResponse extends Response
{
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_SUSPICIOUS = 'suspicious';
    public const STATUS_SAFE = 'safe';

    /** Backend error code returned with HTTP 402 when the user's daily
     *  scan quota is exhausted. Plugins should treat this as "let the
     *  message through unscanned" — see isQuotaExceeded() / wasSkipped().
     */
    public const ERROR_QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';

    /** @var array<string, mixed> */
    protected array $scanData;

    /** @var array<string, mixed> */
    protected array $errorData;

    protected float $scoreDenominator;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        bool $success,
        int $httpCode,
        array $data = [],
        ?string $error = null,
        float $scoreDenominator = ClientConfig::DEFAULT_SCORE_DENOMINATOR,
    ) {
        parent::__construct($success, $httpCode, $data, $error);

        $this->scoreDenominator = $scoreDenominator > 0 ? $scoreDenominator : ClientConfig::DEFAULT_SCORE_DENOMINATOR;

        // API envelope: {success: true, data: {...}}. Unwrap `data` when the
        // envelope explicitly marks the call successful; otherwise fall back
        // to the whole payload so flat responses still work.
        if (isset($data['success']) && $data['success'] === true && isset($data['data']) && is_array($data['data'])) {
            $this->scanData = $data['data'];
        } else {
            $this->scanData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        }

        // Capture the error envelope separately so isQuotaExceeded() and
        // getQuotaUsage() can read code + usage even when scanData is empty
        // (which it is on a 402 response).
        $this->errorData = isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
    }

    public function isSpam(): bool
    {
        // A quota-exceeded response is NOT spam — even though success=false,
        // the plugin should let the message through (fail-open contract).
        if ($this->wasSkipped()) {
            return false;
        }
        return $this->success && $this->getStatus() === self::STATUS_BLOCKED;
    }

    /**
     * True when the backend rejected the scan because the user's daily
     * quota was exhausted (HTTP 402, error.code = QUOTA_EXCEEDED).
     * Plugins MUST check this before treating !success as a transport
     * error — quota exhaustion is a normal operational state, not an
     * error to retry.
     */
    public function isQuotaExceeded(): bool
    {
        if ($this->httpCode !== 402) {
            return false;
        }
        $code = $this->errorData['code'] ?? null;
        return is_string($code) && $code === self::ERROR_QUOTA_EXCEEDED;
    }

    /**
     * True when the SDK could not produce a verdict and the plugin
     * should fail open (allow the content through unscanned). Today
     * this is just a synonym for isQuotaExceeded(); future SDK
     * versions may extend it (e.g. a "PLAN_DOWNGRADED" code).
     */
    public function wasSkipped(): bool
    {
        return $this->isQuotaExceeded();
    }

    /**
     * Machine-readable reason wasSkipped() is true. Empty string when
     * !wasSkipped(). Plugins can log this verbatim into their local
     * "skipped scans" table.
     */
    public function getSkipReason(): string
    {
        if ($this->isQuotaExceeded()) {
            return 'quota_exceeded';
        }
        return '';
    }

    /**
     * Returns the {current, limit, plan, reset_at} block from a 402
     * response so plugins can render a "you've used 200/200 today" hint
     * in their admin UI. All keys are optional — the backend is the
     * source of truth and may extend this in the future.
     *
     * @return array<string, mixed>
     */
    public function getQuotaUsage(): array
    {
        $usage = $this->errorData['usage'] ?? null;
        return is_array($usage) ? $usage : [];
    }

    public function getStatus(): string
    {
        return isset($this->scanData['status']) && is_string($this->scanData['status'])
            ? $this->scanData['status']
            : self::STATUS_SAFE;
    }

    /**
     * Spam score normalized to 0.0–1.0.
     *
     * Backend uses an open-ended additive scale where the configured spam
     * threshold (default 15) means "definitely spam". Mapping raw/denominator
     * and clamping to 1.0 preserves signal between borderline spam (0.5) and
     * high-confidence spam (1.0) instead of collapsing everything ≥ threshold
     * into a single bucket.
     */
    public function getSpamScore(): float
    {
        $raw = $this->getRawSpamScore();
        if ($raw <= 0.0) {
            return 0.0;
        }
        return min(1.0, $raw / $this->scoreDenominator);
    }

    public function getRawSpamScore(): float
    {
        if (!isset($this->scanData['spam_score']) || !is_numeric($this->scanData['spam_score'])) {
            return 0.0;
        }
        return (float) $this->scanData['spam_score'];
    }

    /**
     * @return array<int, string>
     */
    public function getSymbols(): array
    {
        $symbols = $this->scanData['symbols'] ?? [];
        if (!is_array($symbols)) {
            return [];
        }
        return array_values(array_map(
            static function ($s): string {
                if (is_array($s)) {
                    return isset($s['name']) && is_scalar($s['name']) ? (string) $s['name'] : '';
                }
                return is_scalar($s) ? (string) $s : '';
            },
            $symbols,
        ));
    }

    /**
     * @return array<int, mixed>
     */
    public function getSymbolDetails(): array
    {
        $symbols = $this->scanData['symbols'] ?? [];
        return is_array($symbols) ? array_values($symbols) : [];
    }

    /**
     * @return array<int, string>
     */
    public function getThreatCategories(): array
    {
        $cats = $this->scanData['threat_categories'] ?? [];
        if (!is_array($cats)) {
            return [];
        }
        return array_values(array_map(
            static fn ($c): string => is_scalar($c) ? (string) $c : '',
            $cats,
        ));
    }

    public function getSubmissionId(): ?string
    {
        return isset($this->scanData['submission_id']) && is_scalar($this->scanData['submission_id'])
            ? (string) $this->scanData['submission_id']
            : null;
    }
}
