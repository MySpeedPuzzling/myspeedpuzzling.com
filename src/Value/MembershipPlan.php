<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two regular Stripe plans a member may switch between (docs/features/stripe.md "Switching plans").
 * The value is the Stripe price lookup key. Every other price - the early adopter `*_promo` ones, the
 * one-time year - is no plan here on purpose: tryFrom() gives null and nothing offers a switch.
 */
enum MembershipPlan: string
{
    case Monthly = 'puzzlership_monthly';
    case Yearly = 'puzzlership_yearly';

    public static function fromLookupKey(null|string $lookupKey): null|self
    {
        if ($lookupKey === null) {
            return null;
        }

        return self::tryFrom($lookupKey);
    }

    public function switchTarget(): self
    {
        return match ($this) {
            self::Monthly => self::Yearly,
            self::Yearly => self::Monthly,
        };
    }
}
