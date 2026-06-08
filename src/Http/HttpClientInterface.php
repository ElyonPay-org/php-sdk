<?php

declare(strict_types=1);

namespace ElyonPay\Http;

/**
 * Minimal HTTP client contract.
 *
 * The SDK ships with a zero-dependency cURL implementation, but host
 * environments (WordPress, PrestaShop, Joomla) can inject their own client
 * built on top of the platform's native HTTP stack if they prefer.
 */
interface HttpClientInterface
{
    /**
     * @param string                $method  HTTP verb (GET, POST, ...)
     * @param string                $url     Absolute URL
     * @param array<string,string>  $headers Header name => value
     * @param string|null           $body    Raw request body (already JSON-encoded)
     *
     * @return array{status:int, body:string} HTTP status code and raw response body
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
