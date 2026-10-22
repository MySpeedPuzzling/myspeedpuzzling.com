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
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerGroupMemberNotifier;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerListingRules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "List me" (docs/features/partner-finder.md, "Part A as built"). Everything is checked before anything changes; then
 * the listing is saved and the player marked going on a plain event (D2) - in the same transaction, under the event's
 * participants lock.
 *
 * - The same listing id again (a resent form) changes nothing while that listing is open.
 * - The id of the player's own listing that was closed meanwhile (an edit form posted after "Found my partner" in
 *   another tab, the event's or the player's auto-close): a fresh open listing with a new id - the old one stays closed
 *   with its reason, which the player may not know about; the values just posted are what they want listed.
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
        private PartnerGroupMemberNotifier $groupMemberNotifier,
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
        $sameId = $this->ownListingWithTheId($message);

        if ($sameId !== null && $sameId->isOpen()) {
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
        $newMembers = $this->groupMemberNotifier->newMembers($open?->coMemberIds() ?? [], $coMemberIds);

        // Both throw PartnerListingInvalid before they change anything
        if ($open !== null) {
            $listing = $open;
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
            $listing = new PartnerListing(
                id: $sameId === null ? Uuid::fromString($message->listingId) : Uuid::uuid7(),
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
            );
            $this->listingRepository->save($listing);
        }

        $this->groupMemberNotifier->notify($newMembers, $listing, $now);
        $this->attendance->ensureGoing($competition, $player);
    }

    /**
     * The player's own listing that already has the message's id - null when the id is new
     *
     * @throws PartnerListingNotFound an id of somebody else's listing
     */
    private function ownListingWithTheId(ListForPartner $message): null|PartnerListing
    {
        if (Uuid::isValid($message->listingId) === false) {
            throw new PartnerListingNotFound();
        }

        try {
            $listing = $this->listingRepository->get($message->listingId);
        } catch (PartnerListingNotFound) {
            return null;
        }

        if ($listing->player->id->toString() !== strtolower($message->playerId)) {
            throw new PartnerListingNotFound();
        }

        return $listing;
    }
}
