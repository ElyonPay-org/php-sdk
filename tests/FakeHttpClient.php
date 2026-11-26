<?php

declare(strict_types=1);

namespace ElyonPay\Tests;

use ElyonPay\Http\HttpClientInterface;

/** Records every request and answers from a queue of canned responses. */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string}> */
    public array $requests = [];

    /** @var list<array{status:int, body:string}> */
    private array $responses;

    /** @param list<array{status:int, body:string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->responses === []) {
            throw new \LogicException('Unexpected request: ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }
}
