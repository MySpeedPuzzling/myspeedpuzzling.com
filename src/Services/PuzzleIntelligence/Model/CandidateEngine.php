<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * The candidate model's (v4) puzzle evidence replayed over every qualifying first attempt, like PredictionEngine does
 * for the shown model: each difficulty index once more as the candidate reads it - drift-adjusted (the solver's drift
 * before the solve), with its noise variance from the solver's spread, qualifying first attempts and the attempt's
 * flags (CandidateModelParameters::indexVariance()) - and per attempt the evidence known strictly before it (an index
 * from the moment the shown model knows it, FirstAttemptSet::knownFrom()).
 *
 * Needs PredictionEngine::computeDrift() first (the solvers' drift and spread as of their solves).
 */
final class CandidateEngine
{
    // Plain lists over every attempt (packed arrays - the batch runs in 512 MB), NAN = no index
    /** @var array<int, float> ln(index) per attempt */
    private array $rawLog;
    /** @var array<int, float> the solver's drift before the solve (0 without a direct pace) */
    private array $solverDrift;
    /** @var array<int, float> */
    private array $solverSpread;
    /** @var array<int, int> qualifying first attempts of the solver at the size before the solve (0 = the pace was not their own direct one) */
    private array $solverQualifying;

    /** @var null|array<int, int> the player's qualifying first attempts (every size) before each attempt - experience() */
    private null|array $experience = null;
    /** @var null|array<string, list<string>> player => their pair keys (experienceAt()) */
    private null|array $pairsByPlayer = null;

    // As-of evidence of the attempts solved from compute()'s moment on, by slot (packed - the fit runs in 512 MB)
    /** @var array<int, int> attempt => slot */
    private array $slot = [];
    /** @var array<int, int> */
    private array $k = [];
    /** @var array<int, float> */
    private array $weightSum = [];
    /** @var array<int, float> */
    private array $weightedLogSum = [];
    /** @var array<int, float> */
    private array $logSum = [];
    /** @var array<int, float> */
    private array $logSquareSum = [];
    /** @var array<int, float> */
    private array $noiseSum = [];

    public function __construct(
        public readonly PredictionEngine $engine,
        public readonly AttemptFlags $flags,
    ) {
        $set = $engine->set;
        $count = $set->count();
        $this->rawLog = array_fill(0, $count, NAN);
        $this->solverDrift = array_fill(0, $count, 0.0);
        $this->solverSpread = array_fill(0, $count, PredictionModelParameters::GLOBAL_SPREAD);
        $this->solverQualifying = array_fill(0, $count, 0);

        foreach ($set->index as $i => $index) {
            if ($index === null) {
                continue;
            }

            $player = $set->player[$i];
            $pieces = $set->pieces[$i];
            $direct = $engine->timeline->directRowBefore($player, $pieces, $set->solved[$i], $set->tracked[$i]) !== null;
            [$drift, $spread] = $direct ? $engine->stateBefore($player . '|' . $pieces, $i) : [null, null];

            $this->rawLog[$i] = log($index);
            $this->solverDrift[$i] = $drift ?? 0.0;
            $this->solverSpread[$i] = $spread ?? PredictionModelParameters::GLOBAL_SPREAD;
            $this->solverQualifying[$i] = $direct ? $set->position[$i] - 1 : 0;
        }
    }

    /**
     * The candidate's reading of the attempt's index: [log, noise variance, weight] - null without an index.
     *
     * @return null|array{0: float, 1: float, 2: float}
     */
    public function indexTerms(int $i, CandidateModelParameters $parameters): null|array
    {
        if (is_nan($this->rawLog[$i] ?? NAN)) {
            return null;
        }

        return self::term($this->rawLog[$i], $this->solverDrift[$i], $this->solverSpread[$i], $this->solverQualifying[$i], $this->flags->of($i), $parameters);
    }

    /**
     * One index as the candidate reads it - shared with PredictionReconstructor: [log, noise variance, weight].
     *
     * @param float $rawLog ln(time ÷ the solver's pace)
     * @param float $drift the solver's drift before the solve (0 without a direct pace)
     * @param int $qualifying the solver's qualifying first attempts at the size before (0 = not their own direct pace)
     * @return array{0: float, 1: float, 2: float}
     */
    public static function term(float $rawLog, float $drift, float $spread, int $qualifying, int $flags, CandidateModelParameters $parameters): array
    {
        $features = $parameters->features();
        $clip = $parameters->indexClip;
        $log = max(-$clip, min($clip, $rawLog - ($features->on('drift_index') ? $drift : 0.0)));
        $flags &= $features->flagMask();

        if ($features->on('precision_weights')) {
            $variance = $parameters->indexVariance($spread, $qualifying, $flags, $qualifying === 0);
        } else {
            // All alike: the fitted mean noise, plus the flags' terms
            $variance = $parameters->indexVariance(0.0, 0, $flags, false) - ($parameters->indexNoise['constant'] ?? 0.0) + ($parameters->indexNoise['flat'] ?? 0.015);
        }

        return [$log, $variance, 1.0 / $variance];
    }

    /**
     * The noise-model inputs of the attempt's index: [log (drift-adjusted when switched on), spread², 1 ÷ q, indirect,
     * flags] - what CandidateModelFitter regresses the deviations on. Null without an index.
     *
     * @return null|array{0: float, 1: float, 2: float, 3: bool, 4: int}
     */
    public function noiseInputs(int $i, bool $driftAdjusted): null|array
    {
        if (is_nan($this->rawLog[$i] ?? NAN)) {
            return null;
        }

        $qualifying = $this->solverQualifying[$i];

        return [
            $this->rawLog[$i] - ($driftAdjusted ? $this->solverDrift[$i] : 0.0),
            $this->solverSpread[$i] ** 2,
            $qualifying > 0 ? 1.0 / $qualifying : 0.0,
            $qualifying === 0,
            $this->flags->of($i),
        ];
    }

    /**
     * The evidence of every attempt solved from $from on (the fit's window and later) as of its moment, with these
     * parameters - evidenceFor() of an earlier attempt is none.
     */
    public function compute(CandidateModelParameters $parameters, float $from = 0.0): void
    {
        $set = $this->engine->set;
        $this->slot = [];

        foreach ($set->solved as $i => $solved) {
            if ($solved >= $from) {
                $this->slot[$i] = count($this->slot);
            }
        }

        $count = count($this->slot);
        $this->k = array_fill(0, $count, 0);
        $this->weightSum = array_fill(0, $count, 0.0);
        $this->weightedLogSum = array_fill(0, $count, 0.0);
        $this->logSum = array_fill(0, $count, 0.0);
        $this->logSquareSum = array_fill(0, $count, 0.0);
        $this->noiseSum = array_fill(0, $count, 0.0);

        foreach ($set->puzzles() as $puzzle) {
            $attempts = $set->attemptsOfPuzzle($puzzle);
            $knownSolved = [];
            $knownTracked = [];
            $terms = [];

            foreach ($attempts as $i) {
                $term = $this->indexTerms($i, $parameters);

                if ($term !== null) {
                    [$knownSolved[], $knownTracked[]] = $set->knownFrom($i);
                    $terms[] = $term;
                }
            }

            if ($terms === []) {
                continue;
            }

            $order = array_keys($terms);
            array_multisort($knownSolved, $knownTracked, $order);
            $next = 0;
            $eventCount = count($order);
            $evidence = [0, 0.0, 0.0, 0.0, 0.0, 0.0];

            foreach ($attempts as $i) {
                while ($next < $eventCount) {
                    if (($knownSolved[$next] <=> $set->solved[$i] ?: $knownTracked[$next] <=> $set->tracked[$i]) >= 0) {
                        break;
                    }

                    [$log, $variance, $weight] = $terms[$order[$next]];
                    $evidence[0]++;
                    $evidence[1] += $weight;
                    $evidence[2] += $weight * $log;
                    $evidence[3] += $log;
                    $evidence[4] += $log * $log;
                    $evidence[5] += $variance;
                    $next++;
                }

                $slot = $this->slot[$i] ?? null;

                if ($slot !== null) {
                    [$this->k[$slot], $this->weightSum[$slot], $this->weightedLogSum[$slot], $this->logSum[$slot], $this->logSquareSum[$slot], $this->noiseSum[$slot]] = $evidence;
                }
            }
        }
    }

    public function evidenceFor(int $i): CandidateEvidence
    {
        $slot = $this->slot[$i] ?? null;

        return $slot !== null
            ? new CandidateEvidence($this->k[$slot], $this->weightSum[$slot], $this->weightedLogSum[$slot], $this->logSum[$slot], $this->logSquareSum[$slot], $this->noiseSum[$slot])
            : CandidateEvidence::none();
    }

    public function kFor(int $i): int
    {
        $slot = $this->slot[$i] ?? null;

        return $slot !== null ? $this->k[$slot] : 0;
    }

    /**
     * The player's qualifying first attempts (every size) before each attempt.
     *
     * @return array<int, int>
     */
    public function experience(): array
    {
        if ($this->experience === null) {
            // Per attempt: its pair's attempts before it, and the player's other pairs' by binary search - no sort and no
            // copy of the set (the fit runs in 512 MB)
            $set = $this->engine->set;
            $experience = array_fill(0, $set->count(), 0);

            foreach ($set->pairs() as $pairKey => [$start, $pairCount]) {
                $player = explode('|', $pairKey)[0];

                for ($i = $start, $end = $start + $pairCount; $i < $end; $i++) {
                    $before = $i - $start;

                    foreach ($this->pairsOf($player) as $otherPair) {
                        if ($otherPair !== $pairKey) {
                            $before += $this->engine->countBefore($otherPair, $set->solved[$i], $set->tracked[$i]);
                        }
                    }

                    $experience[$i] = $before;
                }
            }

            $this->experience = $experience;
        }

        return $this->experience;
    }

    /**
     * The player's qualifying first attempts (every size) before a moment.
     */
    public function experienceAt(string $player, float $solved, float $tracked): int
    {
        $count = 0;

        foreach ($this->pairsOf($player) as $pairKey) {
            $count += $this->engine->countBefore($pairKey, $solved, $tracked);
        }

        return $count;
    }

    /**
     * @return list<string> the player's pair keys ("player|pieces")
     */
    private function pairsOf(string $player): array
    {
        if ($this->pairsByPlayer === null) {
            $this->pairsByPlayer = [];

            foreach ($this->engine->set->pairs() as $pairKey => $range) {
                $this->pairsByPlayer[explode('|', $pairKey)[0]][] = $pairKey;
            }
        }

        return $this->pairsByPlayer[$player] ?? [];
    }

    /**
     * The evidence known before a moment (a solve outside the qualifying set: the unboxed first tries).
     */
    public function evidenceAt(string $puzzleId, float $solved, float $tracked, CandidateModelParameters $parameters): CandidateEvidence
    {
        $evidence = CandidateEvidence::none();

        foreach ($this->engine->set->attemptsOfPuzzle($puzzleId) as $i) {
            [$knownSolved, $knownTracked] = $this->engine->set->knownFrom($i);

            if (($knownSolved <=> $solved ?: $knownTracked <=> $tracked) >= 0) {
                continue;
            }

            $term = $this->indexTerms($i, $parameters);

            if ($term !== null) {
                $evidence = $evidence->with($term[0], $term[1], $term[2]);
            }
        }

        return $evidence;
    }

    /**
     * Everything known now about the puzzle (every index) - what the batch stores for the live path.
     */
    public function evidenceNow(string $puzzleId, CandidateModelParameters $parameters): CandidateEvidence
    {
        $evidence = CandidateEvidence::none();

        foreach ($this->engine->set->attemptsOfPuzzle($puzzleId) as $i) {
            $term = $this->indexTerms($i, $parameters);

            if ($term !== null) {
                $evidence = $evidence->with($term[0], $term[1], $term[2]);
            }
        }

        return $evidence;
    }
}
