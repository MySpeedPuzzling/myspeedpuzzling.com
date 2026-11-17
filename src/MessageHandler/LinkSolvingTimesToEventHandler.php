<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\WaitingEventLink;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\EventNotLinkable;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsItsOwnEdit;
use SpeedPuzzling\Web\Message\LinkSolvingTimesToEvent;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\WaitingEventLinkRepository;
use SpeedPuzzling\Web\Results\LinkedSolvingTimes;
use SpeedPuzzling\Web\Services\EventWizard\EventLinkDecision;
use SpeedPuzzling\Web\Services\EventWizard\EventLinkRule;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * All or nothing: every time is checked (exists, the actor may change it - PuzzleSolvingTime::canBeModifiedBy(), so a
 * pair/team member may and an imported event result never; not an unfinished result, whose event goes with its own
 * edit) before any is linked. The event follows EventLinkRule. A time linked to it already is left as it is.
 */
#[AsMessageHandler]
readonly final class LinkSolvingTimesToEventHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private WaitingEventLinkRepository $waitingEventLinkRepository,
        private EventLinkRule $eventLinkRule,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws CompetitionSeriesNotFound
     * @throws EventNotLinkable
     * @throws PuzzleSolvingTimeNotFound
     * @throws CanNotModifyOtherPlayersTime
     * @throws UnfinishedResultNeedsItsOwnEdit
     */
    public function __invoke(LinkSolvingTimesToEvent $message): LinkedSolvingTimes
    {
        $actor = $this->playerRepository->get($message->actorPlayerId);
        $competition = $message->targetCompetitionId !== null ? $this->competitionRepository->get($message->targetCompetitionId) : null;
        $series = $message->targetSeriesId !== null ? $this->competitionSeriesRepository->get($message->targetSeriesId) : null;

        $decision = $this->eventLinkRule->decide($actor, $competition, $series);

        if ($decision === EventLinkDecision::Refused) {
            throw new EventNotLinkable();
        }

        $times = [];

        foreach (array_unique(array_map(strtolower(...), $message->solvingTimeIds)) as $timeId) {
            $time = $this->puzzleSolvingTimeRepository->get($timeId);

            if ($time->canBeModifiedBy($actor) === false) {
                throw new CanNotModifyOtherPlayersTime();
            }

            if ($time->isUnfinished()) {
                throw new UnfinishedResultNeedsItsOwnEdit();
            }

            $times[] = $time;
        }

        $linked = 0;
        $waiting = 0;
        $already = 0;
        $affectedPlayerIds = [];

        foreach ($times as $time) {
            $waitingLink = $this->waitingEventLinkRepository->ofTime($time);

            if ($time->isLinkedTo($competition, $series)) {
                if ($waitingLink !== null) {
                    $this->waitingEventLinkRepository->delete($waitingLink);
                }

                $already++;

                continue;
            }

            if ($decision === EventLinkDecision::Waits) {
                if ($waitingLink !== null) {
                    $waitingLink->retarget($competition, $series, $actor, $this->clock->now(), $time->competitionSeries === null ? $time->competition?->id : null, $time->competitionSeries?->id);
                    $waiting++;

                    continue;
                }

                $this->waitingEventLinkRepository->save(new WaitingEventLink(
                    id: Uuid::uuid7(),
                    solvingTime: $time,
                    competition: $competition,
                    series: $series,
                    linkedBy: $actor,
                    createdAt: $this->clock->now(),
                    // The link the player saw - replaced at the approval unless it changed meanwhile
                    seenCompetitionId: $time->competitionSeries === null ? $time->competition?->id : null,
                    seenSeriesId: $time->competitionSeries?->id,
                ));
                $waiting++;

                continue;
            }

            if ($waitingLink !== null) {
                $this->waitingEventLinkRepository->delete($waitingLink);
            }

            $this->eventLinkRule->linkNow($time, $competition, $series);
            $linked++;

            foreach ($time->memberPlayerIds() as $memberId) {
                $affectedPlayerIds[$memberId] = true;
            }
        }

        // Achievements count events (AchievementSqlRules) - the members' badges follow the new links
        foreach (array_keys($affectedPlayerIds) as $playerId) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer((string) $playerId));
        }

        return new LinkedSolvingTimes($linked, $waiting, $already);
    }
}
