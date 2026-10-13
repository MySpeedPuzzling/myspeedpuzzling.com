<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Whether a round can use online timing (docs/features/online-competitions/README.md): a round of an online event,
 * and in the prototype a solo one (README §1: pairs and teams are out of scope - an official pair/team result lives on
 * the pair/team, a participant's page on the participant). The one rule: the "Online timing" page says why not,
 * EnableOnlineRound refuses (OnlineTimingNotAvailable).
 */
enum OnlineTimingAvailability: string
{
    case Available = 'available';
    case InPersonEvent = 'in_person_event';
    case NotSolo = 'not_solo';

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
