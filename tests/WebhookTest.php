<?php

declare(strict_types=1);

namespace ElyonPay\Tests;

use ElyonPay\TransactionStatus;
use ElyonPay\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** The payload documented on docs.elyonpay.com (section Webhooks). */
    private const BODY = '{
      "event": "transaction.delivered",
      "created_at": "2026-07-08T18:45:12+02:00",
      "data": {
        "transaction": "CI032667e43abf11",
        "state": "DELIVERED",
        "amount": 15000,
        "currency": "XAF",
        "operation_code": "OP-2026-000123",
        "merchant_id": 42,
        "external_id": "CMD-2026-0042",
        "customer": {"account_ref": "CLT-1001", "name": "Jean Mballa", "email": "jean@example.com", "phone": "+237670000001"},
        "creation_date": "2026-07-08T18:40:03+02:00",
        "validation_date": "2026-07-08T18:44:57+02:00",
        "delivery_date": "2026-07-08T18:45:10+02:00"
      }
    }';

    public function testParsesTheDocumentedPayloadWithServerHeaders(): void
    {
        $n = Webhook::parse(self::BODY, [
            'HTTP_X_MIP_EVENT'    => 'transaction.delivered',
            'HTTP_X_MIP_DELIVERY' => '981',
            'HTTP_X_MIP_ATTEMPT'  => '2',
            'REQUEST_METHOD'      => 'POST',
        ]);

        self::assertSame('transaction.delivered', $n['event']);
        self::assertSame('981', $n['delivery_id']);
        self::assertSame(2, $n['attempt']);
        self::assertSame('CI032667e43abf11', $n['transaction']);
        self::assertSame('DELIVERED', $n['state']);
        self::assertSame('CMD-2026-0042', $n['external_id']);
        self::assertSame(15000, $n['data']['amount']);
    }

    public function testAcceptsCanonicalAndListShapedHeaders(): void
    {
        $n = Webhook::parse(self::BODY, ['X-Mip-Event' => ['transaction.pending'], 'x-mip-delivery' => '7']);

        self::assertSame('transaction.pending', $n['event']);
        self::assertSame('7', $n['delivery_id']);
        self::assertNull($n['attempt']);
    }

    public function testFallsBackToTheEventInTheBody(): void
    {
        self::assertSame('transaction.delivered', Webhook::parse(self::BODY)['event']);
    }

    /** @return iterable<string, array{string}> */
    public static function notNotifications(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['hello'];
        yield 'no data' => ['{"event":"transaction.delivered"}'];
        yield 'no transaction' => ['{"data":{"state":"DELIVERED"}}'];
        yield 'transaction not a string' => ['{"data":{"transaction":["x"]}}'];
    }

    /** @dataProvider notNotifications */
    public function testRefusesWhatIsNotANotification(string $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhook::parse($body);
    }

    public function testEveryNotifiedStateHasAnUnambiguousOutcome(): void
    {
        // transaction.pending (and its future name) is a PAID transaction, awaiting delivery.
        self::assertTrue(TransactionStatus::isPaid('PENDING'));
        self::assertTrue(TransactionStatus::isPaid('WAITING_FOR_DELIVERY'));
        self::assertTrue(TransactionStatus::isPaid('DELIVERED'));
        self::assertTrue(TransactionStatus::isFailed('DECLINED'));
        self::assertTrue(TransactionStatus::isFailed('CANCELLED'));
        self::assertTrue(TransactionStatus::isPending('CREATED'));
        self::assertTrue(TransactionStatus::isPending('WAITING_FOR_PAYMENT'));
    }
}
