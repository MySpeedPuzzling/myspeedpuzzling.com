<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\GetLinkableSolvingTimes;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * "Were you there? Link my results" on the one-time event and edition pages (docs/features/competitions-management/
 * creation-wizard.md "Backfill", entry points): how many of the signed-in viewer's own results are likely from this
 * event (GetLinkableSolvingTimes::countLikelyFromEvent() - a subset of what the Link page lists), null when the entry
 * is not shown. No statement for a guest, an event that has not started, an event without days, or one the viewer may
 * not link to (EventLinkRule: public, or not public but the viewer may edit it - never a rejected one); one statement
 * otherwise. The rounds' puzzles are the page's own (GetEditionRounds = RoundPuzzlesVisibleNow, like the Link page).
 */
readonly final class LinkMyResultsEntry
{
    public function __construct(
        private GetLinkableSolvingTimes $getLinkableSolvingTimes,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param list<EditionRoundDetail> $rounds
     */
    public function count(
        Competition $competition,
        CompetitionEvent $event,
        array $rounds,
        bool $isPubliclyVisible,
        null|string $viewerPlayerId,
        DateTimeImmutable $now,
    ): null|int {
        if ($viewerPlayerId === null) {
            return null;
        }

        [$dayFrom, $dayTo] = self::days($event, $rounds);

        if ($dayFrom === null || $dayTo === null || $dayFrom->format('Y-m-d') > $now->format('Y-m-d')) {
            return null;
        }

        // Not public: only for whoever may edit it (EventLinkRule::decide() - the link waits), never a rejected one
        if ($isPubliclyVisible === false && $this->mayLinkToHidden($competition) === false) {
            return null;
        }

        $puzzleIds = [];

        foreach ($rounds as $round) {
            foreach ($round->puzzles as $puzzle) {
                $puzzleIds[$puzzle->puzzleId] = true;
            }
        }

        $count = $this->getLinkableSolvingTimes->countLikelyFromEvent($viewerPlayerId, array_keys($puzzleIds), $dayFrom, $dayTo);

        return $count > 0 ? $count : null;
    }

    private function mayLinkToHidden(Competition $competition): bool
    {
        return EventLinkRule::isRejected($competition, null) === false
            && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competition->id->toString());
    }

    /**
     * The event's days like the Link page's (EventWizardLinkResultsController::days()): its dates, else its rounds' days
     *
     * @param list<EditionRoundDetail> $rounds
     * @return array{0: null|DateTimeImmutable, 1: null|DateTimeImmutable}
     */
    private static function days(CompetitionEvent $event, array $rounds): array
    {
        if ($event->dateFrom !== null) {
            return [$event->dateFrom, $event->dateTo ?? $event->dateFrom];
        }

        $first = null;
        $last = null;

        foreach ($rounds as $round) {
            $first = $first === null || $round->startsAt < $first ? $round->startsAt : $first;
            $last = $last === null || $round->startsAt > $last ? $round->startsAt : $last;
        }

        return [$first, $last];
    }
}
