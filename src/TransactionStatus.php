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
 *   CANCELLED, DECLINED, plus PREPARATION and DELIVERING between PENDING and
 *   DELIVERED when the merchant ships the order.
 *
 * For money-handling robustness we also recognise the SUCCESSFUL/FAILED/EXPIRED
 * vocabulary as a defensive belt-and-suspenders: these mappings are
 * unambiguous and protect against any future API/doc drift. Unknown states are
 * always treated as PENDING — the SDK never auto-fulfils on uncertainty.
 *
 * One state does not mean what its name suggests: PENDING. On the ElyonPay side it
 * asserts that the money has been COLLECTED — the validation date is stamped and the
 * merchant is compensated at that moment — and what it still awaits is the delivery,
 * not the payment. The word comes from a different vocabulary: with most providers,
 * and inside ElyonPay's own payment service, "pending" means "in flight, nothing
 * decided". Here it means the opposite.
 *
 * It is therefore classified as PAID. Treating it as PENDING was not a harmless
 * mislabel: an order paid by a customer stayed on-hold and was never fulfilled. It
 * went unnoticed because a sale with no delivery moves on to DELIVERED within the
 * same transition, so PENDING is only ever observed on sales that DO await a
 * delivery — exactly the ones a merchant must ship.
 *
 * WAITING_FOR_DELIVERY is the same state under the name it is being renamed to.
 * Both are accepted so this SDK keeps working across that change, in either
 * direction, without a lockstep release.
 */
final class TransactionStatus
{
    public const PAID    = 'paid';
    public const FAILED  = 'failed';
    public const PENDING = 'pending';

    /** States that mean the money is secured / the order can be fulfilled. */
    private const PAID_STATES = [
        'ACCEPTED',
        // Paid, the order is being prepared or delivered (after PENDING, before DELIVERED).
        'PREPARATION',
        'DELIVERING',
        'DELIVERED',
        // Encaissé, en attente de livraison — voir l'explication en tête de classe.
        'PENDING',
        'WAITING_FOR_DELIVERY', // le même état, sous son nom à venir
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

    /**
     * States that mean "still in progress, check again later".
     *
     * Only kept for documentation: normalize() falls through to PENDING for anything it does
     * not recognise, so this list is never consulted. PENDING itself has moved to PAID_STATES.
     */
    private const PENDING_STATES = [
        'CREATED',
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
