<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;

/**
 * The first tries the candidate model is fitted on (CandidateModelFitter), column by column: the target
 * ln(seconds) - ln(pace) - drift and what the posterior, the offsets and the window keys need. Only what is the
 * sample's own is stored - the evidence, the shown median, the flags and the experience are read from the engine by the
 * attempt (the daily fit runs in 512 MB).
 */
final class CandidateSamples
{
    /** @var array<int, int> */
    public array $attempt = [];
    /** @var array<int, float> */
    public array $target = [];
    /** @var array<int, string> */
    public array $priorType = [];
    /** @var array<int, float> */
    public array $priorLog = [];
    /** @var array<int, PiecesGroup> */
    public array $group = [];
    /** @var array<int, PaceSource> */
    public array $paceSource = [];
    /** @var array<int, float> */
    public array $spread = [];

    private int $count = 0;

    /**
     * @param int $capacity at most this many samples - the columns are allocated once at that size (a list grown one by
     *                      one holds up to twice its size)
     */
    public function __construct(
        private readonly CandidateEngine $candidate,
        int $capacity,
    ) {
        $capacity = max($capacity, 1);
        $this->attempt = array_fill(0, $capacity, 0);
        $this->target = array_fill(0, $capacity, 0.0);
        $this->priorType = array_fill(0, $capacity, '');
        $this->priorLog = array_fill(0, $capacity, 0.0);
        $this->group = array_fill(0, $capacity, PiecesGroup::FiveHundred);
        $this->paceSource = array_fill(0, $capacity, PaceSource::Own);
        $this->spread = array_fill(0, $capacity, 0.0);
    }

    public function add(int $attempt, float $target, string $priorType, float $priorLog, PiecesGroup $group, PaceSource $paceSource, float $spread): void
    {
        $s = $this->count++;
        $this->attempt[$s] = $attempt;
        $this->target[$s] = $target;
        $this->priorType[$s] = $priorType;
        $this->priorLog[$s] = $priorLog;
        $this->group[$s] = $group;
        $this->paceSource[$s] = $paceSource;
        $this->spread[$s] = $spread;
    }

    /**
     * The columns cut to the samples added, one at a time.
     */
    public function finish(): void
    {
        $this->attempt = array_slice($this->attempt, 0, $this->count);
        $this->target = array_slice($this->target, 0, $this->count);
        $this->priorType = array_slice($this->priorType, 0, $this->count);
        $this->priorLog = array_slice($this->priorLog, 0, $this->count);
        $this->group = array_slice($this->group, 0, $this->count);
        $this->paceSource = array_slice($this->paceSource, 0, $this->count);
        $this->spread = array_slice($this->spread, 0, $this->count);
    }

    public function count(): int
    {
        return $this->count;
    }

    public function evidence(int $s): CandidateEvidence
    {
        return $this->candidate->evidenceFor($this->attempt[$s]);
    }

    public function k(int $s): int
    {
        return $this->candidate->kFor($this->attempt[$s]);
    }

    /**
     * The shown model's median (log) from 5 indices on, else null.
     */
    public function median(int $s): null|float
    {
        $i = $this->attempt[$s];
        $score = $this->candidate->engine->score[$i] ?? null;

        return $this->candidate->engine->k[$i] >= TimePredictionCalculator::RATED_INDICES && $score !== null && $score > 0.0 ? log($score) : null;
    }

    public function competition(int $s): bool
    {
        return ($this->candidate->flags->of($this->attempt[$s]) & AttemptFlags::COMPETITION) !== 0;
    }

    public function firstTries(int $s): int
    {
        return $this->candidate->experience()[$this->attempt[$s]] ?? 0;
    }
}
