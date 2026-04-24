<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

class ConnectionException extends SpamtrollException
{
    public static function fromMessage(string $error): self
    {
        return new static('Connection failed: ' . $error, 0);
    }
}
