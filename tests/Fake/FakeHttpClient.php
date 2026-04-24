<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Tests\Fake;

use RuntimeException;
use Spamtroll\Sdk\Http\HttpClientInterface;
use Spamtroll\Sdk\Http\HttpResponse;
use Throwable;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse|Throwable> */
    private array $queue = [];

    /** @var array<int, array{method:string, url:string, headers:array<string,string>, body:?string, timeout:int}> */
    public array $calls = [];

    public function queueResponse(int $statusCode, string $body = '', array $headers = []): self
    {
        $this->queue[] = new HttpResponse($statusCode, $body, $headers);
        return $this;
    }

    public function queueJson(int $statusCode, array $payload): self
    {
        return $this->queueResponse($statusCode, json_encode($payload) ?: '');
    }

    public function queueException(Throwable $e): self
    {
        $this->queue[] = $e;
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];

        if ($this->queue === []) {
            throw new RuntimeException('FakeHttpClient queue exhausted');
        }

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastCall(): array
    {
        if ($this->calls === []) {
            throw new RuntimeException('No calls recorded');
        }
        return $this->calls[count($this->calls) - 1];
    }
}
