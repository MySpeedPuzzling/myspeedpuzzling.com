<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * People of a sandbox demo event who enter the same rounds together (docs/features/sandbox/README.md "Demo events"):
 * `$units` solo entrants, pairs or teams of `$size`. New people, or - `$reuseOf` - the first people of another group
 * (the same person in more rounds of the event: one participant).
 */
readonly final class SandboxDemoGroup
{
    public const string NAMES_FUN = 'fun';
    public const string NAMES_FIRST_NAMES = 'first_names';
    public const string NAMES_FAMILY = 'family';

    /**
     * @param list<string> $roundKeys
     * @param array<string, int> $divisions division name => every n-th unit gets it (3 = units 3, 6, 9 …);
     *     the first match wins, the rest get the round's first division
     */
    public function __construct(
        public string $key,
        public int $units,
        public int $size,
        public array $roundKeys,
        public null|string $reuseOf = null,
        public string $teamNames = self::NAMES_FUN,
        public array $divisions = [],
    ) {
    }
}
