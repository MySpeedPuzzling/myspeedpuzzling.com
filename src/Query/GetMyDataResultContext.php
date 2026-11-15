<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * What the row sheet of "My data" says about one of the player's own results beside its fields
 * (docs/features/my-data.md "Editing one row"): how it was added, whether it needs verification (suspicious - it is
 * the player's own result, shown with its label), and the spreadsheet import it came from - named to the importer
 * only, never to another person of the result. A result without a tracker (an imported event result) is read like
 * any other: it has no import of a player.
 */
readonly final class GetMyDataResultContext
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{source: null|SolvingTimeSource, needsVerification: bool, importId: null|string}
     */
    public function ofResult(string $solvingTimeId, string $viewerPlayerId): array
    {
        $nothing = ['source' => null, 'needsVerification' => false, 'importId' => null];

        if (Uuid::isValid($solvingTimeId) === false || Uuid::isValid($viewerPlayerId) === false) {
            return $nothing;
        }

        $query = <<<SQL
SELECT
    pst.created_via,
    pst.suspicious,
    (
        SELECT import_row.import_id
        FROM data_import_row import_row
        INNER JOIN data_import importing ON importing.id = import_row.import_id AND importing.player_id = :viewerPlayerId
        WHERE import_row.solving_time_id = pst.id
            AND import_row.result_made
            AND import_row.state = 'added'
        LIMIT 1
    ) AS import_id
FROM puzzle_solving_time pst
WHERE pst.id = :solvingTimeId
SQL;

        /** @var false|array{created_via: null|string, suspicious: bool, import_id: null|string} $row */
        $row = $this->database->executeQuery($query, [
            'solvingTimeId' => $solvingTimeId,
            'viewerPlayerId' => $viewerPlayerId,
        ])->fetchAssociative();

        if ($row === false) {
            return $nothing;
        }

        return [
            'source' => SolvingTimeSource::tryFrom($row['created_via'] ?? ''),
            'needsVerification' => $row['suspicious'],
            'importId' => $row['created_via'] === SolvingTimeSource::SpreadsheetImport->value ? $row['import_id'] : null,
        ];
    }
}
