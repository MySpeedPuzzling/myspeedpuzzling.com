<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * The standings of an event (competition toolkit A6) for the organiser's pages - drafts and publishing are the
 * caller's business (every reader here is behind COMPETITION_TOOLKIT_MANAGE or COMPETITION_RESULTS_ENTRY).
 */
readonly final class GetCompetitionStandings
{
    private const string SELECT = <<<SQL
SELECT id, competition_id, name, slug, category, mode, round_ids, unfinished_penalty_seconds_per_piece, order_rule, missing_rule, position, published_at
FROM competition_standing
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionStandingOverview> by position, then creation
     */
    public function ofCompetition(string $competitionId): array
    {
        if (!Uuid::isValid($competitionId)) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            self::SELECT . "\nWHERE competition_id = :competitionId\nORDER BY position, created_at, id",
            ['competitionId' => $competitionId],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @throws CompetitionStandingNotFound
     */
    public function byId(string $standingId): CompetitionStandingOverview
    {
        if (!Uuid::isValid($standingId)) {
            throw new CompetitionStandingNotFound();
        }

        $row = $this->database->fetchAssociative(self::SELECT . "\nWHERE id = :id", ['id' => $standingId]);

        if ($row === false) {
            throw new CompetitionStandingNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CompetitionStandingOverview
    {
        /** @var array{id: string, competition_id: string, name: string, slug: string, category: string, mode: string, round_ids: string, unfinished_penalty_seconds_per_piece: int, order_rule: string, missing_rule: string, position: int, published_at: null|string} $row */
        $roundIds = json_decode($row['round_ids'], true, flags: JSON_THROW_ON_ERROR);

        return new CompetitionStandingOverview(
            id: $row['id'],
            competitionId: $row['competition_id'],
            name: $row['name'],
            slug: $row['slug'],
            category: RoundCategory::from($row['category']),
            mode: StandingMode::from($row['mode']),
            roundIds: is_array($roundIds) ? array_values(array_map(static fn (mixed $id): string => strtolower(is_string($id) ? $id : ''), $roundIds)) : [],
            rules: new StandingRules(
                $row['unfinished_penalty_seconds_per_piece'],
                StandingOrder::from($row['order_rule']),
                StandingMissing::from($row['missing_rule']),
            ),
            position: $row['position'],
            publishedAt: $row['published_at'] !== null ? new DateTimeImmutable($row['published_at']) : null,
        );
    }
}
