<?php

declare(strict_types=1);

namespace Spamtroll\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Spamtroll\Sdk\Client;
use Spamtroll\Sdk\ClientConfig;
use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\ConnectionException;
use Spamtroll\Sdk\Exception\NotConfiguredException;
use Spamtroll\Sdk\Exception\ServerException;
use Spamtroll\Sdk\Exception\TimeoutException;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;
use Spamtroll\Sdk\Response\UsageResponse;
use Spamtroll\Sdk\Tests\Fake\FakeHttpClient;
use Spamtroll\Sdk\Version;

final class ClientTest extends TestCase
{
    private function makeClient(FakeHttpClient $http, ?ClientConfig $config = null, string $apiKey = 'test-key'): Client
    {
        return new Client($apiKey, $config ?? new ClientConfig(retryBaseDelayMs: 0), $http);
    }

    public function test_not_configured_throws(): void
    {
        $client = new Client('', new ClientConfig(retryBaseDelayMs: 0), new FakeHttpClient());

        $this->expectException(NotConfiguredException::class);
        $client->checkSpam(new CheckSpamRequest('anything'));
    }

    public function test_checkSpam_sends_post_with_json_and_headers(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'success' => true,
            'data' => ['status' => 'safe', 'spam_score' => 0],
        ]);
        $client = $this->makeClient($http);

        $client->checkSpam(new CheckSpamRequest('hello', 'comment', '1.2.3.4', 'bob', 'bob@example.com'));

        $call = $http->lastCall();
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.spamtroll.io/api/v1/scan/check', $call['url']);
        self::assertSame('test-key', $call['headers']['X-API-Key']);
        self::assertSame('application/json', $call['headers']['Content-Type']);
        self::assertSame('application/json', $call['headers']['Accept']);
        self::assertSame('spamtroll-php-sdk/' . Version::VERSION, $call['headers']['User-Agent']);

        self::assertIsString($call['body']);
        self::assertSame([
            'content' => 'hello',
            'source' => 'comment',
            'ip_address' => '1.2.3.4',
            'username' => 'bob',
            'email' => 'bob@example.com',
        ], json_decode($call['body'], true));
    }

    public function test_checkSpam_returns_success_response_with_normalized_score(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'success' => true,
            'data' => ['status' => 'blocked', 'spam_score' => 15, 'submission_id' => 'abc-123'],
        ]);
        $client = $this->makeClient($http);

        $response = $client->checkSpam(new CheckSpamRequest('spam'));

        self::assertInstanceOf(CheckSpamResponse::class, $response);
        self::assertTrue($response->success);
        self::assertSame(200, $response->httpCode);
        self::assertSame(CheckSpamResponse::STATUS_BLOCKED, $response->getStatus());
        self::assertSame(0.5, $response->getSpamScore());
        self::assertSame(15.0, $response->getRawSpamScore());
        self::assertSame('abc-123', $response->getSubmissionId());
        self::assertTrue($response->isSpam());
    }

    public function test_401_throws_authentication(): void
    {
        $http = (new FakeHttpClient())->queueJson(401, ['error' => 'bad key']);
        $client = $this->makeClient($http);

        $this->expectException(AuthenticationException::class);
        $client->checkSpam(new CheckSpamRequest('x'));
    }

    public function test_429_returns_unsuccessful_response_without_retry(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(429, ['error' => 'rate limited'])
            ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
        $client = $this->makeClient($http);

        $response = $client->checkSpam(new CheckSpamRequest('x'));

        self::assertFalse($response->success);
        self::assertSame(429, $response->httpCode);
        self::assertSame('rate limited', $response->error);
        self::assertSame(1, $http->callCount(), '429 must not retry');
    }

    public function test_other_4xx_returns_response_without_retry(): void
    {
        $http = (new FakeHttpClient())->queueJson(400, ['error' => 'bad request']);
        $client = $this->makeClient($http);

        $response = $client->checkSpam(new CheckSpamRequest('x'));

        self::assertFalse($response->success);
        self::assertSame(400, $response->httpCode);
        self::assertSame('bad request', $response->error);
        self::assertSame(1, $http->callCount());
    }

    public function test_5xx_retries_then_throws_server_exception(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(500, ['error' => 'boom'])
            ->queueJson(502, ['error' => 'bad gateway'])
            ->queueJson(503, ['error' => 'unavailable']);
        $client = $this->makeClient($http);

        try {
            $client->checkSpam(new CheckSpamRequest('x'));
            self::fail('Expected ServerException');
        } catch (ServerException $e) {
            self::assertSame(503, $e->httpCode);
            self::assertSame(3, $http->callCount());
        }
    }

    public function test_5xx_then_success_recovers(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(500, ['error' => 'transient'])
            ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe', 'spam_score' => 0]]);
        $client = $this->makeClient($http);

        $response = $client->checkSpam(new CheckSpamRequest('x'));

        self::assertTrue($response->success);
        self::assertSame(2, $http->callCount());
    }

    public function test_connection_exception_retries(): void
    {
        $http = (new FakeHttpClient())
            ->queueException(ConnectionException::fromMessage('dns fail'))
            ->queueException(TimeoutException::afterSeconds(5))
            ->queueJson(200, ['success' => true, 'data' => ['status' => 'safe']]);
        $client = $this->makeClient($http);

        $response = $client->checkSpam(new CheckSpamRequest('x'));

        self::assertTrue($response->success);
        self::assertSame(3, $http->callCount());
    }

    public function test_connection_exception_after_retries_rethrows(): void
    {
        $http = (new FakeHttpClient())
            ->queueException(ConnectionException::fromMessage('fail 1'))
            ->queueException(ConnectionException::fromMessage('fail 2'))
            ->queueException(TimeoutException::afterSeconds(5));
        $client = $this->makeClient($http);

        $this->expectException(TimeoutException::class);
        $client->checkSpam(new CheckSpamRequest('x'));
    }

    public function test_testConnection_uses_get_status_endpoint(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['success' => true, 'status' => 'ok']);
        $client = $this->makeClient($http);

        $response = $client->testConnection();

        self::assertTrue($response->isConnectionValid());
        self::assertSame('GET', $http->lastCall()['method']);
        self::assertSame('https://api.spamtroll.io/api/v1/scan/status', $http->lastCall()['url']);
        self::assertNull($http->lastCall()['body']);
    }

    public function test_getAccountUsage_parses_usage_fields(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'success' => true,
            'data' => [
                'requests_today' => 12,
                'requests_limit' => 1000,
                'requests_remaining' => 988,
            ],
        ]);
        $client = $this->makeClient($http);

        $response = $client->getAccountUsage();

        self::assertInstanceOf(UsageResponse::class, $response);
        self::assertSame(12, $response->getRequestsToday());
        self::assertSame(1000, $response->getRequestsLimit());
        self::assertSame(988, $response->getRequestsRemaining());
    }

    public function test_custom_user_agent_overrides_default(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['success' => true, 'data' => []]);
        $config = new ClientConfig(userAgent: 'my-plugin/2.3.4', retryBaseDelayMs: 0);
        $client = $this->makeClient($http, $config);

        $client->checkSpam(new CheckSpamRequest('x'));

        self::assertSame('my-plugin/2.3.4', $http->lastCall()['headers']['User-Agent']);
    }

    public function test_custom_base_url_is_respected(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['success' => true, 'data' => []]);
        $config = new ClientConfig(baseUrl: 'https://staging.spamtroll.io/api/v1/', retryBaseDelayMs: 0);
        $client = $this->makeClient($http, $config);

        $client->checkSpam(new CheckSpamRequest('x'));

        self::assertSame(
            'https://staging.spamtroll.io/api/v1/scan/check',
            $http->lastCall()['url']
        );
    }

    public function test_custom_score_denominator_is_applied(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'success' => true,
            'data' => ['status' => 'blocked', 'spam_score' => 15],
        ]);
        $config = new ClientConfig(scoreDenominator: 15.0, retryBaseDelayMs: 0);
        $client = $this->makeClient($http, $config);

        $response = $client->checkSpam(new CheckSpamRequest('x'));

        self::assertSame(1.0, $response->getSpamScore());
    }

    public function test_optional_fields_omitted_from_payload(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['success' => true, 'data' => []]);
        $client = $this->makeClient($http);

        $client->checkSpam(new CheckSpamRequest('only content'));

        $body = json_decode($http->lastCall()['body'], true);
        self::assertSame(['content' => 'only content', 'source' => 'generic'], $body);
    }
}
