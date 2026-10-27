<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

/**
 * Every pair/team result in the order of the solves with what the pair/team model needs of it as of its moment
 * (GroupResultSetLoader) - nothing that depends on a fit, so one load serves the replay with yesterday's fit, the
 * refit and an evaluation's time split. Kept as plain columns (~75k rows on production): no array per result.
 *
 * A row is predictable when it is boxed, on a puzzle that is not secret, and at least one member is a registered player
 * with a pace at the puzzle's piece count before the solve (PaceTimeline::paceBefore() - rules 1 and 2: what a
 * prediction at that moment had, never the solver's first pace from later). The other members (guests, players
 * without a pace then) are "unknown": they count with the mean rate of the known ones, and their bucket of the
 * coordination constant and the pair/team's synergy carry the rest.
 */
final class GroupResultSet
{
    public const int NOT_PREDICTABLE_UNBOXED = 1;
    public const int NOT_PREDICTABLE_SECRET = 2;
    public const int NOT_PREDICTABLE_NO_PACE = 3;

    /** @var list<string> time ids */
    public array $id = [];
    /** @var list<int> the pair/team, numbered */
    public array $team = [];
    /** @var list<int> the puzzle, numbered */
    public array $puzzle = [];
    /** @var list<int> */
    public array $pieces = [];
    /** @var list<int> */
    public array $seconds = [];
    /** @var list<float> */
    public array $solved = [];
    /** @var list<float> */
    public array $tracked = [];
    /** @var list<bool> everybody's first try (the result's first_attempt flag) */
    public array $first = [];
    /** @var list<bool> */
    public array $competition = [];
    /** @var list<int> people in the pair/team */
    public array $members = [];
    /** @var list<int> members without a pace before the solve (guests included) */
    public array $unknown = [];
    /** @var list<float> Σ 1 / (paceᵢ × e^driftᵢ) of the known members (0 = none) */
    public array $rate = [];
    /** @var list<float> Σ 1 / paceᵢ of the known members, no drift - the coordination rule's (GroupExpectation) */
    public array $rateWithoutDrift = [];
    /** @var list<int> the puzzle's difficulty index count before the solve */
    public array $k = [];
    /** @var list<float> */
    public array $sumLog = [];
    /** @var list<null|float> the median index (k >= 5) */
    public array $score = [];
    /** @var list<null|float> the picture estimate's log difficulty (current picture) */
    public array $imageLog = [];
    /** @var list<int> 0 = predictable, else NOT_PREDICTABLE_* */
    public array $notPredictable = [];
    /** @var list<string> what the prediction of the time was made for (GroupPredictionModel::fingerprint()) */
    public array $fingerprint = [];
    /** @var list<int> where the row's registered members start in $memberPlayers */
    public array $memberStart = [];
    /** @var list<int> registered members (numbered players), every row's after each other */
    public array $memberPlayers = [];

    public function count(): int
    {
        return count($this->id);
    }

    /**
     * The row's registered members (numbered players)
     *
     * @return list<int>
     */
    public function registeredMembers(int $i): array
    {
        $end = $this->memberStart[$i + 1] ?? count($this->memberPlayers);

        return array_slice($this->memberPlayers, $this->memberStart[$i], $end - $this->memberStart[$i]);
    }

    public function isPredictable(int $i): bool
    {
        return $this->notPredictable[$i] === 0;
    }
}
