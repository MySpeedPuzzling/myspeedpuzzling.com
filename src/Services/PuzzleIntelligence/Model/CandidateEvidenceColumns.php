<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\CandidateEvidence;
use SplFixedArray;

/**
 * The as-of evidence of the attempts CandidateEngine::compute() covers, one fixed-size column per sum, by slot (a PHP
 * list of 150k values holds 262k entries; the fit runs in a 512 MB container). Its own object so the fit's samples keep
 * it after the engine and the attempt set are released (CandidateModelFitter::finish()).
 */
final class CandidateEvidenceColumns
{
    /** @var SplFixedArray<int> */
    private SplFixedArray $k;
    /** @var SplFixedArray<float> */
    private SplFixedArray $weightSum;
    /** @var SplFixedArray<float> */
    private SplFixedArray $weightedLogSum;
    /** @var SplFixedArray<float> */
    private SplFixedArray $logSum;
    /** @var SplFixedArray<float> */
    private SplFixedArray $logSquareSum;
    /** @var SplFixedArray<float> */
    private SplFixedArray $noiseSum;

    public function __construct(int $size)
    {
        $this->k = new SplFixedArray($size);
        $this->weightSum = new SplFixedArray($size);
        $this->weightedLogSum = new SplFixedArray($size);
        $this->logSum = new SplFixedArray($size);
        $this->logSquareSum = new SplFixedArray($size);
        $this->noiseSum = new SplFixedArray($size);
    }

    /**
     * @param array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float} $evidence [k, Σw, Σw·log, Σlog, Σlog², Σnoise]
     */
    public function set(int $slot, array $evidence): void
    {
        [$this->k[$slot], $this->weightSum[$slot], $this->weightedLogSum[$slot], $this->logSum[$slot], $this->logSquareSum[$slot], $this->noiseSum[$slot]] = $evidence;
    }

    public function at(int $slot): CandidateEvidence
    {
        return new CandidateEvidence(
            (int) $this->k[$slot],
            (float) $this->weightSum[$slot],
            (float) $this->weightedLogSum[$slot],
            (float) $this->logSum[$slot],
            (float) $this->logSquareSum[$slot],
            (float) $this->noiseSum[$slot],
        );
    }

    public function kAt(int $slot): int
    {
        return (int) $this->k[$slot];
    }
}
