<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;
use SpeedPuzzling\Web\Value\PlayerMomentType;

/**
 * A moment shown as a chip on a person (docs/features/players-page/README.md): "New best on 500 pieces",
 * "Reached 500 puzzles", "1M pieces placed", "First puzzle logged". The wording lives in the template.
 */
readonly final class PlayerMomentChip
{
    public const int MIN_PIECES_FOR_BEST = 300;

    public function __construct(
        public PlayerMomentType $type,
        // Personal best: the piece count it is the best on
        public null|int $piecesCount,
        // Milestones: the milestone reached
        public null|int $value,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * The moments of one person as the query aggregates them: a JSON list of {type, pieces_count, value, occurred_at}.
     * Anything unexpected in it is skipped - a chip is decoration, never worth an error page.
     *
     * @return list<self>
     */
    public static function listFromJson(string $json): array
    {
        $decoded = Json::decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $chips = [];

        foreach ($decoded as $moment) {
            if (!is_array($moment) || !is_string($moment['type'] ?? null) || !is_string($moment['occurred_at'] ?? null)) {
                continue;
            }

            $type = PlayerMomentType::tryFrom($moment['type']);

            if ($type === null) {
                continue;
            }

            $chips[] = new self(
                type: $type,
                piecesCount: is_int($moment['pieces_count'] ?? null) ? $moment['pieces_count'] : null,
                value: is_int($moment['value'] ?? null) ? $moment['value'] : null,
                occurredAt: new DateTimeImmutable($moment['occurred_at']),
            );
        }

        return $chips;
    }

    /**
     * The most notable chips first, at most $limit, never two with the same wording: a personal best on 500 or 1000
     * pieces > a puzzles milestone > a pieces milestone > another personal best > the first result. Among equals the
     * latest wins - for milestones that is also the highest one. A personal best on a small puzzle (below
     * MIN_PIECES_FOR_BEST) is left out - "New best on 88 pieces" is noise in a summary of somebody's week.
     *
     * @param list<self> $chips
     * @return list<self>
     */
    public static function mostNotable(array $chips, int $limit): array
    {
        $chips = array_values(array_filter(
            $chips,
            static fn (self $chip): bool => $chip->type !== PlayerMomentType::PersonalBest || ($chip->piecesCount ?? 0) >= self::MIN_PIECES_FOR_BEST,
        ));

        usort(
            $chips,
            static fn (self $a, self $b): int => [$a->notability(), $b->occurredAt] <=> [$b->notability(), $a->occurredAt],
        );

        $picked = [];

        foreach ($chips as $chip) {
            if (count($picked) >= $limit) {
                break;
            }

            $picked[$chip->wordingKey()] ??= $chip;
        }

        return array_values($picked);
    }

    /**
     * Lower is more notable.
     */
    public function notability(): int
    {
        return match ($this->type) {
            PlayerMomentType::PersonalBest => in_array($this->piecesCount, [500, 1000], true) ? 0 : 3,
            PlayerMomentType::PuzzlesMilestone => 1,
            PlayerMomentType::PiecesMilestone => 2,
            PlayerMomentType::FirstResult => 4,
        };
    }

    /**
     * The milestone in short: 100k, 250k, 1M, 2.5M - pieces milestones are round numbers.
     */
    public function compactValue(): string
    {
        $value = $this->value ?? 0;

        if ($value >= 1_000_000) {
            return self::withoutTrailingZero($value / 1_000_000) . 'M';
        }

        if ($value >= 1_000) {
            return self::withoutTrailingZero($value / 1_000) . 'k';
        }

        return (string) $value;
    }

    private function wordingKey(): string
    {
        return $this->type->value . ($this->type === PlayerMomentType::PersonalBest ? ':' . $this->piecesCount : '');
    }

    private static function withoutTrailingZero(float|int $number): string
    {
        return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
    }
}
