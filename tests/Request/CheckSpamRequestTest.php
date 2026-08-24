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

it('exposes the email source the backend actually branches on', function (): void {
    // source === "email" switches the backend to the mail RETVec model.
    // Without the constant, mail integrations silently got the web model.
    expect(CheckSpamRequest::SOURCE_EMAIL)->toBe('email');

    $request = new CheckSpamRequest('body', CheckSpamRequest::SOURCE_EMAIL);

    expect($request->toArray()['source'])->toBe('email');
});

it('carries raw_message and e-mail headers when supplied', function (): void {
    // Without raw_message the authcheck stage falls back to header-only DKIM,
    // which an attacker can forge by pasting a DKIM-Signature header.
    $request = new CheckSpamRequest(
        content: 'body text',
        source: CheckSpamRequest::SOURCE_EMAIL,
        rawMessage: "From: a@example.com\r\nSubject: hi\r\n\r\nbody text",
        headers: ['From' => 'a@example.com', 'Subject' => 'hi'],
    );

    expect($request->toArray())->toBe([
        'content' => 'body text',
        'source' => 'email',
        'raw_message' => "From: a@example.com\r\nSubject: hi\r\n\r\nbody text",
        'headers' => ['From' => 'a@example.com', 'Subject' => 'hi'],
    ]);
});

it('truncates content at the size the backend is willing to scan', function (): void {
    // Padding a post past the size the backend can score in time is a
    // deterministic way to make every scan time out and fail open.
    $request = new CheckSpamRequest(str_repeat('x', CheckSpamRequest::MAX_CONTENT_BYTES + 1000));

    $content = $request->toArray()['content'];

    expect($request->isContentTruncated())->toBeTrue()
        ->and($content)->toBeString()
        ->and(strlen((string) $content))->toBe(CheckSpamRequest::MAX_CONTENT_BYTES);
});

it('leaves content below the cap untouched', function (): void {
    $request = new CheckSpamRequest('short body');

    expect($request->isContentTruncated())->toBeFalse()
        ->and($request->toArray()['content'])->toBe('short body');
});
