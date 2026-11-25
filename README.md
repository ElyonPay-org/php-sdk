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
    'api_key'     => getenv('ELYONPAY_API_KEY'), // pk_live_egt_..., from your dashboard
    'environment' => Environment::SANDBOX,       // or Environment::PRODUCTION
]);

// 1. Create a payment link, then redirect the customer to it.
$link = $client->createPaymentLink([
    'amount'      => 15000,
    'user_lang'   => 'fr',
    'msisdn'      => '+237600000001',
    'success'     => 'https://yoursite.com/payment/done',
    'error'       => 'https://yoursite.com/payment/error',
    'webhook'     => 'https://yoursite.com/webhooks/elyonpay', // optional, see below
    'external_id' => 'CMD-2026-0042',                          // optional, unique per merchant
]);
header('Location: ' . $link['url']);

// 2. On your return URL (and on each webhook), read the DEFINITIVE status server-side.
$tx = $client->getTransaction($uuid);
if (TransactionStatus::isPaid($tx['state'] ?? null)) {
    // fulfil the order
}
```

See [`examples/quickstart.php`](examples/quickstart.php) and
[`examples/webhook.php`](examples/webhook.php) for the full flow.

## How it works

1. `createPaymentLink()` — `POST /request-to-pay/payment/link`, returns a URL.
2. Redirect the customer; they pay on ElyonPay's page (USSD or 3D Secure).
3. ElyonPay POSTs a notification to your `webhook` URL at each state change, and
   redirects the customer back to your `success` / `error` URL.
4. `getTransaction()` — `GET /transactions/{uuid}`, the **definitive** status.
   Call it on the return URL and on every webhook, and keep a periodic re-check as
   a safety net: a webhook is abandoned after 5 failed attempts (~3 minutes).

> ⚠️ Never mark an order as paid based on the redirect URL or the webhook body
> alone. Neither is signed. Always re-check the status server-side in step 4.

## Webhooks

The URL is given per transaction (`webhook` field). ElyonPay notifies
`transaction.pending` (paid, awaiting delivery — **a paid state despite its name**),
`transaction.delivered`, `transaction.declined` and `transaction.cancelled`, with
the headers `X-Mip-Event`, `X-Mip-Delivery` (unique per notification) and
`X-Mip-Attempt` (1 to 5). Answer 2xx within 10 seconds.

```php
use ElyonPay\Webhook;

$n = Webhook::parse(file_get_contents('php://input'), $_SERVER);
// $n = [event, delivery_id, attempt, transaction, state, external_id, data]

// The body is unsigned: act only on a uuid YOU stored for that order, and read its state.
if (in_array($n['transaction'], $uuidsStoredForThisOrder, true)) {
    $tx = $client->getTransaction($n['transaction']);
    // map $tx['state'] with TransactionStatus, not $n['state'] nor $n['event']
}
http_response_code(200);
```

## API surface

| Method | Description |
|--------|-------------|
| `createPaymentLink(array $payload): array` | Create a payment link (`webhook`, `external_id`, `customer`… passed through). |
| `getTransaction(string $uuid): array` | Fetch one transaction (definitive status). |
| `listTransactions(): array` | Deprecated: the API serves no `GET /transactions` list to integrations today. |
| `usesApiKey(): bool` | Whether the client authenticates with an API key. |
| `login(): string` / `getToken(): string` | Legacy JWT mode only. |
| `Webhook::parse(string $body, array $headers): array` | Read a webhook notification. |

With an API key there is no login: a `401` means the key is wrong or was
regenerated. In legacy mode the JWT is cached on the instance and renewed
automatically on a `401`.

## Status handling

`TransactionStatus` normalises any raw state into one of three outcomes and is
the **single source of truth** for every plugin built on this SDK:

```php
TransactionStatus::isPaid($state);    // ACCEPTED, DELIVERED, PENDING, WAITING_FOR_DELIVERY
TransactionStatus::isFailed($state);  // REJECTED, DECLINED, CANCELLED
TransactionStatus::isPending($state); // CREATED, WAITING_FOR_PAYMENT, unknown
```

`PENDING` means the money is collected and only the delivery is awaited; it is
being renamed `WAITING_FOR_DELIVERY`, and both are accepted. Unknown states are
treated as **pending** — the SDK never auto-fulfils on uncertainty.

## Environments

| Environment | Base URL |
|-------------|----------|
| Sandbox     | `https://api.elyonpay.net/api` |
| Production  | `https://api.elyonpay.org/api` |

Sandbox test numbers and cards are listed in the [API docs](https://docs.elyonpay.com).

## Authentication & security

Use the merchant **API key** (`Authorization: Bearer pk_live_egt_...`), shown —
and regenerable — in the ElyonPay dashboard. It only reaches the payment and
transaction endpoints; anything else answers `403`.

```php
new Client(['api_key' => 'pk_live_egt_...', 'environment' => Environment::PRODUCTION]);
```

The former email + password login (`username`, `password`, `role`) still works,
but it opens the whole merchant account: migrate to the key. Either way:

- never commit, log or expose the secret client-side;
- make every call **server-side**.

## Error handling

```php
use ElyonPay\Exception\AuthenticationException;
use ElyonPay\Exception\ApiException;

try {
    $client->createPaymentLink([...]);
} catch (AuthenticationException $e) {
    // wrong or regenerated API key (or bad legacy credentials)
} catch (ApiException $e) {
    $e->getMessage();    // the API's message
    $e->getHttpStatus(); // e.g. 409 when external_id was already used
}
```

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

[MIT](LICENSE) — © 2026 ElyonPay Global Technologies SAS.
