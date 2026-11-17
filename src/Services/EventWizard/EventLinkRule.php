<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Services\SolvingTimeEventDerivation;

/**
 * The one rule of "Link my results" (docs/features/competitions-management/creation-wizard.md "Backfill"), used by
 * LinkSolvingTimesToEventHandler and ApplyWaitingEventLinksHandler:
 *
 * - a publicly visible event or series: linked now;
 * - not public yet but the player may edit it (their own new or pending event, their own draft, an event of their
 *   organization's team; admins any): the link waits (WaitingEventLink) until it is public - a time is never linked to an
 *   event nobody may see, so no reader of puzzle_solving_time can show it early;
 * - anything else (somebody else's draft or pending event) or a rejected one: refused.
 *
 * linkNow() sets the link through the edit's own entity path (PuzzleSolvingTime::linkToEvent() = what modify() does to
 * the link) and derives the series pick's edition and the round like the edit (SolvingTimeEventDerivation).
 */
readonly final class EventLinkRule
{
    public function __construct(
        private GetCompetitionPermissions $getCompetitionPermissions,
        private SolvingTimeEventDerivation $solvingTimeEventDerivation,
    ) {
    }

    public static function isRejected(null|Competition $competition, null|CompetitionSeries $series): bool
    {
        return $competition?->rejectedAt !== null
            || $competition?->series?->isRejected() === true
            || $series?->isRejected() === true;
    }

    public static function isPublic(null|Competition $competition, null|CompetitionSeries $series): bool
    {
        return $competition !== null ? $competition->isPubliclyVisible() : $series?->isPubliclyVisible() === true;
    }

    public function decide(Player $actor, null|Competition $competition, null|CompetitionSeries $series): EventLinkDecision
    {
        if ($competition !== null) {
            if (self::isRejected($competition, null)) {
                return EventLinkDecision::Refused;
            }

            if ($competition->isPubliclyVisible()) {
                return EventLinkDecision::Now;
            }

            return $actor->isAdmin || $this->getCompetitionPermissions->forPlayer($actor->id->toString())->canEditCompetition($competition->id->toString())
                ? EventLinkDecision::Waits
                : EventLinkDecision::Refused;
        }

        if ($series === null || $series->isRejected()) {
            return EventLinkDecision::Refused;
        }

        if ($series->isPubliclyVisible()) {
            return EventLinkDecision::Now;
        }

        return $actor->isAdmin || $this->getCompetitionPermissions->forPlayer($actor->id->toString())->canEditSeries($series->id->toString())
            ? EventLinkDecision::Waits
            : EventLinkDecision::Refused;
    }

    public function linkNow(PuzzleSolvingTime $solvingTime, null|Competition $competition, null|CompetitionSeries $series): void
    {
        $solvingTime->linkToEvent($competition, $series);
        $this->solvingTimeEventDerivation->derive($solvingTime);
    }
}
