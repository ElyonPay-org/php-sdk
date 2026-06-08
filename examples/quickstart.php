<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\TransactionStatus;

// 1. Configure the client (sandbox).
$client = new Client([
    'username'    => 'demo+23733@elyonpay.com',
    'password'    => 'CompteDemo1',
    'role'        => 'ROLE_MERCHANT_ADMIN',
    'environment' => Environment::SANDBOX,
]);

// 2. Create a payment link and redirect the customer to it.
$link = $client->createPaymentLink([
    'amount'    => 15000,
    'user_lang' => 'fr',
    'msisdn'    => '+237600000001', // sandbox number that always succeeds
    'success'   => 'https://yoursite.com/payment/done',
    'error'     => 'https://yoursite.com/payment/error',
]);

echo "Redirect the customer to: {$link['url']}\n";

// 3. Later, on your success/error return URL, verify the DEFINITIVE status
//    server-side. Replace with the real transaction id/uuid from your context.
$uuid = 'CI032667e43abf11';
$tx   = $client->getTransaction($uuid);

$state = $tx['state'] ?? null;
if (TransactionStatus::isPaid($state)) {
    echo "Payment confirmed — fulfil the order.\n";
} elseif (TransactionStatus::isFailed($state)) {
    echo "Payment failed — show an error.\n";
} else {
    echo "Still pending — check again shortly.\n";
}
