<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

class Response
{
    public bool $success;

    public int $httpCode;

    /** @var array<string, mixed> */
    public array $data;

    public ?string $error;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(bool $success, int $httpCode, array $data = [], ?string $error = null)
    {
        $this->success = $success;
        $this->httpCode = $httpCode;
        $this->data = $data;
        $this->error = $error;
    }

    public function isConnectionValid(): bool
    {
        return $this->success && $this->httpCode >= 200 && $this->httpCode < 300;
    }

    public function getRequestId(): ?string
    {
        return isset($this->data['request_id']) && is_scalar($this->data['request_id'])
            ? (string) $this->data['request_id']
            : null;
    }

    public function getMessage(): ?string
    {
        return isset($this->data['message']) && is_scalar($this->data['message'])
            ? (string) $this->data['message']
            : null;
    }
}
