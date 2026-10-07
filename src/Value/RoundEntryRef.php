<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * One round entry, as the official results endpoints address it: `participant_round:<id>` (a person in a solo round,
 * CompetitionParticipantRound) or `team:<id>` (a pair/team, CompetitionTeam). Ids are lower-cased.
 */
final readonly class RoundEntryRef
{
    public const string PARTICIPANT_ROUND = 'participant_round';
    public const string TEAM = 'team';

    private function __construct(
        /** self::PARTICIPANT_ROUND | self::TEAM */
        public string $kind,
        public string $id,
    ) {
    }

    public static function participantRound(string $id): self
    {
        return new self(self::PARTICIPANT_ROUND, strtolower($id));
    }

    public static function team(string $id): self
    {
        return new self(self::TEAM, strtolower($id));
    }

    public static function tryFromString(mixed $value): null|self
    {
        if (!is_string($value) || !str_contains($value, ':')) {
            return null;
        }

        [$kind, $id] = explode(':', $value, 2);

        if (!Uuid::isValid($id)) {
            return null;
        }

        return match ($kind) {
            self::PARTICIPANT_ROUND => self::participantRound($id),
            self::TEAM => self::team($id),
            default => null,
        };
    }

    public function isTeam(): bool
    {
        return $this->kind === self::TEAM;
    }

    public function toString(): string
    {
        return $this->kind . ':' . $this->id;
    }
}
