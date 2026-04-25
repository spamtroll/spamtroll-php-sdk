<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

use RuntimeException;
use Throwable;

class SpamtrollException extends RuntimeException
{
    public int $httpCode;

    public ?string $apiErrorCode;

    /** @var array<string, mixed>|null */
    public ?array $responseData;

    /**
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message,
        int $httpCode = 0,
        ?string $apiErrorCode = null,
        ?array $responseData = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);

        $this->httpCode = $httpCode;
        $this->apiErrorCode = $apiErrorCode;
        $this->responseData = $responseData;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function fromResponse(int $httpCode, ?array $data = null): self
    {
        $message = 'Unknown API error';
        $apiErrorCode = null;

        if (is_array($data)) {
            if (isset($data['error'])) {
                $message = self::stringify($data['error']);
            } elseif (isset($data['message'])) {
                $message = self::stringify($data['message']);
            }
            if (isset($data['code']) && is_scalar($data['code'])) {
                $apiErrorCode = (string) $data['code'];
            }
        }

        return new self($message, $httpCode, $apiErrorCode, $data);
    }

    private static function stringify(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value);
        return $encoded === false ? 'Unknown API error' : $encoded;
    }
}
