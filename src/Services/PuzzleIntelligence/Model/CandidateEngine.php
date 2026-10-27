<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SplFixedArray;

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
    /** @var null|array<string, list<string>> player => their pair keys (experienceOf(), experienceAt()) */
    private null|array $pairsByPlayer = null;

    // As-of evidence of the attempts solved from compute()'s moment on: attempt => slot (fixed size, null = before it)
    /** @var SplFixedArray<null|int> */
    private SplFixedArray $slot;
    public private(set) CandidateEvidenceColumns $evidence;

    public function __construct(
        public readonly PredictionEngine $engine,
        public readonly AttemptFlags $flags,
    ) {
        $this->slot = new SplFixedArray(0);
        $this->evidence = new CandidateEvidenceColumns(0);
    }

    /**
     * What the candidate reads of the attempt's index, from the engine on demand (four more columns over every attempt
     * would cost ~32 MB of the fit's 512): [ln index, the solver's drift before the solve (0 without a direct pace), their
     * spread, their qualifying first attempts at the size before it (0 = the pace was not their own direct one)] - null
     * without an index.
     *
     * @return null|array{0: float, 1: float, 2: float, 3: int}
     */
    private function solverInputs(int $i): null|array
    {
        $set = $this->engine->set;
        $index = $set->index[$i] ?? null;

        if ($index === null) {
            return null;
        }

        $player = $set->player[$i];
        $pieces = $set->pieces[$i];

        if ($this->engine->timeline->directRowBefore($player, $pieces, $set->solved[$i], $set->tracked[$i]) === null) {
            return [log($index), 0.0, PredictionModelParameters::GLOBAL_SPREAD, 0];
        }

        [$drift, $spread] = $this->engine->stateBefore($player . '|' . $pieces, $i);

        return [log($index), $drift ?? 0.0, $spread ?? PredictionModelParameters::GLOBAL_SPREAD, $set->position[$i] - 1];
    }

    /**
     * The candidate's reading of the attempt's index: [log, noise variance, weight] - null without an index.
     *
     * @return null|array{0: float, 1: float, 2: float}
     */
    public function indexTerms(int $i, CandidateModelParameters $parameters): null|array
    {
        $inputs = $this->solverInputs($i);

        return $inputs !== null ? self::term($inputs[0], $inputs[1], $inputs[2], $inputs[3], $this->flags->of($i), $parameters) : null;
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
        $inputs = $this->solverInputs($i);

        if ($inputs === null) {
            return null;
        }

        [$rawLog, $drift, $spread, $qualifying] = $inputs;

        return [
            $rawLog - ($driftAdjusted ? $drift : 0.0),
            $spread ** 2,
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
        $this->slot = new SplFixedArray($set->count());
        $count = 0;

        foreach ($set->solved as $i => $solved) {
            if ($solved >= $from) {
                $this->slot[$i] = $count++;
            }
        }

        $this->evidence = new CandidateEvidenceColumns($count);

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

                $slot = $this->slot[$i];

                if ($slot !== null) {
                    $this->evidence->set($slot, $evidence);
                }
            }
        }
    }

    /**
     * The attempt's slot in $evidence - null before compute()'s moment.
     */
    public function slotOf(int $i): null|int
    {
        return $i < $this->slot->getSize() ? $this->slot[$i] : null;
    }

    public function evidenceFor(int $i): CandidateEvidence
    {
        $slot = $this->slotOf($i);

        return $slot !== null ? $this->evidence->at($slot) : CandidateEvidence::none();
    }

    /**
     * The player's qualifying first attempts (every size) before the attempt: its place in its pair and the player's other
     * pairs' attempts before it (binary search) - on demand, no column over every attempt.
     */
    public function experienceOf(int $i): int
    {
        $set = $this->engine->set;
        $player = $set->player[$i];
        $pairKey = $player . '|' . $set->pieces[$i];
        $before = $set->position[$i] - 1;

        foreach ($this->pairsOf($player) as $otherPair) {
            if ($otherPair !== $pairKey) {
                $before += $this->engine->countBefore($otherPair, $set->solved[$i], $set->tracked[$i]);
            }
        }

        return $before;
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
