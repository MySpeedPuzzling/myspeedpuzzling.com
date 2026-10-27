<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PeakMemory;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\SizeCurve;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * A candidate fit between its steps (CandidateModelFitter): the parameters fitted so far, the summary, and - once the
 * window is sampled - everything the remaining steps read, so the caller can release the attempt set, the engines and
 * the priors before them (CandidateModelRecalculator; the daily fit runs in a 512 MB container).
 */
final class CandidateFitProgress
{
    /** @var array<string, int|float|string> */
    public array $summary;
    public null|CandidateSamples $samples = null;
    /** The candidate's current size curve (its last fit's) - kept when this window cannot identify one */
    public null|SizeCurve $previousSizeCurve = null;
    /** @var list<array{0: int, 1: string, 2: float, 3: null|float, 4: int, 5: bool}> the window's first tries without the shown rules' pace, for the size curve: [attempt, prior type, prior, median, first tries, at an event] */
    public array $paceless = [];
    /** @var array<string, float> puzzle => its image estimate, for the size curve's own first tries (sample()) */
    public array $fewImageLog = [];
    /** @var array<string, float> puzzle => its candidate brand prior, for the size curve's own first tries (sample()) */
    public array $fewBrandLog = [];
    /** @var list<array{0: StatisticalPredictionInputs, 1: int, 2: bool, 3: array{0: string, 1: string, 2: float, 3: float, 4: null|float, 5: int}}> the window's unboxed first tries: [inputs, seconds, at an event, [player, puzzle, solved, tracked, brand prior, pieces]] */
    public array $unboxed = [];
    private float $clock;

    public function __construct(
        public CandidateModelParameters $parameters,
        public readonly CandidateFeatures $features,
        public readonly float $from,
        public readonly float $to,
    ) {
        $this->summary = [
            'window_from' => new DateTimeImmutable('@' . (int) $from)->format('Y-m-d'),
            'window_to' => new DateTimeImmutable('@' . (int) $to)->format('Y-m-d'),
            'memory_start_mb' => round(memory_get_usage() / 1048576),
            'resident_peak_start_mb' => PeakMemory::residentMegabytes() ?? '-',
        ];
        $this->clock = microtime(true);
    }

    /**
     * The step's time and memory into the summary; the freed memory handed back before the next step builds its own.
     */
    public function lap(string $step): void
    {
        $this->summary['seconds_' . $step] = round(microtime(true) - $this->clock, 2);
        $this->summary['memory_' . $step . '_mb'] = round(memory_get_usage() / 1048576);
        $this->summary['memory_peak_' . $step . '_mb'] = round(memory_get_peak_usage() / 1048576);
        $this->summary['memory_real_peak_' . $step . '_mb'] = round(memory_get_peak_usage(true) / 1048576);
        $this->summary['resident_peak_' . $step . '_mb'] = PeakMemory::residentMegabytes() ?? '-';
        memory_reset_peak_usage();
        gc_mem_caches();
        $this->clock = microtime(true);
    }

    /**
     * @param array<string, int|float|string> $summary
     */
    public function add(array $summary): void
    {
        $this->summary = [...$this->summary, ...$summary];
    }
}
