<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Query\GetTimesAwaitingImagePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SolvingTimePredictionRecordRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictionsSideBySide;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BackfillSolvingTimePredictionsHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PredictionReconstructor $predictionReconstructor,
        private SolvingTimePredictionRecordRepository $recordRepository,
        private ClockInterface $clock,
        private PredictionModelParametersProvider $parametersProvider,
        private GetTimesAwaitingImagePredictions $getTimesAwaitingImagePredictions,
        // PREDICTION_CANDIDATE_ROWS: the candidate model's rows (v4, never shown) only while on, or asked for by the message
        #[Autowire(param: 'predictionCandidateRows')]
        private bool $candidateRows = false,
        // PREDICTION_SHOWN_MODEL: v7 = the shown prediction is the candidate's (its rows are then always written)
        #[Autowire(param: 'predictionShownModel')]
        private string $shownModel = 'v3',
    ) {
    }

    /**
     * The shown prediction (puzzle_solving_time) is written for pending and re-evaluated times - and, asked for, over
     * every reconstructed one from before the current fit; never over a live one, that is history. The side-by-side
     * row of the current model is written wherever it is missing, and replaced when the time was re-evaluated (or,
     * asked for, when it is a reconstruction from before the current fit).
     *
     * The image / blend rows of a first try (Insights plan §5.4) follow the same rules, from the puzzle's out-of-fold
     * estimate only (PredictionReconstructor): written once the puzzle has one, a live row written without an estimate
     * replaced by the reconstruction, a re-evaluated time that is no first try any more (or has no out-of-fold estimate
     * now) loses its rows; with recomputeImage also reconstructions older than the estimate. A time the work list names
     * as a first try that reconstructs as a repeat (its stored statistical row is older than a merge or a back-dated
     * earlier solve) gets reconstructed "not predictable" rows without an estimate - done, never listed again.
     *
     * The candidate model's (v4, never shown) row of every solo time once it is fitted and its rows are switched on
     * (PREDICTION_CANDIDATE_ROWS, or the message asks): written where missing, replaced
     * when the time was re-evaluated or, asked for, when it is a reconstruction from before the candidate's fit.
     *
     * Before the model's first fit (prediction_model_fit) no side-by-side rows at all: they would hold the defaults'
     * predictions without priors under the current model version, as if fitted - the shown prediction of a pending
     * time is still written (and replaced by the post-deploy `--recompute-reconstructed` run).
     *
     * @return int how many times got something written (shown or side by side)
     */
    public function __invoke(BackfillSolvingTimePredictions $message): int
    {
        $fittedAt = $this->parametersProvider->fittedAt();
        $fitted = $fittedAt !== null;
        $candidateShown = ShownPredictionModel::fromSetting($this->shownModel)->isCandidate();
        // The candidate model: its rows once it is fitted, with the statistical model's - always while it is the shown one
        $candidateFittedAt = $fitted && ($this->candidateRows || $message->candidateRows || $candidateShown) ? $this->parametersProvider->candidateFittedAt() : null;
        $recomputeCandidateBefore = $message->recomputeReconstructed ? $candidateFittedAt : null;
        // Only reconstructions from before the current fit: a run that stopped continues where it was
        $recomputeBefore = $message->recomputeReconstructed ? $fittedAt : null;
        // The shown prediction: the candidate's while it is the shown model and fitted, else the statistical model's - a
        // recompute replaces a reconstructed one from before that model's fit or made by the other model
        $shown = ShownPredictionModel::effective($candidateShown && $candidateFittedAt !== null);
        $recomputeShownBefore = $message->recomputeReconstructed ? ($shown->isCandidate() ? $candidateFittedAt : $fittedAt) : null;
        $imageTimeIds = $fitted ? $this->getTimesAwaitingImagePredictions->ofPlayer($message->playerId, $message->recomputeImage) : [];

        // Locked before anything is read, so the reconstruction sees every edit committed before it
        $times = $this->puzzleSolvingTimeRepository->findForPredictionOfPlayer(
            $message->playerId,
            $message->reevaluateTimeIds,
            $recomputeBefore,
            withSideRows: $fitted,
            imageTimeIds: $imageTimeIds,
            withCandidateRows: $candidateFittedAt !== null,
            recomputeCandidateBefore: $recomputeCandidateBefore,
            recomputeShownBefore: $recomputeShownBefore,
            shownModelVersion: $shown->modelVersion(),
        );

        if ($times === []) {
            return 0;
        }

        $timeIds = array_map(static fn (PuzzleSolvingTime $time): string => $time->id->toString(), $times);
        $predictions = $this->predictionReconstructor->reconstructSideBySide($message->playerId, $timeIds, withCandidate: $candidateFittedAt !== null);
        $records = $fitted
            ? $this->recordRepository->forTimesByPredictor($timeIds, [PredictorKind::Statistical, PredictorKind::Image, PredictorKind::Blend], TimePredictionCalculator::MODEL_VERSION)
            : [];
        $candidateRecords = $candidateFittedAt !== null
            ? $this->recordRepository->forTimesByPredictor($timeIds, [PredictorKind::Candidate], CandidatePredictionCalculator::MODEL_VERSION)[PredictorKind::Candidate->value] ?? []
            : [];
        $reevaluate = array_flip($message->reevaluateTimeIds);
        $awaitingImage = array_flip($imageTimeIds);

        $now = $this->clock->now();
        $recorded = 0;

        foreach ($times as $time) {
            $timeId = $time->id->toString();
            $sideBySide = $predictions[$timeId] ?? null;

            if ($sideBySide === null) {
                continue;
            }

            $prediction = $sideBySide->statistical;
            $reevaluated = isset($reevaluate[$timeId]) || $time->isPredictionPending();
            $written = false;
            // The shown one: the candidate's while it is shown (the statistical model's when its pass failed - it says so
            // by its version, and the next recompute replaces it)
            $shownPrediction = $shown->isCandidate() && $sideBySide->candidate !== null ? $sideBySide->candidate : $prediction;

            if (
                $reevaluated
                || self::isReconstructionBefore($time->predictionSource, $time->predictionComputedAt, $recomputeShownBefore)
                || ($recomputeShownBefore !== null && $time->predictionSource === TimePredictionSource::Reconstructed && $time->predictionModelVersion !== $shown->modelVersion())
            ) {
                $time->recordPrediction($shownPrediction->forDisplay(), $now);
                $written = true;
            }

            if ($fitted) {
                $record = $records[PredictorKind::Statistical->value][$timeId] ?? null;

                if ($record === null) {
                    $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION, $prediction, $now));
                    $written = true;
                } elseif ($reevaluated || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeBefore)) {
                    $record->record($prediction, $now);
                    $written = true;
                }

                $written = $this->recordImageRows($time, $sideBySide, $records, $reevaluated, isset($awaitingImage[$timeId]), $recomputeBefore, $now) || $written;
            }

            // The candidate's row: written where missing, replaced like the statistical one (re-evaluated, or a
            // reconstruction from before the candidate's fit when recomputing)
            if ($candidateFittedAt !== null && $sideBySide->candidate !== null) {
                $record = $candidateRecords[$timeId] ?? null;
                $estimate = $sideBySide->firstTry ? $sideBySide->imageEstimate : null;

                if ($record === null) {
                    $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, PredictorKind::Candidate, CandidatePredictionCalculator::MODEL_VERSION, $sideBySide->candidate, $now, $estimate));
                    $written = true;
                } elseif ($reevaluated || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeCandidateBefore)) {
                    $record->record($sideBySide->candidate, $now, $estimate);
                    $written = true;
                }
            }

            if ($written) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * @param array<string, array<string, SolvingTimePredictionRecord>> $records predictor => time id => row
     * @param bool $reevaluated pending or named (an edit)
     * @param bool $awaiting listed by GetTimesAwaitingImagePredictions (missing, written live without an estimate, stale)
     * @return bool whether a row was written or removed
     */
    private function recordImageRows(
        PuzzleSolvingTime $time,
        PredictionsSideBySide $sideBySide,
        array $records,
        bool $reevaluated,
        bool $awaiting,
        null|DateTimeImmutable $recomputeBefore,
        DateTimeImmutable $now,
    ): bool {
        $timeId = $time->id->toString();
        $written = false;
        $rows = [
            [PredictorKind::Image, $sideBySide->image],
            [PredictorKind::Blend, $sideBySide->blend],
        ];

        foreach ($rows as [$predictor, $prediction]) {
            $record = $records[$predictor->value][$timeId] ?? null;

            if ($prediction === null) {
                if ($awaiting) {
                    // Listed as a first try (its stored statistical row says so) but it reconstructs as a repeat now -
                    // a merge or an earlier solve back-dated in front of it (docs/TODO.md): a reconstructed "not
                    // predictable" row without an estimate closes it, or the work list would name it on every run
                    $closed = SolvingTimePrediction::notPredictable(TimePredictionSource::Reconstructed);

                    if ($record === null) {
                        $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, $predictor, TimePredictionCalculator::MODEL_VERSION, $closed, $now));
                    } else {
                        $record->record($closed, $now);
                    }

                    $written = true;
                } elseif ($record !== null && $reevaluated) {
                    // No image prediction for this time now (a repeat, or no out-of-fold estimate): an edit made what
                    // was there obsolete; otherwise the row stays (a live one is history)
                    $this->recordRepository->delete($record);
                    $written = true;
                }

                continue;
            }

            if ($record === null) {
                $this->recordRepository->save(new SolvingTimePredictionRecord(Uuid::uuid7(), $time, $predictor, TimePredictionCalculator::MODEL_VERSION, $prediction, $now, $sideBySide->imageEstimate));
                $written = true;
            } elseif ($reevaluated || $awaiting || self::isReconstructionBefore($record->source, $record->computedAt, $recomputeBefore)) {
                $record->record($prediction, $now, $sideBySide->imageEstimate);
                $written = true;
            }
        }

        return $written;
    }

    private static function isReconstructionBefore(null|TimePredictionSource $source, null|DateTimeImmutable $computedAt, null|DateTimeImmutable $fittedAt): bool
    {
        return $fittedAt !== null
            && $source === TimePredictionSource::Reconstructed
            && $computedAt !== null
            && $computedAt <= $fittedAt;
    }
}
