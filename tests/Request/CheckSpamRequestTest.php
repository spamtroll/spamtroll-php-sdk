<?php

declare(strict_types=1);

use Spamtroll\Sdk\Request\CheckSpamRequest;

it('serialises a minimal payload to the canonical pair', function (): void {
    $request = new CheckSpamRequest('hello');

    expect($request->toArray())->toBe([
        'content' => 'hello',
        'source' => 'generic',
    ]);
});

it('serialises a fully-populated payload', function (): void {
    $request = new CheckSpamRequest('hi', 'comment', '1.2.3.4', 'alice', 'a@example.com');

    expect($request->toArray())->toBe([
        'content' => 'hi',
        'source' => 'comment',
        'ip_address' => '1.2.3.4',
        'username' => 'alice',
        'email' => 'a@example.com',
    ]);
});

it('omits optional fields when they are empty strings', function (): void {
    $request = new CheckSpamRequest('hi', 'forum', '', '', '');

    expect($request->toArray())->toBe([
        'content' => 'hi',
        'source' => 'forum',
    ]);
});
