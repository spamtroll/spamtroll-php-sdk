<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Http;

final class HttpResponse
{
    public int $statusCode;

    public string $body;

    /** @var array<string, string> Lowercased header name → value */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }
}
