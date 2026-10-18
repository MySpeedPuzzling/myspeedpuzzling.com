<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\PuzzleDifficultyEstimateModelResult;

/**
 * The recorded runs of the image pipeline's difficulty model (PuzzleDifficultyEstimateModel): the current one = the
 * latest received, optionally of one model name.
 */
readonly final class GetPuzzleDifficultyEstimateModel
{
    private const string COLUMNS = 'id, model, model_version, training_size, holdout_spearman, evaluation, created_at, received_at';

    public function __construct(
        private Connection $database,
    ) {
    }

    public function current(null|string $model = null): null|PuzzleDifficultyEstimateModelResult
    {
        $columns = self::COLUMNS;
        $condition = $model !== null ? 'WHERE model = :model' : '';
        $parameters = $model !== null ? ['model' => $model] : [];

        // received_at ties (two records within one clock tick) fall back to the uuid7 id - the later insert
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT {$columns}
FROM puzzle_difficulty_estimate_model
{$condition}
ORDER BY received_at DESC, id DESC
LIMIT 1
SQL,
            $parameters,
        );

        return $row !== false ? PuzzleDifficultyEstimateModelResult::fromDatabaseRow($row) : null;
    }

    public function byId(string $id): null|PuzzleDifficultyEstimateModelResult
    {
        if (Uuid::isValid($id) === false) {
            return null;
        }

        $columns = self::COLUMNS;

        $row = $this->database->fetchAssociative(
            "SELECT {$columns} FROM puzzle_difficulty_estimate_model WHERE id = :id",
            ['id' => strtolower($id)],
        );

        return $row !== false ? PuzzleDifficultyEstimateModelResult::fromDatabaseRow($row) : null;
    }
}
