<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\ClosePartnerListing;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Found my partner" / "Remove listing" (docs/features/partner-finder.md D7) - the owner's own two reasons; the others
 * (not going, event over, entered) close a listing by themselves. "Found" counts a group still needing more than one
 * person down instead of closing (PartnerListing::reduceNeeded()). A closed listing stays closed with its first reason.
 */
#[AsMessageHandler]
readonly final class ClosePartnerListingHandler
{
    public function __construct(
        private PartnerListingRepository $listingRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerListingNotFound
     */
    public function __invoke(ClosePartnerListing $message): void
    {
        $listing = $this->listingRepository->get($message->listingId);

        if ($listing->player->id->toString() !== strtolower($message->playerId)) {
            throw new PartnerListingNotFound();
        }

        $now = $this->clock->now();

        match ($message->reason) {
            PartnerListingClosedReason::Found => $listing->reduceNeeded($now),
            PartnerListingClosedReason::Withdrawn => $listing->close(PartnerListingClosedReason::Withdrawn, $now),
            default => throw new \InvalidArgumentException(sprintf('A listing\'s owner closes it as found or withdrawn, not "%s".', $message->reason->value)),
        };
    }
}
