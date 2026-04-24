<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Tests\Request;

use PHPUnit\Framework\TestCase;
use Spamtroll\Sdk\Request\CheckSpamRequest;

final class CheckSpamRequestTest extends TestCase
{
    public function test_minimal_payload(): void
    {
        $request = new CheckSpamRequest('hello');
        self::assertSame(['content' => 'hello', 'source' => 'generic'], $request->toArray());
    }

    public function test_full_payload(): void
    {
        $request = new CheckSpamRequest('hi', 'comment', '1.2.3.4', 'alice', 'a@example.com');
        self::assertSame([
            'content' => 'hi',
            'source' => 'comment',
            'ip_address' => '1.2.3.4',
            'username' => 'alice',
            'email' => 'a@example.com',
        ], $request->toArray());
    }

    public function test_empty_optional_fields_are_omitted(): void
    {
        $request = new CheckSpamRequest('hi', 'forum', '', '', '');
        self::assertSame(['content' => 'hi', 'source' => 'forum'], $request->toArray());
    }
}
