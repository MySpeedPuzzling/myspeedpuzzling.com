<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use LogicException;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SplFixedArray;

/**
 * The first tries the candidate model is fitted on (CandidateModelFitter), column by column (fixed size - a PHP list
 * grown one by one holds up to twice its size): the target ln(seconds) - ln(pace) - drift and what the posterior, the
 * offsets and the window keys need. Self-contained - the evidence by its slot in the engine's evidence columns, the rest
 * copied - so the fit's later steps run after the attempt set, the engine and the priors are released (the daily fit
 * runs in a 512 MB container).
 */
final class CandidateSamples
{
    /** @var SplFixedArray<int> the attempt of the set */
    private SplFixedArray $attempt;
    /** @var SplFixedArray<int> the attempt's slot in the evidence columns (-1 = none) - useEvidenceOf() */
    private SplFixedArray $slot;
    /** @var SplFixedArray<int> */
    private SplFixedArray $pieces;
    /** @var SplFixedArray<float> the pace's own variance (the size curve's posterior, 0 for the shown pace rules) */
    private SplFixedArray $paceVariance;
    private null|CandidateEvidenceColumns $evidence = null;
    /** @var SplFixedArray<float> */
    private SplFixedArray $target;
    /** @var SplFixedArray<string> */
    private SplFixedArray $priorType;
    /** @var SplFixedArray<float> */
    private SplFixedArray $priorLog;
    /** @var SplFixedArray<PiecesGroup> */
    private SplFixedArray $group;
    /** @var SplFixedArray<PaceSource> */
    private SplFixedArray $paceSource;
    /** @var SplFixedArray<float> */
    private SplFixedArray $spread;
    /** @var SplFixedArray<null|float> the shown model's median (log) from 5 indices on */
    private SplFixedArray $median;
    /** @var SplFixedArray<int> the player's qualifying first attempts (every size) before it */
    private SplFixedArray $firstTries;
    /** @var SplFixedArray<bool> */
    private SplFixedArray $competition;
    /** @var SplFixedArray<int> the player (int of $players) - the taste factor */
    private SplFixedArray $player;
    /** @var SplFixedArray<int> the puzzle (int of $puzzles) - the taste factor */
    private SplFixedArray $puzzle;
    /** @var SplFixedArray<float> */
    private SplFixedArray $solved;
    /** @var SplFixedArray<float> */
    private SplFixedArray $tracked;
    /** @var array<string, int> player id => int */
    private array $players = [];
    /** @var array<string, int> puzzle id => int */
    private array $puzzles = [];

    private int $count = 0;

    /**
     * @param int $capacity at most this many samples - the columns are allocated once at that size
     */
    public function __construct(int $capacity)
    {
        $this->attempt = new SplFixedArray($capacity);
        $this->slot = new SplFixedArray($capacity);
        $this->pieces = new SplFixedArray($capacity);
        $this->paceVariance = new SplFixedArray($capacity);
        $this->target = new SplFixedArray($capacity);
        $this->priorType = new SplFixedArray($capacity);
        $this->priorLog = new SplFixedArray($capacity);
        $this->group = new SplFixedArray($capacity);
        $this->paceSource = new SplFixedArray($capacity);
        $this->spread = new SplFixedArray($capacity);
        $this->median = new SplFixedArray($capacity);
        $this->firstTries = new SplFixedArray($capacity);
        $this->competition = new SplFixedArray($capacity);
        $this->player = new SplFixedArray($capacity);
        $this->puzzle = new SplFixedArray($capacity);
        $this->solved = new SplFixedArray($capacity);
        $this->tracked = new SplFixedArray($capacity);
    }

    public function add(
        int $attempt,
        float $target,
        string $priorType,
        float $priorLog,
        PiecesGroup $group,
        PaceSource $paceSource,
        float $spread,
        null|float $median,
        int $firstTries,
        bool $competition,
        int $pieces,
        float $paceVariance = 0.0,
        int $slot = -1,
        string $player = '',
        string $puzzle = '',
        float $solved = 0.0,
        float $tracked = 0.0,
    ): void {
        $s = $this->count++;
        $this->player[$s] = $this->players[$player] ??= count($this->players);
        $this->puzzle[$s] = $this->puzzles[$puzzle] ??= count($this->puzzles);
        $this->solved[$s] = $solved;
        $this->tracked[$s] = $tracked;
        $this->attempt[$s] = $attempt;
        $this->slot[$s] = $slot;
        $this->pieces[$s] = $pieces;
        $this->paceVariance[$s] = $paceVariance;
        $this->target[$s] = $target;
        $this->priorType[$s] = $priorType;
        $this->priorLog[$s] = $priorLog;
        $this->group[$s] = $group;
        $this->paceSource[$s] = $paceSource;
        $this->spread[$s] = $spread;
        $this->median[$s] = $median;
        $this->firstTries[$s] = $firstTries;
        $this->competition[$s] = $competition;
    }

