<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * What every time's stored pair/team predictions were made for (group_prediction_input) - the daily run predicts a
 * time again only when its fingerprint changed (docs/features/puzzle-intelligence/prediction-history.md "Pair and team
 * predictions").
 */
readonly final class GetGroupPredictionInputs
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: bool}> time id => fingerprint, written live
     */
    public function all(): array
    {
        $inputs = [];

        foreach ($this->connection->iterateNumeric('SELECT time_id, fingerprint, live FROM group_prediction_input') as $row) {
            /** @var array{0: string, 1: string, 2: bool|int|string} $row */
            $inputs[$row[0]] = [$row[1], $row[2] === true || $row[2] === 1 || $row[2] === '1' || $row[2] === 't'];
        }

        return $inputs;
    }
}
