<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * Identifies one compared subject: a player (solo times) or a pair/team. Its string form `p-<uuid>` / `t-<uuid>`
 * is what share links and Live props carry (docs/features/player-comparison.md).
 */
final readonly class ComparisonSubjectRef
{
    private function __construct(
        public ComparisonSubjectType $type,
        public string $id,
    ) {
    }

    public static function player(string $playerId): self
    {
        return new self(ComparisonSubjectType::Player, strtolower($playerId));
    }

    public static function team(string $teamId): self
    {
        return new self(ComparisonSubjectType::Team, strtolower($teamId));
    }

    public static function tryFromString(string $value): null|self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^([pt])-(.+)$/', $value, $matches) !== 1) {
            return null;
        }

        if (Uuid::isValid($matches[2]) === false) {
            return null;
        }

        return new self(ComparisonSubjectType::from($matches[1]), $matches[2]);
    }

    /**
     * Comma separated refs from user input (URL). Invalid and duplicate entries are dropped, order kept.
     *
     * @return list<self>
     */
    public static function listFromString(string $value, int $limit): array
    {
        $refs = [];

        foreach (explode(',', $value) as $part) {
            $ref = self::tryFromString($part);

            if ($ref === null || isset($refs[$ref->toString()])) {
                continue;
            }

            $refs[$ref->toString()] = $ref;

            if (count($refs) >= $limit) {
                break;
            }
        }

        return array_values($refs);
    }

    public function toString(): string
    {
        return $this->type->value . '-' . $this->id;
    }

    public function isPlayer(): bool
    {
        return $this->type === ComparisonSubjectType::Player;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
