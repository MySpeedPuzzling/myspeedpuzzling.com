<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\CompetitionTeam;

/**
 * The "Add teams" field of a round: one team name per line, so an organizer can paste the whole list at once.
 * Blank lines are skipped and a name repeated in the list counts once. No name at all adds one unnamed team.
 */
readonly final class CompetitionTeamNames
{
    public const int MAX_TEAMS_AT_ONCE = 100;

    /**
     * @param list<string> $names
     */
    private function __construct(
        public array $names,
    ) {
    }

    public static function fromText(null|string $text): self
    {
        $names = [];

        foreach (preg_split('/\R/u', $text ?? '') ?: [] as $line) {
            $name = CompetitionTeam::cleanName($line);

            if ($name !== null) {
                $names[mb_strtolower($name)] ??= $name;
            }
        }

        return new self(array_values($names));
    }

    /**
     * @return list<string>
     */
    public function tooLong(): array
    {
        return array_values(array_filter(
            $this->names,
            static fn (string $name): bool => mb_strlen($name) > CompetitionTeam::NAME_MAX_LENGTH,
        ));
    }

    public function tooMany(): bool
    {
        return count($this->names) > self::MAX_TEAMS_AT_ONCE;
    }
}
