<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Request;

final class CheckSpamRequest
{
    public const SOURCE_FORUM = 'forum';
    public const SOURCE_COMMENT = 'comment';
    public const SOURCE_MESSAGE = 'message';
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_GENERIC = 'generic';

    public string $content;

    public string $source;

    public ?string $ipAddress;

    public ?string $username;

    public ?string $email;

    public function __construct(
        string $content,
        string $source = self::SOURCE_GENERIC,
        ?string $ipAddress = null,
        ?string $username = null,
        ?string $email = null,
    ) {
        $this->content = $content;
        $this->source = $source;
        $this->ipAddress = $ipAddress;
        $this->username = $username;
        $this->email = $email;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = [
            'content' => $this->content,
            'source' => $this->source,
        ];

        if ($this->ipAddress !== null && $this->ipAddress !== '') {
            $data['ip_address'] = $this->ipAddress;
        }
        if ($this->username !== null && $this->username !== '') {
            $data['username'] = $this->username;
        }
        if ($this->email !== null && $this->email !== '') {
            $data['email'] = $this->email;
        }

        return $data;
    }
}
