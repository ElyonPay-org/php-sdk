<?php

declare(strict_types=1);

namespace ElyonPay;

use ElyonPay\Exception\ApiException;
use ElyonPay\Exception\AuthenticationException;
use ElyonPay\Http\CurlHttpClient;
use ElyonPay\Http\HttpClientInterface;

/**
 * Main entry point for the ElyonPay API.
 *
 * Usage (recommended: the merchant API key, from the dashboard):
 *   $client = new \ElyonPay\Client([
 *       'api_key'     => 'pk_live_egt_...',
 *       'environment' => \ElyonPay\Environment::SANDBOX,
 *   ]);
 *
 *   $link = $client->createPaymentLink([
 *       'amount'      => 15000,
 *       'user_lang'   => 'fr',
 *       'msisdn'      => '+237600000001',
 *       'success'     => 'https://yoursite.com/payment/done',
 *       'error'       => 'https://yoursite.com/payment/error',
 *       'webhook'     => 'https://yoursite.com/webhooks/elyonpay',
 *       'external_id' => 'CMD-2026-0042',
 *   ]);
 *   // -> redirect the customer to $link['url']
 *
 *   $tx = $client->getTransaction($uuid);
 *   if (\ElyonPay\TransactionStatus::isPaid($tx['state'] ?? null)) { ... }
 *
 * Authentication: an API key (Authorization: Bearer pk_live_egt_...) reaches the payment and
 * transaction endpoints only, and can be regenerated from the dashboard if it leaks. The merchant
 * email + password (JWT obtained from /login) is still accepted for backward compatibility, but it
 * opens the whole account: prefer the key. Either way, keep the secret server-side and never log it.
 */
final class Client
{
    private ?string $apiKey = null;
    private string $username = '';
    private string $password = '';
    private string $role;
    private string $environment;
    private string $baseUrl;
    private HttpClientInterface $http;

    private ?string $token = null;

    /**
     * @param array{
     *     api_key?:string,
     *     username?:string,
     *     password?:string,
     *     role?:string,
     *     environment?:string,
     *     token?:string,
     *     timeout?:int
     * } $config Either "api_key", or "username" + "password" (legacy JWT login).
     */
    public function __construct(array $config, ?HttpClientInterface $http = null)
    {
        $apiKey = isset($config['api_key']) ? trim((string) $config['api_key']) : '';

        if ($apiKey !== '') {
            $this->apiKey = $apiKey;
        } elseif (empty($config['username']) || empty($config['password'])) {
            throw new \InvalidArgumentException('Provide an "api_key", or both "username" and "password".');
        } else {
            $this->username = (string) $config['username'];
            $this->password = (string) $config['password'];
        }

        $this->role        = (string) ($config['role'] ?? 'ROLE_MERCHANT_ADMIN');
        $this->environment = (string) ($config['environment'] ?? Environment::SANDBOX);
        $this->baseUrl     = Environment::baseUrl($this->environment);
        $this->http        = $http ?? new CurlHttpClient((int) ($config['timeout'] ?? 30));

        // Allow a pre-obtained JWT to be injected (e.g. cached by the host plugin). Ignored with an
        // API key, which needs no token.
        if ($this->apiKey === null && !empty($config['token'])) {
            $this->token = (string) $config['token'];
        }
    }

    /** True when the client authenticates with an API key rather than a JWT. */
    public function usesApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * Authenticate with the merchant email + password and return a JWT (legacy mode).
     *
     * @throws \LogicException when the client is configured with an API key, which needs no login
     */
    public function login(): string
    {
        if ($this->apiKey !== null) {
            throw new \LogicException('An API key needs no login: it is sent as is on every request.');
        }

        $response = $this->http->request(
            'POST',
            $this->baseUrl . '/login',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode([
                'username' => $this->username,
                'password' => $this->password,
                'role'     => $this->role,
            ])
        );

        $data = $this->decode($response['body']);

        if ($response['status'] !== 200 || empty($data['token'])) {
            throw new AuthenticationException('ElyonPay login failed. Check the merchant credentials and role.');
        }

        $this->token = (string) $data['token'];

        return $this->token;
    }

    /**
     * Return the JWT, logging in if necessary (legacy mode).
     *
     * @throws \LogicException when the client is configured with an API key: there is no token to
     *                         cache, and returning the key here would invite storing it elsewhere
     */
    public function getToken(): string
    {
        if ($this->apiKey !== null) {
            throw new \LogicException('No JWT with an API key.');
        }
        if ($this->token === null) {
            $this->login();
        }

        return (string) $this->token;
    }

