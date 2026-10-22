<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\EditPartnerListing;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerListingRules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The owner changes their open listing (docs/features/partner-finder.md). Somebody else's listing, or a closed one, is
 * not there for them (404). Checked like "List me" before anything changes; nothing about going changes.
 */
#[AsMessageHandler]
readonly final class EditPartnerListingHandler
{
    public function __construct(
        private PartnerListingRepository $listingRepository,
        private PartnerListingRules $rules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerFinderNotAvailable
     * @throws PartnerListingInvalid
     * @throws PartnerListingNotFound
     */
    public function __invoke(EditPartnerListing $message): void
    {
        $listing = $this->listingRepository->get($message->listingId);

        if ($listing->player->id->toString() !== strtolower($message->playerId) || $listing->isOpen() === false) {
            throw new PartnerListingNotFound();
        }

        $offer = $this->rules->offer($listing->competition->id->toString());
        $this->rules->checkKinds($offer, $message->lookingFor);
        $strengths = $this->rules->strengths($message->strengths, $message->customStrengths);
        $coMemberIds = $message->coMemberIds === null
            ? $listing->coMemberIds()
            : $this->rules->coMembers($message->playerId, $message->coMemberIds);
        $languages = $this->rules->languages($message->languages);

        $listing->edit(
            groupSize: $message->groupSize,
            lookingFor: $message->lookingFor,
            needed: $message->needed,
            coMemberIds: $coMemberIds,
            goal: $message->goal,
            strengths: $strengths,
            languages: $languages,
            note: $message->note,
            now: $this->clock->now(),
        );
    }
}
