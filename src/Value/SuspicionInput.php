<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Everything SuspiciousTimeClassifier needs to judge one solo time. The scan builds it from
 * Query\GetSuspiciousTimeCandidates + Query\GetPlayerPaces, the form from the live prediction
 * (Services\SuspiciousTimes\SingleTimeSuspicionCheck). The evidence is added for raised times only.
 */
readonly final class SuspicionInput
{
    /**
     * Pair/team results carry no personal expectation (prediction, baseline and pace are null) - they are judged by
     * the community's slow floor only.
     */
    public function __construct(
        public int $piecesCount,
        public int $seconds,
        public PuzzlingType $puzzlingType,
        // The prediction made without knowledge of the solve (stored: predictable = true)
        public null|int $predictedSeconds,
        // player_baseline for the piece count
        public null|int $baselineSeconds,
        // puzzle_difficulty.difficulty_score when its confidence is not insufficient
        public null|float $difficultyScore,
        // The player's relative pace (GetPlayerPaces): median of their other solo results' pace ÷ the community median
        public null|float $paceFactor,
        public PaceReferences $references,
        public null|SuspicionEvidence $evidence = null,
        // A personal prediction: the time of the attempt it was built on (prediction_last_time_seconds)
        public null|int $previousAttemptSeconds = null,
        // An earlier attempt of the puzzle has a pending or marked slow case
        public bool $previousAttemptRaisedSlow = false,
    ) {
    }

    public function withEvidence(SuspicionEvidence $evidence): self
    {
        return new self(
            piecesCount: $this->piecesCount,
            seconds: $this->seconds,
            puzzlingType: $this->puzzlingType,
            predictedSeconds: $this->predictedSeconds,
            baselineSeconds: $this->baselineSeconds,
            difficultyScore: $this->difficultyScore,
            paceFactor: $this->paceFactor,
            references: $this->references,
            evidence: $evidence,
            previousAttemptSeconds: $this->previousAttemptSeconds,
            previousAttemptRaisedSlow: $this->previousAttemptRaisedSlow,
        );
    }

    /**
     * The same time judged as if it had no prediction - by the baseline, the pace or the community.
     */
    public function withoutPrediction(): self
    {
        return new self(
            piecesCount: $this->piecesCount,
            seconds: $this->seconds,
            puzzlingType: $this->puzzlingType,
            predictedSeconds: null,
            baselineSeconds: $this->baselineSeconds,
            difficultyScore: $this->difficultyScore,
            paceFactor: $this->paceFactor,
            references: $this->references,
            evidence: $this->evidence,
        );
    }

    /**
     * The community pace of the time's range and puzzling type.
     */
    public function reference(): null|PaceReference
    {
        return $this->references->for($this->piecesCount, $this->puzzlingType);
    }

    /**
     * The community solo pace of a piece count - what makes piece counts comparable for a player.
     */
    public function soloReference(int $piecesCount): null|PaceReference
    {
        return $this->references->for($piecesCount, PuzzlingType::Solo);
    }

    public function isSolo(): bool
    {
        return $this->puzzlingType === PuzzlingType::Solo;
    }
}
