<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\WaitingEventLink;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\WaitingEventLinkRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Waiting event links (creation-wizard.md "Backfill") become the times' links the moment their event is public - called
 * right where an event or series becomes public, in that transaction: ApproveCompetitionHandler,
 * ApproveCompetitionSeriesHandler, PublishCompetitionHandler, PublishCompetitionSeriesHandler and
 * OrganizationApprovalPolicy (approved at once under a trusted organization, an organization's approval approving its
 * pending items). It decides on the entities as they are in memory (isPubliclyVisible()), so it works before the flush.
 * ApplyWaitingEventLinks (the 15-minute cron) is the safety net for anything else that makes an event public.
 *
 * One link: applied through EventLinkRule only while the time still has no event (a link made meanwhile wins) and its
 * linker may still change it; dropped when the event or series was rejected; kept while it is not public yet.
 */
readonly final class WaitingEventLinksApplier
{
    public const string APPLIED = 'applied';
    public const string DROPPED = 'dropped';
    public const string KEPT = 'kept';

    public function __construct(
        private WaitingEventLinkRepository $waitingEventLinkRepository,
        private EventLinkRule $eventLinkRule,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * An event, or a series with the waiting links of its dates
     */
    public function applyFor(Competition|CompetitionSeries $item): void
    {
        $links = $item instanceof Competition
            ? $this->waitingEventLinkRepository->ofCompetition($item)
            : $this->waitingEventLinkRepository->ofSeriesAndItsEditions($item);

        $this->apply($links);
    }

    /**
     * @param list<WaitingEventLink> $links
     * @return array{applied: int, dropped: int}
     */
    public function apply(array $links): array
    {
        $counts = [self::APPLIED => 0, self::DROPPED => 0];
        $affectedPlayerIds = [];

        foreach ($links as $link) {
            $outcome = $this->applyOne($link);

            if ($outcome === self::KEPT) {
                continue;
            }

            $counts[$outcome]++;

            if ($outcome === self::APPLIED) {
                foreach ($link->solvingTime->memberPlayerIds() as $memberId) {
                    $affectedPlayerIds[$memberId] = true;
                }
            }
        }

        // Achievements count events (AchievementSqlRules) - the members' badges follow the new links
        foreach (array_keys($affectedPlayerIds) as $playerId) {
            $this->messageBus->dispatch(new RecalculateBadgesForPlayer((string) $playerId));
        }

        return $counts;
    }

    /**
     * @return self::APPLIED|self::DROPPED|self::KEPT
     */
    private function applyOne(WaitingEventLink $link): string
    {
        $rejected = EventLinkRule::isRejected($link->competition, $link->series);

        if ($rejected === false && EventLinkRule::isPublic($link->competition, $link->series) === false) {
            return self::KEPT;
        }

        $time = $link->solvingTime;
        $this->waitingEventLinkRepository->delete($link);

        if (
            $rejected
            || $time->competition !== null
            || $time->competitionSeries !== null
            || $time->canBeModifiedBy($link->linkedBy) === false
            || $time->isUnfinished()
        ) {
            return self::DROPPED;
        }

        $this->eventLinkRule->linkNow($time, $link->competition, $link->series);

        return self::APPLIED;
    }
}
