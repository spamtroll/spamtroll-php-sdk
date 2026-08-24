<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Exception;

/**
 * The SDK was handed a configuration it cannot honour — today, a base URL
 * that is not http(s).
 *
 * It extends SpamtrollException on purpose: an integrator who wired a single
 * `catch (SpamtrollException)` around their scan path catches this too, so a
 * typo in an admin settings field cannot take a site down.
 */
final class InvalidConfigurationException extends SpamtrollException
{
    public static function unsupportedScheme(string $scheme): self
    {
        return new self(
            $scheme === ''
                ? 'baseUrl must be an absolute http(s) URL'
                : sprintf('baseUrl must use http or https, got "%s"', $scheme),
            0,
            'INVALID_CONFIGURATION',
        );
    }
}
