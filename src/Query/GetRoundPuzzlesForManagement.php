<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\RoundPuzzleForManagement;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundPuzzleOwnership;
use SpeedPuzzling\Web\Value\RoundPuzzleStatus;

readonly final class GetRoundPuzzlesForManagement
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<RoundPuzzleForManagement>
     */
    public function ofRound(string $roundId): array
    {
        $mayKeepHiddenEverywhere = RoundPuzzleOwnership::sqlMayKeepHiddenEverywhere('crp', 'p', 'cr');
        $shownByAnotherRound = RoundPuzzleOwnership::sqlShownByAnotherRound('crp');
        $otherHidden = RoundPuzzleReveal::sqlHidden('name_crp', 'name_cr');
        $query = <<<SQL
SELECT
    crp.id AS round_puzzle_id,
    {$mayKeepHiddenEverywhere} AS may_keep_hidden_everywhere,
    {$shownByAnotherRound} AS shown_by_another_round,
    EXISTS (
        SELECT 1 FROM competition_round_puzzle name_crp
        INNER JOIN competition_round name_cr ON name_cr.id = name_crp.round_id
        WHERE name_crp.puzzle_id = crp.puzzle_id
            AND name_crp.id <> crp.id
            AND name_crp.hides_everywhere
            AND COALESCE(name_crp.hide_mode, 'entirely') = 'entirely'
            AND {$otherHidden}
    ) AS name_held_by_another_round,
    EXISTS (
        SELECT 1 FROM competition_round_puzzle other_crp
        WHERE other_crp.puzzle_id = crp.puzzle_id
            AND other_crp.id <> crp.id
            AND other_crp.hides_everywhere
            AND other_crp.hide_until_round_starts
    ) AS another_round_holds,
    crp.hide_until_round_starts,
    crp.hide_mode,
    crp.reveal_mode,
    crp.reveal_at,
    crp.hides_everywhere,
    cr.starts_at AS round_starts_at,
    cr.reveal_delay_minutes AS round_reveal_delay_minutes,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    p.image AS puzzle_image,
    p.hide_until AS puzzle_hide_until,
    p.hide_image_until AS puzzle_hide_image_until,
    m.name AS manufacturer_name
FROM competition_round_puzzle crp
INNER JOIN competition_round cr ON cr.id = crp.round_id
INNER JOIN puzzle p ON p.id = crp.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE crp.round_id = :roundId
ORDER BY p.name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'roundId' => $roundId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        $now = $this->clock->now();

        return array_map(static function (array $row) use ($now): RoundPuzzleForManagement {
            /**
             * @var array{
             *     round_puzzle_id: string,
             *     hide_until_round_starts: bool|string,
             *     hide_mode: null|string,
             *     reveal_mode: string,
             *     reveal_at: null|string,
             *     hides_everywhere: bool|string,
             *     round_starts_at: string,
             *     round_reveal_delay_minutes: int|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int|string,
             *     puzzle_image: null|string,
             *     manufacturer_name: null|string,
             *     puzzle_hide_until: null|string,
             *     may_keep_hidden_everywhere: bool|string,
             *     another_round_holds: bool|string,
             *     shown_by_another_round: bool|string,
             *     name_held_by_another_round: bool|string,
             *     puzzle_hide_image_until: null|string,
             * } $row
             */

            $hideUntilRoundStarts = self::bool($row['hide_until_round_starts']);
            $revealMode = RoundPuzzleReveal::from($row['reveal_mode']);
            $hideMode = $row['hide_mode'] !== null ? PuzzleHideMode::from($row['hide_mode']) : null;
            $revealsAt = $hideUntilRoundStarts
                ? $revealMode->revealAt(
                    new DateTimeImmutable($row['round_starts_at']),
                    (int) $row['round_reveal_delay_minutes'],
                    $row['reveal_at'] !== null ? new DateTimeImmutable($row['reveal_at']) : null,
                )
                : null;

            return new RoundPuzzleForManagement(
                roundPuzzleId: $row['round_puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                piecesCount: (int) $row['pieces_count'],
                puzzleImage: $row['puzzle_image'],
                manufacturerName: $row['manufacturer_name'],
                hideUntilRoundStarts: $hideUntilRoundStarts,
                hideMode: $hideMode,
                revealMode: $revealMode,
                revealsAt: $revealsAt,
                hidesEverywhere: self::bool($row['hides_everywhere']),
                hidden: $hideUntilRoundStarts && ($revealsAt === null || $revealsAt > $now),
                status: RoundPuzzleStatus::of(
                    secretInRound: $hideUntilRoundStarts,
                    hideMode: $hideMode,
                    roundRevealsAt: $revealsAt,
                    puzzleHiddenUntil: $row['puzzle_hide_until'] !== null ? new DateTimeImmutable($row['puzzle_hide_until']) : null,
                    puzzleImageHiddenUntil: $row['puzzle_hide_image_until'] !== null ? new DateTimeImmutable($row['puzzle_hide_image_until']) : null,
                    now: $now,
                    anotherRoundHolds: self::bool($row['another_round_holds']),
                ),
                mayKeepHiddenEverywhere: self::bool($row['may_keep_hidden_everywhere']) && $hideUntilRoundStarts && ($revealsAt === null || $revealsAt > $now),
                // Shown on the event page so far: secret only before the round starts and while no other round shows it
                nameHeldByAnotherRound: self::bool($row['name_held_by_another_round']),
                mayBecomeSecret: $hideUntilRoundStarts === false
                    && new DateTimeImmutable($row['round_starts_at']) > $now
                    && self::bool($row['shown_by_another_round']) === false,
            );
        }, $data);
    }

    private static function bool(bool|string $value): bool
    {
        if (is_string($value)) {
            return $value === 't' || $value === '1' || $value === 'true';
        }

        return $value;
    }
}
