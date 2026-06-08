<?php

declare(strict_types=1);

namespace ElyonPay\Http;

use ElyonPay\Exception\ApiException;

/**
 * Default HTTP client based on the cURL extension. No external dependencies,
 * which avoids version conflicts when embedded inside a CMS plugin.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('The PHP cURL extension is required by the ElyonPay SDK.');
        }
        $this->timeout = $timeout;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init();

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new ApiException(sprintf('HTTP transport error (%d): %s', $errno, $error));
        }

        return [
            'status' => $status,
            'body'   => \is_string($response) ? $response : '',
        ];
    }
}
