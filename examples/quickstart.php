<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\TransactionStatus;

// 1. Configure the client with your API key (dashboard -> API key). Sandbox keys work on
//    api.elyonpay.net, production keys on api.elyonpay.org.
$client = new Client([
    'api_key'     => getenv('ELYONPAY_API_KEY'),
    'environment' => Environment::SANDBOX,
]);

// 2. Create a payment link and redirect the customer to it.
$link = $client->createPaymentLink([
    'amount'      => 15000,
    'user_lang'   => 'fr',
    'msisdn'      => '+237600000001', // sandbox number that always succeeds
    'success'     => 'https://yoursite.com/payment/done',
    'error'       => 'https://yoursite.com/payment/error',
    'webhook'     => 'https://yoursite.com/webhooks/elyonpay', // see examples/webhook.php
    'external_id' => 'CMD-2026-0042-1',                        // unique per merchant
    'customer'    => ['name' => 'Jean Mballa', 'email' => 'jean@example.com'],
]);

echo "Redirect the customer to: {$link['url']}\n";

// The transaction uuid is the last path segment of the link: keep it with your order.
preg_match('#/t/([A-Za-z0-9]+)#', (string) parse_url($link['url'], PHP_URL_PATH), $m);
$uuid = $m[1] ?? '';

// 3. Later — on your success/error return URL and on each webhook — verify the
//    DEFINITIVE status server-side.
$tx    = $client->getTransaction($uuid);
$state = $tx['state'] ?? null;
if (TransactionStatus::isPaid($state)) {
    echo "Payment confirmed — fulfil the order.\n";
} elseif (TransactionStatus::isFailed($state)) {
    echo "Payment failed — show an error.\n";
} else {
    echo "Still pending — check again shortly.\n";
}
