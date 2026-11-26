<?php

declare(strict_types=1);

/*
 * Webhook receiver: the URL you pass as "webhook" when creating the payment.
 *
 * The notification is not signed. It only tells you WHICH transaction changed: read its state
 * with getTransaction(), and only for a uuid you stored yourself for that order.
 */

require __DIR__ . '/../vendor/autoload.php';

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\Exception\ApiException;
use ElyonPay\TransactionStatus;
use ElyonPay\Webhook;

try {
    $n = Webhook::parse((string) file_get_contents('php://input'), $_SERVER);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    exit;
}

// Your own lookup: the order that holds this transaction uuid (stored when the link was created).
$order = find_order_by_transaction_uuid($n['transaction']);
if ($order === null) {
    http_response_code(200); // not ours: acknowledge so it is not retried
    exit;
}

$client = new Client([
    'api_key'     => getenv('ELYONPAY_API_KEY'),
    'environment' => Environment::SANDBOX,
    'timeout'     => 8, // ElyonPay waits 10 s for the 2xx
]);

try {
    $tx = $client->getTransaction($n['transaction']);
} catch (ApiException $e) {
    // 400: unknown to this merchant -> nothing to do. Anything else is transient: ask for a retry.
    http_response_code($e->getHttpStatus() === 400 ? 200 : 503);
    exit;
}

$state = $tx['state'] ?? null;
if (TransactionStatus::isPaid($state)) {
    mark_order_paid($order, $n['transaction']); // must be idempotent: notifications can repeat
} elseif (TransactionStatus::isFailed($state)) {
    mark_order_failed($order);
}

http_response_code(200);
