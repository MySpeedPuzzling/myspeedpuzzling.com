<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Results\CompetitionDisplayOverview;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;

/**
 * The organiser's view of an event's displays (docs/features/competitions-management/toolkit/D-displays-overlays.md
 * §8.1) - organiser tooling only, behind COMPETITION_TOOLKIT_MANAGE.
 */
readonly final class GetCompetitionDisplays
{
    private const string COLUMNS = 'id, competition_id, kind, name, token, locale, follow_mode, pinned_round_id, follow_round_ids, design_version, updated_at';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionDisplayOverview> in the order they were added
     */
    public function ofCompetition(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $rows = $this->database->executeQuery(
            'SELECT ' . self::COLUMNS . ' FROM competition_display WHERE competition_id = :competitionId ORDER BY created_at, id',
            ['competitionId' => $competitionId],
        )->fetchAllAssociative();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @throws CompetitionDisplayNotFound
     */
    public function byId(string $displayId): CompetitionDisplayOverview
    {
        if (Uuid::isValid($displayId) === false) {
            throw new CompetitionDisplayNotFound();
        }

        $row = $this->database->executeQuery(
            'SELECT ' . self::COLUMNS . ' FROM competition_display WHERE id = :id',
            ['id' => $displayId],
        )->fetchAssociative();

        if ($row === false) {
            throw new CompetitionDisplayNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CompetitionDisplayOverview
    {
        /**
         * @var array{
         *     id: string,
         *     competition_id: string,
         *     kind: string,
         *     name: string,
         *     token: string,
         *     locale: string,
         *     follow_mode: string,
         *     pinned_round_id: null|string,
         *     follow_round_ids: null|string,
         *     design_version: int,
         *     updated_at: string,
         * } $row
         */
        $followRoundIds = null;

        if ($row['follow_round_ids'] !== null) {
            $decoded = json_decode($row['follow_round_ids'], true);
            $followRoundIds = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
        }

        return new CompetitionDisplayOverview(
            id: $row['id'],
            competitionId: $row['competition_id'],
            kind: DisplayKind::from($row['kind']),
            name: $row['name'],
            token: $row['token'],
            locale: $row['locale'],
            followMode: DisplayFollowMode::from($row['follow_mode']),
            pinnedRoundId: $row['pinned_round_id'],
            followRoundIds: $followRoundIds,
            designVersion: $row['design_version'],
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }
}