    /**
     * The columns cut to the samples added, one at a time.
     */
    public function finish(): void
    {
        foreach ([$this->attempt, $this->slot, $this->pieces, $this->paceVariance, $this->target, $this->priorType, $this->priorLog, $this->group, $this->paceSource, $this->spread, $this->median, $this->firstTries, $this->competition, $this->player, $this->puzzle, $this->solved, $this->tracked] as $column) {
            $column->setSize($this->count);
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * A sample paced anew (the size curve): its target, pace source, spread and the pace's variance.
     */
    public function repace(int $s, float $target, PaceSource $paceSource, float $spread, float $paceVariance): void
    {
        $this->target[$s] = $target;
        $this->paceSource[$s] = $paceSource;
        $this->spread[$s] = $spread;
        $this->paceVariance[$s] = $paceVariance;
    }

    /**
     * Room for this many more add()s after finish().
     */
    public function grow(int $by): void
    {
        if ($by <= 0) {
            return;
        }

        foreach ([$this->attempt, $this->slot, $this->pieces, $this->paceVariance, $this->target, $this->priorType, $this->priorLog, $this->group, $this->paceSource, $this->spread, $this->median, $this->firstTries, $this->competition, $this->player, $this->puzzle, $this->solved, $this->tracked] as $column) {
            $column->setSize($this->count + $by);
        }
    }

    /**
     * The samples' evidence from now on: each attempt's slot in the engine's evidence columns (CandidateEngine::compute()
     * first) - the samples keep the columns, not the engine.
     */
    public function useEvidenceOf(CandidateEngine $candidate): void
    {
        for ($s = 0; $s < $this->count; $s++) {
            $this->slot[$s] = $candidate->slotOf((int) $this->attempt[$s]) ?? -1;
        }

        $this->evidence = $candidate->evidence;
    }

    public function attempt(int $s): int
    {
        return (int) $this->attempt[$s];
    }

    public function slot(int $s): int
    {
        return (int) $this->slot[$s];
    }

    public function pieces(int $s): int
    {
        return (int) $this->pieces[$s];
    }

    public function paceVariance(int $s): float
    {
        return (float) $this->paceVariance[$s];
    }

    public function target(int $s): float
    {
        return (float) $this->target[$s];
    }

    public function priorType(int $s): string
    {
        return (string) $this->priorType[$s];
    }

    public function priorLog(int $s): float
    {
        return (float) $this->priorLog[$s];
    }

    public function group(int $s): PiecesGroup
    {
        return $this->group[$s] ?? throw new LogicException('No sample ' . $s);
    }

    public function paceSource(int $s): PaceSource
    {
        return $this->paceSource[$s] ?? throw new LogicException('No sample ' . $s);
    }

    public function spread(int $s): float
    {
        return (float) $this->spread[$s];
    }

    public function evidence(int $s): CandidateEvidence
    {
        $slot = (int) $this->slot[$s];
        $evidence = $this->evidence ?? throw new LogicException('useEvidenceOf() first');

        return $slot >= 0 ? $evidence->at($slot) : CandidateEvidence::none();
    }

    public function k(int $s): int
    {
        $slot = (int) $this->slot[$s];
        $evidence = $this->evidence ?? throw new LogicException('useEvidenceOf() first');

        return $slot >= 0 ? $evidence->kAt($slot) : 0;
    }

    /**
     * The shown model's median (log) from 5 indices on, else null.
     */
    public function median(int $s): null|float
    {
        $median = $this->median[$s];

        return $median !== null ? (float) $median : null;
    }

    public function competition(int $s): bool
    {
        return $this->competition[$s] === true;
    }

    public function firstTries(int $s): int
    {
        return (int) $this->firstTries[$s];
    }

    public function player(int $s): int
    {
        return (int) $this->player[$s];
    }

    public function puzzle(int $s): int
    {
        return (int) $this->puzzle[$s];
    }

    public function solved(int $s): float
    {
        return (float) $this->solved[$s];
    }

    public function tracked(int $s): float
    {
        return (float) $this->tracked[$s];
    }

    /**
     * @return array<int, string> int => player id
     */
    public function playerIds(): array
    {
        return array_flip($this->players);
    }

    /**
     * @return array<string, int> puzzle id => int
     */
    public function puzzleInts(): array
    {
        return $this->puzzles;
    }

    /**
     * The int of a player id (null = no sample of theirs).
     */
    public function playerInt(string $player): null|int
    {
        return $this->players[$player] ?? null;
    }

    /**
     * @return list<float>
     */
    public function spreads(): array
    {
        $spreads = [];

        for ($s = 0; $s < $this->count; $s++) {
            $spreads[] = (float) $this->spread[$s];
        }

        return $spreads;
    }
}
