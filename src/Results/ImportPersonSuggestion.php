<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A player who may be a person of the import's file - one of the importing player's own people
 * (docs/features/data-import/people.md "Suggestions"). The name is null for a private player the importing player
 * may not see by name - they are offered by their code then.
 */
readonly final class ImportPersonSuggestion
{
    public function __construct(
        public string $playerId,
        public null|string $name,
        public string $code,
        public null|string $country,
        /** The folded names are equal; false: only the first name is - "maybe" */
        public bool $exact,
        /** The two have a pair/team result together already */
        public bool $solvedTogether,
    ) {
    }
}
