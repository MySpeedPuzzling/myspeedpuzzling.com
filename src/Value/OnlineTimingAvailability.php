<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Query\EventIsOnline;

/**
 * Whether a round can use online timing (docs/features/online-competitions/README.md): a round of an online event
 * (EventIsOnline - an edition by its series), and in the prototype a solo one (README §1: pairs and teams are out of
 * scope - an official pair/team result lives on the pair/team, a participant's page on the participant). The one rule:
 * the "Online timing" page says why not, EnableOnlineRound refuses (OnlineTimingNotAvailable), and the changes that
 * would break it for a round using online timing are refused (OnlineTimingInUse).
 */
enum OnlineTimingAvailability: string
{
    case Available = 'available';
    case InPersonEvent = 'in_person_event';
    case NotSolo = 'not_solo';

    public static function ofRound(CompetitionRound $round): self
    {
        return self::of(EventIsOnline::of($round->competition), $round->category);
    }

    public static function of(bool $competitionIsOnline, RoundCategory $category): self
    {
        if ($competitionIsOnline === false) {
            return self::InPersonEvent;
        }

        if ($category !== RoundCategory::Solo) {
            return self::NotSolo;
        }

        return self::Available;
    }

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
