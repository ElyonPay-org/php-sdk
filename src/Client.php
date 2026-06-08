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
 * Usage:
 *   $client = new \ElyonPay\Client([
 *       'username'    => 'merchant@example.com',
 *       'password'    => '***',
 *       'role'        => 'ROLE_MERCHANT_ADMIN',
 *       'environment' => \ElyonPay\Environment::SANDBOX,
 *   ]);
 *
 *   $link = $client->createPaymentLink([
 *       'amount'    => 15000,
 *       'user_lang' => 'fr',
 *       'msisdn'    => '+237600000001',
 *       'success'   => 'https://yoursite.com/payment/done',
 *       'error'     => 'https://yoursite.com/payment/error',
 *   ]);
 *   // -> redirect the customer to $link['url']
 *
 *   $tx = $client->getTransaction($uuid);
 *   if (\ElyonPay\TransactionStatus::isPaid($tx['state'] ?? null)) { ... }
 *
 * SECURITY NOTE: ElyonPay currently authenticates with the merchant account
 * email + password rather than a revocable API key. Store these credentials
 * encrypted, never log them, and never expose them client-side.
 */
final class Client
{
    private string $username;
    private string $password;
    private string $role;
    private string $environment;
    private string $baseUrl;
    private HttpClientInterface $http;

    private ?string $token = null;

    /**
     * @param array{
     *     username:string,
     *     password:string,
     *     role?:string,
     *     environment?:string,
     *     token?:string
     * } $config
     */
    public function __construct(array $config, ?HttpClientInterface $http = null)
    {
        if (empty($config['username']) || empty($config['password'])) {
            throw new \InvalidArgumentException('Both "username" and "password" are required.');
        }

        $this->username    = (string) $config['username'];
        $this->password    = (string) $config['password'];
        $this->role        = (string) ($config['role'] ?? 'ROLE_MERCHANT_ADMIN');
        $this->environment = (string) ($config['environment'] ?? Environment::SANDBOX);
        $this->baseUrl     = Environment::baseUrl($this->environment);
        $this->http        = $http ?? new CurlHttpClient();

        // Allow a pre-obtained token to be injected (e.g. cached by the host plugin).
        if (!empty($config['token'])) {
            $this->token = (string) $config['token'];
        }
    }

    /**
     * Authenticate and return a JWT. The token is cached on the instance and
     * reused for subsequent calls. Tokens expire server-side, so callers that
     * keep a long-lived Client should be ready for a 401 (handled transparently
     * by the request layer below).
     */
    public function login(): string
    {
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

    /** Return the current token, logging in if necessary. */
    public function getToken(): string
    {
        if ($this->token === null) {
            $this->login();
        }

        return (string) $this->token;
    }

    /**
     * Create a payment link (POST /request-to-pay/payment/link).
     *
     * @param array{amount:int|float, user_lang:string, msisdn:string, success:string, error:string} $payload
     *
     * @return array{url:string}
     */
    public function createPaymentLink(array $payload): array
    {
        foreach (['amount', 'user_lang', 'msisdn', 'success', 'error'] as $required) {
            if (!isset($payload[$required]) || $payload[$required] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required field "%s".', $required));
            }
        }

        $data = $this->authedRequest('POST', '/request-to-pay/payment/link', $payload, $payload['user_lang']);

        if (empty($data['url'])) {
            throw new ApiException('ElyonPay did not return a payment URL.');
        }

        return ['url' => (string) $data['url']];
    }

    /**
     * Fetch a transaction by its identifier / UUID (GET /transactions/{id}).
     * Use this server-side to obtain the DEFINITIVE status — never trust the
     * success/error redirect alone.
     *
     * @return array<string,mixed> The raw transaction object (incl. "state")
     */
    public function getTransaction(string $id): array
    {
        return $this->authedRequest('GET', '/transactions/' . rawurlencode($id));
    }

    /**
     * List transactions (GET /transactions).
     *
     * @return array<string,mixed>
     */
    public function listTransactions(): array
    {
        return $this->authedRequest('GET', '/transactions');
    }

    /**
     * Perform an authenticated request, retrying once after a fresh login if
     * the token has expired (HTTP 401).
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function authedRequest(string $method, string $path, ?array $body = null, ?string $lang = null): array
    {
        $attempt = static function (string $token) use ($method, $path, $body, $lang): array {
            $headers = [
                'Authorization' => 'Bearer ' . $token,
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

        [$headers, $encoded] = $attempt($this->getToken());
        $response = $this->http->request($method, $this->baseUrl . $path, $headers, $encoded);

        // Token expired -> log in again once and retry.
        if ($response['status'] === 401) {
            $this->token = null;
            [$headers, $encoded] = $attempt($this->getToken());
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

    /** @param array<string,mixed> $data */
    private function throwApiError(array $data, int $status): void
    {
        $code    = null;
        $message = 'ElyonPay API error (HTTP ' . $status . ').';

        if (isset($data['error']) && \is_array($data['error'])) {
            $code    = isset($data['error']['code']) ? (string) $data['error']['code'] : null;
            $message = isset($data['error']['message']) ? (string) $data['error']['message'] : $message;
        }

        if ($status === 401) {
            throw new AuthenticationException($message);
        }

        throw new ApiException($message, $code, $status);
    }
}
