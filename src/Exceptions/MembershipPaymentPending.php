<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The player's Stripe subscription still lives but its last invoice is unpaid (past_due/unpaid/incomplete) -
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
