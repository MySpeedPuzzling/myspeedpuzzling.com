<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\KarmaBreakdown;
use SpeedPuzzling\Web\Results\KarmaItem;
use SpeedPuzzling\Web\Services\Karma\KarmaDerivation;
use SpeedPuzzling\Web\Value\KarmaKind;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;

/**
 * "What did I get my karma for?" (docs/features/karma.md): the player's karma items straight from the derivation, so
 * the history always explains the stored total (player.karma) - copies merged into an earlier puzzle included, as not
 * counted.
 */
readonly final class GetPlayerKarma
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function breakdown(string $playerId): KarmaBreakdown
    {
        $items = KarmaDerivation::itemsCte(scoped: true);
        $eligible = KarmaDerivation::ELIGIBLE_PLAYER;

        $sql = <<<SQL
{$items}
SELECT i.kind, COUNT(*) AS items
FROM karma_items i
JOIN player pl ON {$eligible}
WHERE i.player_id = :playerId
GROUP BY i.kind
SQL;

        /** @var list<array{kind: string, items: int|string}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            ['karmaPlayerIds' => [$playerId], 'playerId' => $playerId],
            ['karmaPlayerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['kind']] = (int) $row['items'];
        }

        return new KarmaBreakdown($counts);
    }

    /**
     * Newest first; undated items (puzzles added before dates were kept) last.
     *
     * @return list<KarmaItem>
     */
    public function items(string $playerId, int $limit, int $offset): array
    {
        $items = KarmaDerivation::itemsCte(scoped: true);
        $eligible = KarmaDerivation::ELIGIBLE_PLAYER;

        $sql = <<<SQL
{$items}
SELECT i.kind, i.at, i.puzzle_id, i.puzzle_name, i.action
FROM karma_items i
JOIN player pl ON {$eligible}
WHERE i.player_id = :playerId
ORDER BY i.at DESC NULLS LAST, i.kind, i.puzzle_id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{kind: string, at: null|string, puzzle_id: null|string, puzzle_name: null|string, action: null|string}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            ['karmaPlayerIds' => [$playerId], 'playerId' => $playerId, 'limit' => $limit, 'offset' => $offset],
            ['karmaPlayerIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): KarmaItem => new KarmaItem(
            kind: KarmaKind::from($row['kind']),
            at: $row['at'] !== null ? new DateTimeImmutable($row['at']) : null,
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            action: $row['action'] !== null ? PuzzleModerationAction::tryFrom($row['action']) : null,
        ), $rows);
    }
}
