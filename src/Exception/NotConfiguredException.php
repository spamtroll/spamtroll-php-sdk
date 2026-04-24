<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

final class NotConfiguredException extends SpamtrollException
{
    public static function create(): self
    {
        return new self('API key not configured', 0, 'NOT_CONFIGURED');
    }
}
