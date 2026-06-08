<?php

declare(strict_types=1);

namespace ElyonPay;

/**
 * Canonical mapping of ElyonPay transaction states to three business outcomes:
 * PAID, FAILED or PENDING. This is the single source of truth for status
 * handling across every plugin built on this SDK.
 *
 * Canonical list (docs.elyonpay.com, "États des Transactions"):
 *   CREATED, PENDING, WAITING_FOR_PAYMENT, ACCEPTED, REJECTED, DELIVERED,
 *   CANCELLED, DECLINED.
 *
 * For money-handling robustness we also recognise the SUCCESSFUL/FAILED/EXPIRED
 * vocabulary as a defensive belt-and-suspenders: these mappings are
 * unambiguous and protect against any future API/doc drift. Unknown states are
 * always treated as PENDING — the SDK never auto-fulfils on uncertainty.
 */
final class TransactionStatus
{
    public const PAID    = 'paid';
    public const FAILED  = 'failed';
    public const PENDING = 'pending';

    /** States that mean the money is secured / the order can be fulfilled. */
    private const PAID_STATES = [
        'ACCEPTED',
        'DELIVERED',
        'SUCCESSFUL', // alternate vocabulary seen in the docs example
    ];

    /** States that mean the payment will not complete. */
    private const FAILED_STATES = [
        'REJECTED',
        'DECLINED',
        'CANCELLED',
        'FAILED',   // alternate vocabulary
        'EXPIRED',  // alternate vocabulary
    ];

    /** States that mean "still in progress, check again later". */
    private const PENDING_STATES = [
        'CREATED',
        'PENDING',
        'WAITING_FOR_PAYMENT',
    ];

    /**
     * Reduce a raw ElyonPay state to PAID | FAILED | PENDING.
     * Unknown states are treated as PENDING (never auto-fulfil on uncertainty).
     */
    public static function normalize(?string $rawState): string
    {
        $state = strtoupper(trim((string) $rawState));

        if (\in_array($state, self::PAID_STATES, true)) {
            return self::PAID;
        }
        if (\in_array($state, self::FAILED_STATES, true)) {
            return self::FAILED;
        }

        return self::PENDING;
    }

    public static function isPaid(?string $rawState): bool
    {
        return self::normalize($rawState) === self::PAID;
    }

    public static function isFailed(?string $rawState): bool
    {
        return self::normalize($rawState) === self::FAILED;
    }

    public static function isPending(?string $rawState): bool
    {
        return self::normalize($rawState) === self::PENDING;
    }
}
