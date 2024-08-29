<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SyncWjpcIndividualParticipants
{
    public function __construct(
        /** @var array<array{name: string, country: string, group: null|string, rank: null|int}> */
        public array $individuals,
    ) {
    }
}
