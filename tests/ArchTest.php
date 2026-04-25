<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests
|--------------------------------------------------------------------------
|
| Pest's arch plugin lets us assert structural rules about the codebase:
| no debug functions in production code, every class declares strict
| types, exception hierarchy is intact, etc.
|
*/

arch('production code uses strict types')
    ->expect('Spamtroll\\Sdk')
    ->toUseStrictTypes();

arch('production code stays in the SDK namespace')
    ->expect('Spamtroll\\Sdk')
    ->toOnlyBeUsedIn('Spamtroll\\Sdk');

arch('no debug helpers leak into the codebase')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('all SDK exceptions extend the base SpamtrollException')
    ->expect('Spamtroll\\Sdk\\Exception')
    ->toExtend(\Spamtroll\Sdk\Exception\SpamtrollException::class);

arch('the HTTP client contract is an interface')
    ->expect(\Spamtroll\Sdk\Http\HttpClientInterface::class)
    ->toBeInterface();

arch('SDK does not depend on Symfony or Laravel internals')
    ->expect('Spamtroll\\Sdk')
    ->not->toUse(['Symfony', 'Illuminate']);

arch('Response classes live under Response namespace')
    ->expect('Spamtroll\\Sdk\\Response')
    ->classes()
    ->toExtend(\Spamtroll\Sdk\Response\Response::class);
