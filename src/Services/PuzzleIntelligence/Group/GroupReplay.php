<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

/**
 * Every pair/team result predicted as of its moment with one fit (GroupPredictionModel::replay()), as columns parallel
 * to the GroupResultSet. All values in log seconds; null centre = not predictable.
 */
final class GroupReplay
{
    public const string KIND_FIRST = 'first';
    public const string KIND_REPEAT = 'repeat';
    // A repeat after the same pair/team's own earlier time on the puzzle
    public const string KIND_OWN = 'own';

    /** @var array<int, null|float> the group model's centre */
    public array $centre = [];
    /** @var array<int, null|array{0: float, 1: float, 2: float, 3: float}> its 80 % low, 50 % low, 50 % high, 80 % high */
    public array $window = [];
    /** @var array<int, null|string> */
    public array $kind = [];
    /** @var array<int, int> the pair/team's results before this one */
    public array $experience = [];
    /** @var array<int, null|float> the puzzle's log difficulty used */
    public array $logDifficulty = [];
    /** @var array<int, null|string> what it rested on: solves / shrunk / image / none */
    public array $source = [];
    /** @var array<int, null|float> the coordination rule with the model's buckets, before the synergy and the offsets */
    public array $base = [];
    /** @var array<int, null|float> the pair/team's shrunk synergy as of the result */
    public array $synergy = [];
    /** @var array<int, null|string> GroupPredictionModel::windowBucket() */
    public array $bucket = [];
    /** @var array<int, null|float> the reference: the coordination rule alone (every member known, a rated puzzle) */
    public array $baselineCentre = [];
    /** @var array<int, null|array{0: float, 1: float, 2: float, 3: float}> */
    public array $baselineWindow = [];
}
