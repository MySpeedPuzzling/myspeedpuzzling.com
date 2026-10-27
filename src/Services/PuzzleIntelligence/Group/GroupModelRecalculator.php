<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\GroupPredictionFit;
use SpeedPuzzling\Web\Query\GetGroupPredictionInputs;
use SpeedPuzzling\Web\Repository\GroupPredictionFitRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PeakMemory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;

/**
 * The pair/team model's daily job (`myspeedpuzzling:fit-group-prediction-model`, its own process - nothing of it runs in
 * the shown model's jobs or on the add-time path): every pair/team result loaded once (GroupResultSetLoader), then
 * 1. with rows on: the times without rows (or with a changed fingerprint) predicted with **yesterday's** fit, which has
 *    not seen the ones added since - those are the live, out-of-sample rows;
 * 2. the model refitted on everything (group_prediction_fit);
 * 3. with rows on and no fit before (the first fill) or $recompute: every reconstructed row made again with the new fit.
 * Reads the shown model's τ, writes only its own tables and its own predictors' rows.
 */
readonly final class GroupModelRecalculator
{
    public function __construct(
        private GroupResultSetLoader $loader,
        private GroupPredictionRowsWriter $writer,
        private GetGroupPredictionInputs $getGroupPredictionInputs,
        private GroupPredictionFitRepository $fitRepository,
        private PredictionModelParametersProvider $parametersProvider,
        private GroupPredictionModel $model = new GroupPredictionModel(),
    ) {
    }

    /**
     * @return array<string, int|float|string>
     */
    public function run(DateTimeImmutable $now, bool $writeRows, bool $recompute): array
    {
        $startedAt = microtime(true);
        $set = $this->loader->load($now);
        $summary = ['results' => $set->count(), 'predictable' => count(array_filter($set->notPredictable, static fn (int $reason): bool => $reason === 0)), 'load_seconds' => round(microtime(true) - $startedAt, 1), 'resident_after_load_mb' => PeakMemory::residentMegabytes() ?? '-'];
        $fit = $this->fitRepository->find(GroupPredictionModel::MODEL_VERSION);
        $stored = $writeRows ? $this->getGroupPredictionInputs->all() : [];
        $written = 0;

        if ($writeRows && $fit !== null) {
            $written += $this->writer->write($set, $this->model->replay($set, $fit->parameters()), $stored, GroupPredictionRowsWriter::CHANGED, $fit->fittedAt, $now);
        }

        $parameters = $this->model->fit($set, (float) $now->getTimestamp(), $this->parametersProvider->current()->tau);

        if ($fit === null) {
            $fit = new GroupPredictionFit(GroupPredictionModel::MODEL_VERSION, $now, $parameters->toArray());
            $this->fitRepository->save($fit);
            $firstFit = true;
        } else {
            $fit->refit($parameters, $now);
            $firstFit = false;
        }

        if ($writeRows && ($firstFit || $recompute)) {
            // What step 1 wrote is in $stored now as written (live where it was) - read it again
            $written += $this->writer->write($set, $this->model->replay($set, $parameters), $this->getGroupPredictionInputs->all(), GroupPredictionRowsWriter::RECONSTRUCTED, $now, $now);
        }

        $forgotten = 0;

        if ($writeRows) {
            $current = array_flip($set->id);
            $forgotten = $this->writer->forget(array_values(array_filter(array_keys($stored), static fn (string $timeId): bool => !isset($current[$timeId]))));
        }

        return $summary + $parameters->summary + [
            'times_written' => $written,
            'times_forgotten' => $forgotten,
            'seconds' => round(microtime(true) - $startedAt, 1),
            'resident_peak_mb' => PeakMemory::residentMegabytes() ?? '-',
            'php_peak_mb' => round(memory_get_peak_usage(true) / 1048576),
        ];
    }
}
