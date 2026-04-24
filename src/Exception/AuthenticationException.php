<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

final class AuthenticationException extends SpamtrollException
{
    public static function invalidApiKey(): self
    {
        return new self('Invalid API key', 401, 'INVALID_API_KEY');
    }
}
