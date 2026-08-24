<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Spamtroll\Sdk\Internal\ErrorEnvelope;
use Throwable;

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

    /** getSkipReason() value when the SDK never reached a verdict because
     *  the call itself failed (timeout, DNS, 5xx, bad key, no key).
     */
    public const SKIP_TRANSPORT_ERROR = 'transport_error';

    /** getSkipReason() fallback for an HTTP 402 without a recognised code. */
    public const SKIP_PAYMENT_REQUIRED = 'payment_required';

    /** getSkipReason() fallback for an HTTP 429 without a recognised code. */
    public const SKIP_RATE_LIMITED = 'rate_limited';

    /** getSkipReason() value for a 2xx whose body carried no verdict. */
    public const SKIP_UNPARSEABLE_RESPONSE = 'unparseable_response';

    /** Upper bound on any server-supplied symbol or category name. These
     *  strings are rendered in moderation panels.
     */
    public const MAX_LABEL_LENGTH = 128;

    /** @var array<string, mixed> */
    private array $scanData;

    /** @var array<string, mixed> */
    private array $errorData;

    private float $scoreDenominator;

    /** Transport failure that produced a fail-open response, if any. */
    private ?Throwable $failure;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        bool $success,
        int $httpCode,
        array $data = [],
        ?string $error = null,
        float $scoreDenominator = ClientConfig::DEFAULT_SCORE_DENOMINATOR,
        ?string $errorCode = null,
        ?Throwable $failure = null,
    ) {
        parent::__construct($success, $httpCode, $data, $error, $errorCode);

        $this->scoreDenominator = $scoreDenominator > 0 ? $scoreDenominator : ClientConfig::DEFAULT_SCORE_DENOMINATOR;
        $this->failure = $failure;

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

    /**
     * The response every failure collapses into: no verdict, nothing blocked,
     * the original throwable kept for the caller's log.
     *
     * This is what makes fail-open a property of the SDK rather than a rule
     * six separate integrations have to remember. Only a successful scan can
     * ever produce a blocking verdict.
     */
    public static function failOpen(
        Throwable $failure,
        float $scoreDenominator = ClientConfig::DEFAULT_SCORE_DENOMINATOR,
    ): self {
        return new self(
            success: false,
            httpCode: 0,
            data: [],
            error: ErrorEnvelope::truncate($failure->getMessage()),
            scoreDenominator: $scoreDenominator,
            errorCode: self::errorCodeOf($failure),
            failure: $failure,
        );
    }

    /**
     * True when the backend actually classified the content. False for every
     * failure mode: transport error, quota exhaustion, rate limiting, a 4xx,
     * or a body the SDK could not parse.
     *
     * The three predicates below are derived from this, so a plugin can act
     * on the verdict without re-deriving one from the score.
     */
    public function hasVerdict(): bool
    {
        return $this->success
            && $this->failure === null
            && isset($this->scanData['status'])
            && is_string($this->scanData['status']);
    }

    /**
     * Server verdict: `blocked`, `suspicious` or `safe`.
     *
     * The server owns this decision — it applies the platform's configured
     * thresholds, which the SDK does not know. Branch on this, not on
     * getSpamScore(); the score is for display and tie-breaking.
     *
     * Falls back to `safe` whenever there is no verdict, which is the
     * fail-open default.
     */
    public function getStatus(): string
    {
        if (!$this->hasVerdict()) {
            return self::STATUS_SAFE;
        }

        /** @var string $status */
        $status = $this->scanData['status'];

        return $status;
    }

    public function isBlocked(): bool
    {
        return $this->getStatus() === self::STATUS_BLOCKED;
    }

    public function isSuspicious(): bool
    {
        return $this->getStatus() === self::STATUS_SUSPICIOUS;
    }

    public function isSafe(): bool
    {
        return $this->getStatus() === self::STATUS_SAFE;
    }

    /** Alias of isBlocked(), kept because plugins read better with it. */
    public function isSpam(): bool
    {
        return $this->isBlocked();
    }

    /** Block the content outright. True only for an explicit `blocked` verdict. */
    public function shouldBlock(): bool
    {
        return $this->isBlocked();
    }

    /** Hold the content for a human. True only for an explicit `suspicious` verdict. */
    public function shouldModerate(): bool
    {
        return $this->isSuspicious();
    }

    /**
     * True whenever no verdict was reached and the caller must fail open:
     * the transport failed, the backend refused to scan (402, 429, any 4xx),
     * or the body carried no classification at all.
     *
     * The exact inverse of hasVerdict(), deliberately — every branch that is
     * not "the server classified this content" ends in the same place.
     */
    public function wasSkipped(): bool
    {
        return !$this->hasVerdict();
    }

    /**
     * Machine-readable reason wasSkipped() is true; empty string otherwise.
     * Safe to log verbatim into a plugin's "skipped scans" table.
     */
    public function getSkipReason(): string
    {
        if ($this->hasVerdict()) {
            return '';
        }

        if ($this->failure !== null) {
            return self::SKIP_TRANSPORT_ERROR;
        }

        $code = $this->getErrorCode();
        $code = $code === null ? '' : strtolower($code);

        if ($this->httpCode === 402) {
            return $code === '' ? self::SKIP_PAYMENT_REQUIRED : $code;
        }
        if ($this->httpCode === 429) {
            return $code === '' ? self::SKIP_RATE_LIMITED : $code;
        }
        if ($this->httpCode < 200 || $this->httpCode >= 300) {
            return $code === '' ? 'http_' . $this->httpCode : $code;
        }

        // 2xx that carried no `status` — an HTML error page, an empty body,
        // a proxy answering on the API's behalf.
        return self::SKIP_UNPARSEABLE_RESPONSE;
    }

    /**
     * The throwable behind a fail-open response, for the caller's log. Null
     * when the round-trip itself succeeded.
     */
    public function getFailure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * True when the backend rejected the scan because the user's daily
     * quota was exhausted (HTTP 402, error.code = QUOTA_EXCEEDED).
     */
    public function isQuotaExceeded(): bool
    {
        return $this->httpCode === 402 && $this->getErrorCode() === self::ERROR_QUOTA_EXCEEDED;
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

    /**
     * Spam score normalized to 0.0–1.0.
     *
     * Backend uses an open-ended additive scale where the configured spam
     * threshold (default 15) means "definitely spam". Mapping raw/denominator
     * and clamping to 1.0 preserves signal between borderline spam (0.5) and
     * high-confidence spam (1.0) instead of collapsing everything >= threshold
     * into a single bucket.
     *
     * Display value. The blocking decision belongs to getStatus().
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
        if (!$this->success || $this->failure !== null) {
            return 0.0;
        }
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
        return array_values(array_map(
            static function (mixed $symbol): string {
                if (is_array($symbol)) {
                    $name = $symbol['name'] ?? null;

                    return is_scalar($name) ? ErrorEnvelope::truncate((string) $name, self::MAX_LABEL_LENGTH) : '';
                }

                return is_scalar($symbol) ? ErrorEnvelope::truncate((string) $symbol, self::MAX_LABEL_LENGTH) : '';
            },
            $this->rawSymbols(),
        ));
    }

    /**
     * @return array<int, mixed>
     */
    public function getSymbolDetails(): array
    {
        return $this->rawSymbols();
    }

    /**
     * @return array<int, string>
     */
    public function getThreatCategories(): array
    {
        if (!$this->success) {
            return [];
        }
        $categories = $this->scanData['threat_categories'] ?? [];
        if (!is_array($categories)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $category): string => is_scalar($category)
                ? ErrorEnvelope::truncate((string) $category, self::MAX_LABEL_LENGTH)
                : '',
            $categories,
        ));
    }

    /**
     * UUID of the stored submission, needed to send moderator feedback.
     *
     * Null when the backend could not persist the submission — the scan
     * still returns 200, `submission_id` is simply absent.
     */
    public function getSubmissionId(): ?string
    {
        return isset($this->scanData['submission_id']) && is_scalar($this->scanData['submission_id'])
            ? (string) $this->scanData['submission_id']
            : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function rawSymbols(): array
    {
        if (!$this->success) {
            return [];
        }
        $symbols = $this->scanData['symbols'] ?? [];

        return is_array($symbols) ? array_values($symbols) : [];
    }

    private static function errorCodeOf(Throwable $failure): ?string
    {
        return $failure instanceof SpamtrollException
            ? $failure->apiErrorCode
            : null;
    }
}
