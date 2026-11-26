<?php

declare(strict_types=1);

namespace ElyonPay\Tests;

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\Exception\ApiException;
use ElyonPay\Exception\AuthenticationException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const KEY = 'pk_live_egt_0123456789abcdef0123456789abcdef';

    public function testApiKeyIsSentAsBearerAndNeverLogsIn(): void
    {
        $http   = new FakeHttpClient([['status' => 201, 'body' => '{"url":"https://app.elyonpay.net/t/CM01abc?success=x&error=y"}']]);
        $client = new Client(['api_key' => self::KEY, 'environment' => Environment::SANDBOX], $http);

        $link = $client->createPaymentLink([
            'amount'      => 15000,
            'user_lang'   => 'fr',
            'msisdn'      => '+237600000001',
            'success'     => 'https://shop.test/ok',
            'error'       => 'https://shop.test/ko',
            'webhook'     => 'https://shop.test/hook',
            'external_id' => 'wc-42-ab12cd34',
            'customer'    => ['name' => 'Jean Mballa', 'email' => 'jean@example.com'],
        ]);

        self::assertSame('https://app.elyonpay.net/t/CM01abc?success=x&error=y', $link['url']);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.elyonpay.net/api/request-to-pay/payment/link', $request['url']);
        self::assertSame('Bearer ' . self::KEY, $request['headers']['Authorization']);
        self::assertSame('fr', $request['headers']['language']);

        $body = json_decode((string) $request['body'], true);
        self::assertSame('https://shop.test/hook', $body['webhook']);
        self::assertSame('wc-42-ab12cd34', $body['external_id']);
        self::assertSame('jean@example.com', $body['customer']['email']);
        self::assertTrue($client->usesApiKey());
    }

    public function testInvalidApiKeyThrowsWithoutRetry(): void
    {
        $http   = new FakeHttpClient([['status' => 401, 'body' => '{"message":"Invalid API key."}']]);
        $client = new Client(['api_key' => self::KEY], $http);

        try {
            $client->getTransaction('CM01abc');
            self::fail('Expected an AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertSame('Invalid API key.', $e->getMessage());
        }
        self::assertCount(1, $http->requests, 'an API key is never retried through /login');
    }

    public function testOutOfScopeEndpointSurfacesAs403(): void
    {
        $http   = new FakeHttpClient([['status' => 403, 'body' => '{"message":"This API key is not allowed to access this endpoint."}']]);
        $client = new Client(['api_key' => self::KEY], $http);

        try {
            $client->listTransactions();
            self::fail('Expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('This API key is not allowed to access this endpoint.', $e->getMessage());
        }
    }

    public function testListShapedErrorMessageIsReadable(): void
    {
        $http   = new FakeHttpClient([['status' => 409, 'body' => '{"message":["A transaction with external_id [wc-1] already exists for this merchant."]}']]);
        $client = new Client(['api_key' => self::KEY], $http);

        try {
            $client->createPaymentLink(['amount' => 1, 'user_lang' => 'fr', 'msisdn' => '+237600000001', 'success' => 'a', 'error' => 'b']);
            self::fail('Expected an ApiException');
        } catch (ApiException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertStringContainsString('external_id [wc-1] already exists', $e->getMessage());
        }
    }

    public function testCustomerPhoneStandsInForMsisdn(): void
    {
        $http   = new FakeHttpClient([['status' => 201, 'body' => '{"url":"https://app/t/X"}']]);
        $client = new Client(['api_key' => self::KEY], $http);

        $client->createPaymentLink(['amount' => 1, 'user_lang' => 'fr', 'customer' => ['phone' => '+237600000001'], 'success' => 'a', 'error' => 'b']);

        self::assertCount(1, $http->requests);
    }

    public function testPaymentLinkWithoutAnyPhoneIsRefusedLocally(): void
    {
        $client = new Client(['api_key' => self::KEY], new FakeHttpClient([]));

        $this->expectException(\InvalidArgumentException::class);
        $client->createPaymentLink(['amount' => 1, 'user_lang' => 'fr', 'success' => 'a', 'error' => 'b']);
    }

    public function testGetTokenRefusesWithApiKey(): void
    {
        $client = new Client(['api_key' => self::KEY], new FakeHttpClient([]));

        $this->expectException(\LogicException::class);
        $client->getToken();
    }

    public function testLegacyLoginStillWorksAndRetriesOnExpiredToken(): void
    {
        $http = new FakeHttpClient([
            ['status' => 401, 'body' => '{"message":"Expired JWT Token"}'],
            ['status' => 200, 'body' => '{"token":"fresh.jwt.token"}'],
            ['status' => 201, 'body' => '{"uuid":"CM01abc","state":"DELIVERED"}'],
        ]);
        $client = new Client(['username' => 'm@example.com', 'password' => 'secret', 'token' => 'stale.jwt.token', 'environment' => Environment::PRODUCTION], $http);

        $tx = $client->getTransaction('CM01abc');

        self::assertSame('DELIVERED', $tx['state']);
        self::assertSame('Bearer stale.jwt.token', $http->requests[0]['headers']['Authorization']);
        self::assertSame('https://api.elyonpay.org/api/login', $http->requests[1]['url']);
        self::assertSame('Bearer fresh.jwt.token', $http->requests[2]['headers']['Authorization']);
        self::assertFalse($client->usesApiKey());
    }

    public function testInjectedTokenIsIgnoredWithApiKey(): void
    {
        $http   = new FakeHttpClient([['status' => 201, 'body' => '{"uuid":"CM01abc","state":"CREATED"}']]);
        $client = new Client(['api_key' => self::KEY, 'token' => 'some.jwt'], $http);

        $client->getTransaction('CM01abc');

        self::assertSame('Bearer ' . self::KEY, $http->requests[0]['headers']['Authorization']);
    }

    public function testMissingCredentialsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(['api_key' => '   '], new FakeHttpClient([]));
    }
}
