<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\PartnerFinderNotAvailable;
use SpeedPuzzling\Web\Exceptions\PartnerListingInvalid;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ListForPartner;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderAttendance;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerListingRules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "List me" (docs/features/partner-finder.md, "Part A as built"). Everything is checked before anything changes; then
 * the listing is saved and the player marked going on a plain event (D2) - in the same transaction, under the event's
 * participants lock.
 *
 * - The same listing id again (a resent form) changes nothing.
 * - One open listing per player and event: a second "List me" with another id (two tabs, an old form) edits the open
 *   listing instead of failing on custom_partner_listing_one_open - the newest values win, like a second Save would.
 */
#[AsMessageHandler]
readonly final class ListForPartnerHandler
{
    public function __construct(
        private PartnerListingRepository $listingRepository,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private PartnerListingRules $rules,
        private PartnerFinderAttendance $attendance,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerFinderNotAvailable
     * @throws PartnerListingInvalid
     * @throws PartnerListingNotFound
     * @throws CompetitionNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(ListForPartner $message): void
    {
        if ($this->savedAlready($message)) {
            return;
        }

        $offer = $this->rules->offer($message->competitionId);
        $this->rules->checkKinds($offer, $message->lookingFor);
        $strengths = $this->rules->strengths($message->strengths, $message->customStrengths);
        $coMemberIds = $this->rules->coMembers($message->playerId, $message->coMemberIds);
        $languages = $this->rules->languages($message->languages);

        $competition = $this->competitionRepository->get($message->competitionId);
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        $open = $this->listingRepository->findOpenFor($message->competitionId, $message->playerId);

        // Both throw PartnerListingInvalid before they change anything
        if ($open !== null) {
            $open->edit(
                groupSize: $message->groupSize,
                lookingFor: $message->lookingFor,
                needed: $message->needed,
                coMemberIds: $coMemberIds,
                goal: $message->goal,
                strengths: $strengths,
                languages: $languages,
                note: $message->note,
                now: $now,
            );
        } else {
            $this->listingRepository->save(new PartnerListing(
                id: Uuid::fromString($message->listingId),
                competition: $competition,
                player: $player,
                groupSize: $message->groupSize,
                lookingFor: $message->lookingFor,
                needed: $message->needed,
                coMemberIds: $coMemberIds,
                goal: $message->goal,
                strengths: $strengths,
                languages: $languages,
                note: $message->note,
                createdAt: $now,
            ));
        }

        $this->attendance->ensureGoing($competition, $player);
    }

    /**
     * @throws PartnerListingNotFound an id of somebody else's listing
     */
    private function savedAlready(ListForPartner $message): bool
    {
        if (Uuid::isValid($message->listingId) === false) {
            throw new PartnerListingNotFound();
        }

        try {
            $listing = $this->listingRepository->get($message->listingId);
        } catch (PartnerListingNotFound) {
            return false;
        }

        if ($listing->player->id->toString() !== strtolower($message->playerId)) {
            throw new PartnerListingNotFound();
        }

        return true;
    }
}
