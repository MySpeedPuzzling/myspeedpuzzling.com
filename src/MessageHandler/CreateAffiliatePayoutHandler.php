<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\CreateAffiliatePayout;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateAffiliatePayoutHandler
{
    private const int PAYOUT_PERCENTAGE = 10;

    public function __construct(
        private StripeClient $stripeClient,
        private ReferralRepository $referralRepository,
        private AffiliatePayoutRepository $affiliatePayoutRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateAffiliatePayout $message): void
    {
        // Idempotency: check if payout already exists for this invoice
        if ($this->affiliatePayoutRepository->existsByStripeInvoiceId($message->stripeInvoiceId)) {
            return;
        }

        // Get subscription to find the customer/player
        $subscription = $this->stripeClient->subscriptions->retrieve($message->stripeSubscriptionId);

        $customerId = $subscription->customer;
        assert(is_string($customerId));

        $customer = $this->stripeClient->customers->retrieve($customerId);
        $playerId = $customer->metadata->player_id ?? null;

        if (!is_string($playerId)) {
            return;
        }

        // Check if subscriber has a referral
        try {
            $referral = $this->referralRepository->getBySubscriberId($playerId);
        } catch (ReferralNotFound) {
            return;
        }

        if (!$referral->affiliatePlayer->isInReferralProgram()) {
            $this->logger->info('Affiliate player is not in referral program, skipping payout creation', [
                'affiliate_player_id' => $referral->affiliatePlayer->id->toString(),
                'invoice_id' => $message->stripeInvoiceId,
            ]);
            return;
        }

        // Fetch invoice to get payment amount
        $invoice = $this->stripeClient->invoices->retrieve($message->stripeInvoiceId);

        /** @var int $amountPaid */
        $amountPaid = $invoice->amount_paid;
        /** @var string $currency */
        $currency = $invoice->currency;

        if ($amountPaid <= 0) {
            return;
        }

        $payoutAmount = (int) floor($amountPaid * self::PAYOUT_PERCENTAGE / 100);

        $payout = new AffiliatePayout(
            id: Uuid::uuid7(),
            affiliatePlayer: $referral->affiliatePlayer,
            referral: $referral,
            stripeInvoiceId: $message->stripeInvoiceId,
            paymentAmountCents: $amountPaid,
            payoutAmountCents: $payoutAmount,
            currency: strtoupper($currency),
            createdAt: $this->clock->now(),
        );

        $this->affiliatePayoutRepository->save($payout);

        $this->logger->info('Affiliate payout created', [
            'payout_id' => $payout->id->toString(),
            'affiliate_player_id' => $referral->affiliatePlayer->id->toString(),
            'amount_cents' => $payoutAmount,
            'currency' => $currency,
        ]);
    }
}
