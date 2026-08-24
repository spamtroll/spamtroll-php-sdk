<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Request;

final class CheckSpamRequest
{
    public const SOURCE_FORUM = 'forum';
    public const SOURCE_COMMENT = 'comment';
    public const SOURCE_MESSAGE = 'message';
    public const SOURCE_REGISTRATION = 'registration';
    public const SOURCE_CONTACT_FORM = 'contact_form';

    /**
     * Mail integrations must use this. The backend switches the RETVec model
     * to the e-mail one for `source: "email"`; anything else gets the web
     * model and a systematically shifted classification.
     */
    public const SOURCE_EMAIL = 'email';

    public const SOURCE_GENERIC = 'generic';

    /**
     * Matches the backend's per-item cap on /scan/batch. /scan/check has no
     * cap of its own beyond the framework body limit, so a multi-megabyte
     * body would simply run past the timeout and fail open — which an
     * attacker can trigger on purpose by padding the content.
     */
    public const MAX_CONTENT_BYTES = 65536;

    /**
     * @param string                $content    Plain-text body to scan.
     * @param string                $source     One of the SOURCE_* constants.
     * @param ?string               $ipAddress  Author IP. Strongly recommended — see docs/USAGE.md.
     * @param ?string               $username   Author display name.
     * @param ?string               $email      Author e-mail address.
     * @param ?string               $rawMessage Full RFC 822 message, byte for byte, for real DKIM verification.
     * @param array<string, string> $headers    E-mail headers (From, Subject, Authentication-Results, …).
     */
    public function __construct(
        public readonly string $content,
        public readonly string $source = self::SOURCE_GENERIC,
        public readonly ?string $ipAddress = null,
        public readonly ?string $username = null,
        public readonly ?string $email = null,
        public readonly ?string $rawMessage = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * True when toArray() had to cut the content down to MAX_CONTENT_BYTES.
     * Worth logging: the scan then saw only the head of the message.
     */
    public function isContentTruncated(): bool
    {
        return strlen($this->content) > self::MAX_CONTENT_BYTES;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'content' => $this->truncatedContent(),
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
        if ($this->rawMessage !== null && $this->rawMessage !== '') {
            $data['raw_message'] = $this->rawMessage;
        }
        if ($this->headers !== []) {
            $data['headers'] = $this->headers;
        }

        return $data;
    }

    private function truncatedContent(): string
    {
        if (!$this->isContentTruncated()) {
            return $this->content;
        }

        // Cut on a character boundary so the payload stays valid UTF-8.
        return mb_strcut($this->content, 0, self::MAX_CONTENT_BYTES);
    }
}
