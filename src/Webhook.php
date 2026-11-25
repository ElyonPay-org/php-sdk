<?php

declare(strict_types=1);

namespace ElyonPay;

/**
 * Reads an ElyonPay webhook notification (docs.elyonpay.com, "Webhooks").
 *
 * ElyonPay POSTs a JSON notification to the "webhook" URL given when the payment was created,
 * each time the transaction reaches PENDING (paid, awaiting delivery), DELIVERED, DECLINED or
 * CANCELLED. Your endpoint must answer 2xx within 10 seconds; otherwise the notification is
 * retried (3 s, 30 s, 60 s, 90 s) and abandoned after the fifth attempt.
 *
 * The notification is NOT SIGNED. Treat it as a signal that says "look at this transaction",
 * never as the status itself:
 *   - only act on a transaction uuid YOU created and stored for that order;
 *   - read the definitive state with Client::getTransaction($uuid) and map it with
 *     TransactionStatus — do not branch on the event name nor on data.state;
 *   - notifications may arrive late, twice, or out of order (dedupe on delivery_id if you must).
 *
 * Usage:
 *   $n  = \ElyonPay\Webhook::parse(file_get_contents('php://input'), $_SERVER);
 *   if (!in_array($n['transaction'], $uuidsStoredForThisOrder, true)) { http_response_code(200); exit; }
 *   $tx = $client->getTransaction($n['transaction']);
 *   if (\ElyonPay\TransactionStatus::isPaid($tx['state'] ?? null)) { ... }
 */
final class Webhook
{
    /** Paid, awaiting delivery. Despite its name, this is a PAID transaction. */
    public const EVENT_PENDING = 'transaction.pending';
    /** The name transaction.pending is being renamed to. Same meaning: paid. */
    public const EVENT_WAITING_FOR_DELIVERY = 'transaction.waiting_for_delivery';
    public const EVENT_DELIVERED = 'transaction.delivered';
    public const EVENT_DECLINED  = 'transaction.declined';
    public const EVENT_CANCELLED = 'transaction.cancelled';

    /**
     * Parse a raw notification.
     *
     * @param string               $body    The raw request body
     * @param array<string,mixed>  $headers Request headers, in any common shape: ['X-Mip-Event' => ...],
     *                                      lower-cased names, or $_SERVER (HTTP_X_MIP_EVENT)
     *
     * @return array{
     *     event: ?string,
     *     delivery_id: ?string,
     *     attempt: ?int,
     *     transaction: string,
     *     state: ?string,
     *     external_id: ?string,
     *     data: array<string,mixed>
     * }
     *
     * @throws \InvalidArgumentException when the body is not an ElyonPay transaction notification
     */
    public static function parse(string $body, array $headers = []): array
    {
        $payload = json_decode($body, true);
        if (!\is_array($payload) || !isset($payload['data']) || !\is_array($payload['data'])) {
            throw new \InvalidArgumentException('Not an ElyonPay notification: expected a JSON object with "data".');
        }

        $data        = $payload['data'];
        $transaction = isset($data['transaction']) && \is_string($data['transaction']) ? trim($data['transaction']) : '';
        if ($transaction === '') {
            throw new \InvalidArgumentException('Not an ElyonPay notification: "data.transaction" is missing.');
        }

        $normalized = self::normalizeHeaders($headers);
        $event      = $normalized['x-mip-event'] ?? (isset($payload['event']) && \is_string($payload['event']) ? $payload['event'] : null);
        $attempt    = $normalized['x-mip-attempt'] ?? null;

        return [
            'event'       => $event,
            'delivery_id' => $normalized['x-mip-delivery'] ?? null,
            'attempt'     => $attempt !== null && preg_match('/^\d+$/', $attempt) === 1 ? (int) $attempt : null,
            'transaction' => $transaction,
            'state'       => isset($data['state']) && \is_string($data['state']) ? $data['state'] : null,
            'external_id' => isset($data['external_id']) && \is_string($data['external_id']) ? $data['external_id'] : null,
            'data'        => $data,
        ];
    }

    /**
     * @param array<string,mixed> $headers
     *
     * @return array<string,string> lower-case dash-separated names => first value
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }
            $key = strtolower(str_replace('_', '-', $name));
            if (strpos($key, 'http-') === 0) {
                $key = substr($key, 5);
            }
            if (\is_array($value)) {
                $value = reset($value);
            }
            if (\is_scalar($value)) {
                $out[$key] = trim((string) $value);
            }
        }

        return $out;
    }
}
