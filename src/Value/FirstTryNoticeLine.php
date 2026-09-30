<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\FirstTryPerson;
use SpeedPuzzling\Web\Results\FirstTryTime;

/**
 * One sentence of the first-try notice, already cut down to what the viewer may be told.
 */
readonly final class FirstTryNoticeLine
{
    // A result the viewer took part in - theirs to see in full
    public const string OWN = 'own';
    // A registered teammate the viewer may see: named, with the date
    public const string TEAMMATE = 'teammate';
    // A private or blocked teammate: no name, no date
    public const string SOMEONE = 'someone';

    /**
     * @param list<string> $with the other people of the viewer's own result, as the viewer may call them
     */
    public function __construct(
        public string $kind,
        public null|DateTimeImmutable $date,
        public null|FirstTryTime $time = null,
        public null|FirstTryPerson $person = null,
        public array $with = [],
    ) {
    }
}
