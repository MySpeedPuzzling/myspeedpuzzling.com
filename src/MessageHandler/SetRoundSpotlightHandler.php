<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundSpotlight;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\SetRoundSpotlight;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundSpotlightRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\DisplayControlSpotlight;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayControlEntries;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Puts an entry of a round in its displays' spotlight, or takes it out (competition toolkit v2 L2,
 * displays-standings.md §5.1). The entry must be one of the round's entries as the organiser's tools list them
 * (GetRoundResultEntries - a person of a solo round, a pair/team otherwise; nobody removed from the event or on the
 * waitlist), else `unknown_entry`; only for an event with a display (`no_displays`). Everything is checked before
 * anything changes. Reads the round and its entries, writes only `competition_round_spotlight`. The controller signals
 * the displays after the commit (CompetitionDisplayLiveUpdates::controlChanged()).
 */
#[AsMessageHandler]
readonly final class SetRoundSpotlightHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private CompetitionDisplayRepository $displayRepository,
        private CompetitionRoundSpotlightRepository $spotlightRepository,
        private GetRoundResultEntries $getRoundResultEntries,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     * @throws DisplayControlRefused
     */
    public function __invoke(SetRoundSpotlight $message): null|DisplayControlSpotlight
    {
        $round = $this->roundRepository->get($message->roundId);

        // The event the controller authorised is the round's own
        if (strtolower($round->competition->id->toString()) !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        if ($this->displayRepository->countOf($round->competition) === 0) {
            throw new DisplayControlRefused(DisplayControlRefused::NO_DISPLAYS);
        }

        $spotlight = $this->spotlightRepository->find($round->id->toString());

        if ($message->entryRef === null) {
            if ($spotlight !== null) {
                $this->spotlightRepository->delete($spotlight);
            }

            return null;
        }

        $ref = RoundEntryRef::tryFromString($message->entryRef);
        $entry = $ref !== null ? ($this->getRoundResultEntries->byRefs($round->id->toString(), [$ref->toString()])[0] ?? null) : null;

        if ($ref === null || $entry === null) {
            throw new DisplayControlRefused(DisplayControlRefused::UNKNOWN_ENTRY);
        }

        $now = $this->clock->now();
        $actor = $message->actorPlayerId !== null ? $this->playerRepository->get($message->actorPlayerId) : null;

        if ($spotlight === null) {
            $this->spotlightRepository->save(new CompetitionRoundSpotlight($round, $ref->toString(), $now, $actor));
        } else {
            $spotlight->spotlight($ref, $actor, $now);
        }

        return new DisplayControlSpotlight($ref->toString(), DisplayControlEntries::name($entry), $entry->tableNumber);
    }
}
