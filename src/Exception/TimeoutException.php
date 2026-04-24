<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

final class TimeoutException extends ConnectionException
{
    public static function afterSeconds(int $seconds): self
    {
        return new self(sprintf('Request timed out after %d seconds', $seconds), 0);
    }
}
