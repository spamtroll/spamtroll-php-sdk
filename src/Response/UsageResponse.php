<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Response;

final class UsageResponse extends Response
{
    public function getRequestsToday(): int
    {
        return $this->intField('requests_today');
    }

    public function getRequestsLimit(): int
    {
        return $this->intField('requests_limit');
    }

    public function getRequestsRemaining(): int
    {
        return $this->intField('requests_remaining');
    }

    /**
     * @return array{requests_today:int, requests_limit:int, requests_remaining:int}
     */
    public function toArray(): array
    {
        return [
            'requests_today' => $this->getRequestsToday(),
            'requests_limit' => $this->getRequestsLimit(),
            'requests_remaining' => $this->getRequestsRemaining(),
        ];
    }

    private function intField(string $key): int
    {
        // Usage payload may come either at the top level or wrapped in a `data`
        // envelope — mirror the lookup both SDK-side Responses already do.
        if (isset($this->data[$key]) && is_numeric($this->data[$key])) {
            return (int) $this->data[$key];
        }
        $nested = $this->data['data'] ?? null;
        if (is_array($nested) && isset($nested[$key]) && is_numeric($nested[$key])) {
            return (int) $nested[$key];
        }
        return 0;
    }
}