    /**
     * Create a payment link (POST /request-to-pay/payment/link).
     *
     * Required: amount, user_lang, success, error, and the payer's number as msisdn or
     * customer.phone. Every other documented field is passed through as is: webhook,
     * external_id (unique per merchant), customer { account_ref, name, email, phone, locale },
     * onbehalfof, fees.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{url:string}
     */
    public function createPaymentLink(array $payload): array
    {
        foreach (['amount', 'user_lang', 'success', 'error'] as $required) {
            if (!isset($payload[$required]) || $payload[$required] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required field "%s".', $required));
            }
        }
        $phone = $payload['msisdn'] ?? ($payload['customer']['phone'] ?? null);
        if ($phone === null || $phone === '') {
            throw new \InvalidArgumentException('Missing required field "msisdn" (or "customer.phone").');
        }

        $data = $this->authedRequest('POST', '/request-to-pay/payment/link', $payload, (string) $payload['user_lang']);

        if (empty($data['url'])) {
            throw new ApiException('ElyonPay did not return a payment URL.');
        }

        return ['url' => (string) $data['url']];
    }

    /**
     * Fetch a transaction by its uuid (GET /transactions/{uuid}).
     * Use this server-side to obtain the DEFINITIVE status — never trust the success/error
     * redirect, nor the content of a webhook, alone.
     *
     * A transaction that does not exist or belongs to another merchant answers HTTP 400.
     *
     * @return array<string,mixed> The raw transaction object (incl. "state", "uuid", "amount", "externalId")
     */
    public function getTransaction(string $id): array
    {
        return $this->authedRequest('GET', '/transactions/' . rawurlencode($id));
    }

    /**
     * List transactions (GET /transactions).
     *
     * @deprecated The API serves no GET /transactions list to integrations today (the merchant
     *             history lives in the dashboard). Kept so existing callers do not break.
     *
     * @return array<string,mixed>
     */
    public function listTransactions(): array
    {
        return $this->authedRequest('GET', '/transactions');
    }

    /**
     * Perform an authenticated request. With a JWT, retry once after a fresh login if the token
     * has expired (HTTP 401). With an API key, a 401 means the key is wrong or revoked: no retry.
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function authedRequest(string $method, string $path, ?array $body = null, ?string $lang = null): array
    {
        $build = static function (string $bearer) use ($body, $lang): array {
            $headers = [
                'Authorization' => 'Bearer ' . $bearer,
                'Accept'        => 'application/json',
            ];
            if ($lang !== null) {
                $headers['language'] = $lang;
            }
            $encoded = null;
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
                $encoded = (string) json_encode($body);
            }

            return [$headers, $encoded];
        };

        [$headers, $encoded] = $build($this->apiKey ?? $this->getToken());
        $response = $this->http->request($method, $this->baseUrl . $path, $headers, $encoded);

        // Token expired -> log in again once and retry (JWT only).
        if ($response['status'] === 401 && $this->apiKey === null) {
            $this->token = null;
            [$headers, $encoded] = $build($this->getToken());
            $response = $this->http->request($method, $this->baseUrl . $path, $headers, $encoded);
        }

        $data = $this->decode($response['body']);

        if ($response['status'] >= 400) {
            $this->throwApiError($data, $response['status']);
        }

        // ElyonPay sometimes wraps payloads in {success, data, meta}; unwrap if so.
        if (isset($data['data']) && \is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The API answers errors in two shapes: {"error": {"code", "message"}}, and the more common
     * {"message": "..."} where message may also be a list (or map) of strings.
     *
     * @param array<string,mixed> $data
     */
    private function throwApiError(array $data, int $status): void
    {
        $code    = null;
        $message = 'ElyonPay API error (HTTP ' . $status . ').';

        if (isset($data['error']) && \is_array($data['error'])) {
            $code    = isset($data['error']['code']) ? (string) $data['error']['code'] : null;
            $message = isset($data['error']['message']) ? (string) $data['error']['message'] : $message;
        } elseif (isset($data['message'])) {
            $text = $this->flatten($data['message']);
            if ($text !== '') {
                $message = $text;
            }
            if (isset($data['code']) && \is_string($data['code'])) {
                $code = $data['code'];
            }
        }

        if ($status === 401) {
            throw new AuthenticationException($message);
        }

        throw new ApiException($message, $code, $status);
    }

    /** @param mixed $value */
    private function flatten($value): string
    {
        if (\is_scalar($value)) {
            return trim((string) $value);
        }
        if (!\is_array($value)) {
            return '';
        }
        $parts = [];
        array_walk_recursive($value, static function ($leaf) use (&$parts): void {
            if (\is_scalar($leaf) && trim((string) $leaf) !== '') {
                $parts[] = trim((string) $leaf);
            }
        });

        return implode(' ', $parts);
    }
}
