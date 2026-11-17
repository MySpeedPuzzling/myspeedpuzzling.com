<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The player's Stripe subscription is in the retry period of its current billing period (past_due) -
 * paying that invoice keeps the subscription and its price, a new checkout would start a second one.
 */
final class MembershipPaymentPending extends \Exception
{
    public function __construct(
        readonly public string $hostedInvoiceUrl,
    ) {
        parent::__construct('The membership subscription waits for the payment of its open invoice');
    }
}
