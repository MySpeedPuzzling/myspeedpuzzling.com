<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PiecesGroup;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Parity guards of the prediction model run on a fitted model, so its fitted parts are not null: a real fit
 * (FitPredictionModel - the prior groups and shares, tau, the drift with them), every brand × piece-count group then
 * given 40 more indices of mean log 0.05 (the fixtures hold too few first attempts for a brand prior - 5 other
 * indices) and every puzzle's finished prior written again from those sums the way the fit writes it
 * (difficulty_prior_puzzle.prior_log through the prior chain, and its copy on puzzle_difficulty), and on request a thin-size rule for every size group
 * (the fixtures are too few for the fit to keep one).
 */
trait FitsThePredictionModel
{
    private function fitPredictionModel(): void
    {
        $container = self::getContainer();

        /** @var MessageBusInterface $messageBus */
        $messageBus = $container->get(MessageBusInterface::class);
        $messageBus->dispatch(new FitPredictionModel());

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $connection->executeStatement("
            INSERT INTO difficulty_prior_group (manufacturer_id, pieces_count, index_count, sum_log_indices, computed_at)
            SELECT DISTINCT p.manufacturer_id, p.pieces_count, 40, 2.0, NOW()
            FROM puzzle p
            WHERE p.manufacturer_id IS NOT NULL
            ON CONFLICT (manufacturer_id, pieces_count) DO UPDATE SET
                index_count = difficulty_prior_group.index_count + 40,
                sum_log_indices = difficulty_prior_group.sum_log_indices + 2.0
        ");

        /** @var TimePredictionCalculator $calculator */
        $calculator = $container->get(TimePredictionCalculator::class);
        $rows = $connection->fetchAllAssociative('
            SELECT p.id, p.pieces_count, p.manufacturer_id, g.sum_log_indices AS group_sum, g.index_count AS group_count,
                dpp.sum_log_indices AS own_sum, dpp.index_count AS own_count
            FROM puzzle p
            LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND ' . StatisticalInputsSql::NOT_SECRET . '
            LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id
        ');

        foreach ($rows as $row) {
            /** @var array{id: string, pieces_count: int|string, manufacturer_id: null|string, group_sum: null|float|string, group_count: null|int|string, own_sum: null|float|string, own_count: null|int|string} $row */
            $prior = $calculator->prior(new PriorContext(
                puzzleId: $row['id'],
                piecesCount: (int) $row['pieces_count'],
                manufacturerId: $row['manufacturer_id'],
                brandPiecesGroupSumLog: (float) ($row['group_sum'] ?? 0.0),
                brandPiecesGroupCount: (int) ($row['group_count'] ?? 0),
                brandPiecesOwnSumLog: (float) ($row['own_sum'] ?? 0.0),
                brandPiecesOwnCount: (int) ($row['own_count'] ?? 0),
            ))?->logDifficulty;

            if ($prior === null && $row['own_count'] === null) {
                continue;
            }

            $connection->executeStatement(
                'INSERT INTO difficulty_prior_puzzle (puzzle_id, index_count, sum_log_indices, prior_log, computed_at) VALUES (:id, 0, 0, CAST(:prior AS double precision), NOW())
                ON CONFLICT (puzzle_id) DO UPDATE SET prior_log = EXCLUDED.prior_log',
                // The exact double, like the fit's array literal (a plain string cast rounds to 14 digits)
                ['id' => $row['id'], 'prior' => $prior !== null ? var_export($prior, true) : null],
            );
        }

        /** @var PredictionModelRecalculator $recalculator */
        $recalculator = $container->get(PredictionModelRecalculator::class);
        $recalculator->syncDifficultyPriors();
    }

    private function switchOnThinSizeRules(): void
    {
        $container = self::getContainer();

        /** @var PredictionModelParametersProvider $provider */
        $provider = $container->get(PredictionModelParametersProvider::class);
        $rule = ['alpha' => 1.4, 'max_own_solves' => PredictionModelFitter::ALWAYS];
        $parameters = $provider->current()->with(thinSizes: [
            PiecesGroup::UpTo300->value => $rule,
            PiecesGroup::Thousand->value => $rule,
            PiecesGroup::From1500->value => ['alpha' => 1.5, 'max_own_solves' => PredictionModelFitter::ALWAYS],
            PiecesGroup::Odd->value => $rule,
        ]);

        self::assertNotNull($parameters->fittedAt, 'Fitted first (fitPredictionModel())');

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $connection->executeStatement(
            'UPDATE prediction_model_fit SET parameters = CAST(:parameters AS jsonb) WHERE model_version = :version',
            ['version' => TimePredictionCalculator::MODEL_VERSION, 'parameters' => json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
        );
    }
}
