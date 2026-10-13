<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;

/**
 * What a personal link opens (OnlineAccess::forToken()): the participant, their competition and the round their page is
 * about now - null while the competition has no round with online timing. `round` and `onlineRound` are null together;
 * `entry` is null when the participant is not entered in that round. `clock` is the round's clock as of `asOfMs`, the
 * moment the rows were read (taken before reading them, so a state never claims to be newer than what it holds).
 */
final readonly class OnlineAccessContext
{
    public function __construct(
        public CompetitionParticipant $participant,
        public Competition $competition,
        public null|CompetitionRound $round,
        public null|OnlineRound $onlineRound,
        public null|CompetitionParticipantRound $entry,
        public null|RoundClock $clock,
        public int $asOfMs,
    ) {
    }
}
