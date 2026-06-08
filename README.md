# ElyonPay PHP SDK

Official PHP SDK for the [ElyonPay](https://elyonpay.com) payment API — accept
**Mobile Money** (MTN, Orange, Wave, Moov, Airtel) and **bank cards** (Visa,
Mastercard) in a few lines of code.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-777bb4.svg)](composer.json)

> **Zero runtime dependencies.** The SDK talks to the API through the native
> cURL extension, so it can be embedded inside WooCommerce, PrestaShop or
> Joomla without pulling in conflicting libraries.

## Installation

```bash
composer require elyonpay/php-sdk
```

## Quick start

```php
use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\TransactionStatus;

$client = new Client([
    'username'    => 'merchant@example.com',
    'password'    => '********',
    'role'        => 'ROLE_MERCHANT_ADMIN',
    'environment' => Environment::SANDBOX, // or Environment::PRODUCTION
]);

// 1. Create a payment link, then redirect the customer to it.
$link = $client->createPaymentLink([
    'amount'    => 15000,
    'user_lang' => 'fr',
    'msisdn'    => '+237600000001',
    'success'   => 'https://yoursite.com/payment/done',
    'error'     => 'https://yoursite.com/payment/error',
]);
header('Location: ' . $link['url']);

// 2. On your return URL, verify the DEFINITIVE status server-side.
$tx = $client->getTransaction($uuid);
if (TransactionStatus::isPaid($tx['state'] ?? null)) {
    // fulfil the order
}
```

See [`examples/quickstart.php`](examples/quickstart.php) for the full flow.

## How it works (pull model)

ElyonPay uses a payment link plus a server-side status check — **there is no
webhook to expose**.

1. `login()` — authenticate and obtain a JWT (done automatically on first call).
2. `createPaymentLink()` — `POST /request-to-pay/payment/link`, returns a URL.
3. Redirect the customer; they pay on ElyonPay's page (USSD or 3D Secure).
4. The customer is redirected back to your `success` / `error` URL.
5. `getTransaction()` — `GET /transactions/{uuid}`, the **definitive** status.

> ⚠️ Never mark an order as paid based on the redirect URL alone. A customer
> can change it. Always re-check the status server-side in step 5.

## API surface

| Method | Description |
|--------|-------------|
| `login(): string` | Authenticate, return and cache a JWT. |
| `getToken(): string` | Current token, logging in on demand. |
| `createPaymentLink(array $payload): array` | Create a payment link. |
| `getTransaction(string $id): array` | Fetch one transaction. |
| `listTransactions(): array` | List transactions. |

Tokens are cached on the instance and renewed automatically on a `401`.

## Status handling

ElyonPay's docs currently expose several status vocabularies. `TransactionStatus`
normalises any raw state into one of three outcomes and is the **single source
of truth** for every plugin built on this SDK:

```php
TransactionStatus::isPaid($state);    // ACCEPTED, DELIVERED, SUCCESSFUL
TransactionStatus::isFailed($state);  // REJECTED, DECLINED, CANCELLED, FAILED, EXPIRED
TransactionStatus::isPending($state); // CREATED, PENDING, WAITING_FOR_PAYMENT, unknown
```

Unknown states are treated as **pending** — the SDK never auto-fulfils on
uncertainty.

## Environments

| Environment | Base URL |
|-------------|----------|
| Sandbox     | `https://api.elyonpay.net/api` |
| Production  | `https://api.elyonpay.org/api` |

Sandbox test numbers and cards are listed in the [API docs](https://docs.elyonpay.com).

## Security

ElyonPay currently authenticates with the merchant account **email + password**
(not a revocable API key). Therefore:

- Store credentials encrypted; never commit them, never log them.
- Make all calls **server-side** — never expose credentials or the JWT in the
  browser.

## Error handling

```php
use ElyonPay\Exception\AuthenticationException;
use ElyonPay\Exception\ApiException;

try {
    $client->createPaymentLink([...]);
} catch (AuthenticationException $e) {
    // bad credentials or expired token
} catch (ApiException $e) {
    $e->getErrorCode();  // e.g. INSUFFICIENT_FUNDS
    $e->getHttpStatus(); // e.g. 402
}
```

## License

[MIT](LICENSE) — © 2026 ElyonPay Global Technologies SAS.
