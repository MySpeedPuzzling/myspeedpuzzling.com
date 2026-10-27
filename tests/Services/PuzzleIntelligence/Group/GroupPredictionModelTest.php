<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Group;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupReplay;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupResultSet;
use SpeedPuzzling\Web\Value\GroupModelParameters;

/**
 * The pair/team model on synthetic pairs and teams whose true synergy is known: it learns the synergy as of each
 * result (never from the result itself or later ones), shrinks it, keeps the coordination rule for everything else and
 * calibrates its windows; the reference predicts only what GroupExpectation's rule can.
 */
final class GroupPredictionModelTest extends TestCase
{
    private const float DAY = 86400.0;
    private const float START = 1_700_000_000.0;
    private const float TAU = 0.5;

    public function testTheSynergyLearnedFromEarlierResultsBeatsTheCoordinationRuleOutOfPeriod(): void
    {
        [$set, $synergies] = self::synthetic(teams: 60, resultsPerTeam: 24);
        $cut = self::START + 360 * self::DAY;
        $model = new GroupPredictionModel();
        $parameters = $model->fit($set, $cut, self::TAU);
        $replay = $model->replay($set, $parameters);

        $model0 = 0.0;
        $rule = 0.0;
        $n = 0;
        $hits50 = 0;
        $hits80 = 0;

        for ($i = 0; $i < $set->count(); $i++) {
            if ($set->solved[$i] < $cut || $replay->kind[$i] !== GroupReplay::KIND_FIRST) {
                continue;
            }

            $y = log($set->seconds[$i]);
            $model0 += abs($y - (float) $replay->centre[$i]);
            $rule += abs($y - (float) $replay->base[$i]);
            $window = $replay->window[$i];
            self::assertNotNull($window);
            $hits50 += $y >= $window[1] && $y <= $window[2] ? 1 : 0;
            $hits80 += $y >= $window[0] && $y <= $window[3] ? 1 : 0;
            $n++;
        }

        self::assertGreaterThan(300, $n);
        self::assertLessThan(0.9 * $rule / $n, $model0 / $n, 'The synergy learned before each result cuts the error by more than 10 %');
        self::assertEqualsWithDelta(0.5, $hits50 / $n, 0.08, 'The 50 % window holds about half out of period');
        self::assertEqualsWithDelta(0.8, $hits80 / $n, 0.07);
        self::assertContains($parameters->kappaKnown, GroupPredictionModel::KAPPA_KNOWN_GRID);

        // The synergy of a team's last result follows its true synergy
        $last = [];

        for ($i = 0; $i < $set->count(); $i++) {
            $last[$set->team[$i]] = $i;
        }

        $truth = [];
        $learned = [];

        foreach ($last as $team => $i) {
            $truth[] = $synergies[$team];
            $learned[] = (float) $replay->synergy[$i];
        }

        self::assertGreaterThan(0.8, self::correlation($truth, $learned));
    }

    public function testAPredictionUsesNothingOfItsOwnResultOrLaterOnes(): void
    {
        [$set] = self::synthetic(teams: 10, resultsPerTeam: 12);
        $model = new GroupPredictionModel();
        $parameters = $model->fit($set, self::START + 200 * self::DAY, self::TAU);
        $before = $model->replay($set, $parameters);

        // Every result from the middle on ten times slower
        $middle = intdiv($set->count(), 2);

        $set->seconds = array_map(static fn (int $seconds, int $i): int => $i >= $middle ? 10 * $seconds : $seconds, $set->seconds, array_keys($set->seconds));

        $after = $model->replay($set, $parameters);

        for ($i = 0; $i <= $middle; $i++) {
            self::assertSame($before->centre[$i], $after->centre[$i], "Result {$i} is predicted as before");
        }

        self::assertNotSame($before->centre[$set->count() - 1], $after->centre[$set->count() - 1], 'A later result does see the slower ones before it');
    }

    public function testUnknownMembersCountWithTheKnownOnesMeanRate(): void
    {
        $set = new GroupResultSet();
        self::add($set, team: 0, puzzle: 0, seconds: 3600, day: 1, members: 2, unknown: 1, rate: 1 / 3000, score: 1.0);
        self::add($set, team: 1, puzzle: 0, seconds: 3600, day: 2, members: 2, unknown: 0, rate: 2 / 3000, score: 1.0);
        $parameters = self::parameters(coordination: ['2|1|0' => 0.2, '2|1|1' => 0.3]);
        $replay = (new GroupPredictionModel())->replay($set, $parameters);

        // A pair with a guest: R = 1/3000 × 2 people / 1 known - like the pair of two known 3000 s puzzlers, its own constant
        self::assertEqualsWithDelta(0.3 + log(1500), $replay->base[0], 1e-9);
        self::assertEqualsWithDelta(0.2 + log(1500), $replay->base[1], 1e-9);
        self::assertNull($replay->baselineCentre[0], 'The reference never predicts a pair/team with an unknown member');
        self::assertNotNull($replay->baselineCentre[1]);
    }

    public function testARepeatAfterTheSameTeamsOwnTimeStartsFromThatTime(): void
    {
        $set = new GroupResultSet();
        self::add($set, team: 0, puzzle: 7, seconds: 4000, day: 1);
        self::add($set, team: 1, puzzle: 7, seconds: 5000, day: 2, first: false);
        self::add($set, team: 0, puzzle: 7, seconds: 3500, day: 3, first: false);
        $parameters = self::parameters(repeatLogRatio: log(0.9));
        $replay = (new GroupPredictionModel())->replay($set, $parameters);

        self::assertSame(GroupReplay::KIND_FIRST, $replay->kind[0]);
        self::assertSame(GroupReplay::KIND_REPEAT, $replay->kind[1], "Another team's repeat: the model");
        self::assertSame(GroupReplay::KIND_OWN, $replay->kind[2]);
        self::assertEqualsWithDelta(log(4000 * 0.9), $replay->centre[2], 1e-9);

        $off = (new GroupPredictionModel())->replay($set, self::parameters(repeatLogRatio: log(0.9), off: [GroupModelParameters::PART_OWN_REPEAT]));
        self::assertSame(GroupReplay::KIND_REPEAT, $off->kind[2]);
    }

    public function testResultsThatCannotBePredictedGetNothing(): void
    {
        $set = new GroupResultSet();
        self::add($set, team: 0, puzzle: 0, seconds: 3600, day: 1, notPredictable: GroupResultSet::NOT_PREDICTABLE_NO_PACE);
        self::add($set, team: 0, puzzle: 1, seconds: 3600, day: 2, notPredictable: GroupResultSet::NOT_PREDICTABLE_UNBOXED);
        self::add($set, team: 0, puzzle: 2, seconds: 3600, day: 3, notPredictable: GroupResultSet::NOT_PREDICTABLE_SECRET);
        self::add($set, team: 0, puzzle: 3, seconds: 3600, day: 4);
        $replay = (new GroupPredictionModel())->replay($set, self::parameters());

        self::assertSame([null, null, null], array_slice($replay->centre, 0, 3));
        self::assertSame([null, null, null], array_slice($replay->baselineCentre, 0, 3));
        self::assertNotNull($replay->centre[3]);
        self::assertSame(0, $replay->experience[3], 'Results that cannot be predicted are not part of the synergy');
    }

    public function testTheDifficultyIsTheShownModelsAsOfTheResult(): void
    {
        $set = new GroupResultSet();
        self::add($set, team: 0, puzzle: 0, seconds: 1, day: 1, k: 7, sumLog: 1.0, score: 1.2);
        self::add($set, team: 0, puzzle: 0, seconds: 1, day: 1, k: 2, sumLog: 0.4, score: null, imageLog: 0.1);
        self::add($set, team: 0, puzzle: 0, seconds: 1, day: 1, k: 0, sumLog: 0.0, score: null, imageLog: 0.1);
        self::add($set, team: 0, puzzle: 0, seconds: 1, day: 1, k: 0, sumLog: 0.0, score: null);

        self::assertEqualsWithDelta(log(1.2), GroupPredictionModel::logDifficulty($set, 0, self::TAU)[0], 1e-12);
        self::assertSame('solves', GroupPredictionModel::logDifficulty($set, 0, self::TAU)[1]);
        self::assertEqualsWithDelta((0.4 + 0.5 * 0.1) / 2.5, GroupPredictionModel::logDifficulty($set, 1, self::TAU)[0], 1e-12);
        self::assertSame('shrunk', GroupPredictionModel::logDifficulty($set, 1, self::TAU)[1]);
        self::assertSame([0.1, 'image'], GroupPredictionModel::logDifficulty($set, 2, self::TAU));
        self::assertSame([0.0, 'none'], GroupPredictionModel::logDifficulty($set, 3, self::TAU));
    }

    public function testTheParametersSurviveTheirJson(): void
    {
        [$set] = self::synthetic(teams: 20, resultsPerTeam: 10);
        $parameters = (new GroupPredictionModel())->fit($set, self::START + 400 * self::DAY, self::TAU, [GroupModelParameters::PART_DECAY]);
        $json = json_decode(json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        $decoded = GroupModelParameters::fromArray($json);

        self::assertEquals($parameters, $decoded);
        self::assertNull($decoded->halfLifeDays, 'Decay off');
        self::assertFalse($decoded->isOn(GroupModelParameters::PART_DECAY));
    }

    public function testTheFingerprintChangesWithWhatThePredictionWasMadeFor(): void
    {
        $base = GroupPredictionModel::fingerprint('team', 'puzzle', 500, 1000.0, true, false, false);

        self::assertSame($base, GroupPredictionModel::fingerprint('team', 'puzzle', 500, 1000.0, true, false, false));
        self::assertNotSame($base, GroupPredictionModel::fingerprint('other team', 'puzzle', 500, 1000.0, true, false, false));
        self::assertNotSame($base, GroupPredictionModel::fingerprint('team', 'puzzle', 500, 1000.0 + 86400, true, false, false));
        self::assertNotSame($base, GroupPredictionModel::fingerprint('team', 'puzzle', 500, 1000.0, false, false, false));
        self::assertNotSame($base, GroupPredictionModel::fingerprint('team', 'puzzle', 500, 1000.0, true, false, true));
        self::assertSame(['size 2', 'experience 3-9', 'unknown members 1', 'own earlier time'], GroupPredictionModel::slicesOfBucket('own|e3-9|u1|solves|2'));
    }

    /**
     * Pairs (two known members) with a true synergy each, solving rated puzzles over two years.
     *
     * @return array{0: GroupResultSet, 1: array<int, float>}
     */
    private static function synthetic(int $teams, int $resultsPerTeam): array
    {
        mt_srand(42);
        $results = [];
        $synergies = [];

        for ($team = 0; $team < $teams; $team++) {
            $synergies[$team] = 0.15 * self::normal();
            $pace = 2500 + 1500 * mt_rand() / mt_getrandmax();

            for ($r = 0; $r < $resultsPerTeam; $r++) {
                $day = 730 * mt_rand() / mt_getrandmax();
                $score = exp(0.2 * self::normal());
                $seconds = (int) round(exp(log(1.2) + log($score) + log($pace / 2) + $synergies[$team] + 0.1 * self::normal()));
                $results[] = [$team, $team * 1000 + $r, $seconds, $day, 2 / $pace, $score];
            }
        }

        usort($results, static fn (array $a, array $b): int => $a[3] <=> $b[3]);
        $set = new GroupResultSet();

        foreach ($results as [$team, $puzzle, $seconds, $day, $rate, $score]) {
            self::add($set, team: $team, puzzle: $puzzle, seconds: max(1, $seconds), day: $day, rate: $rate, score: $score, players: [2 * $team, 2 * $team + 1]);
        }

        return [$set, $synergies];
    }

    /**
     * @param list<int> $players
     */
    private static function add(
        GroupResultSet $set,
        int $team,
        int $puzzle,
        int $seconds,
        float $day,
        bool $first = true,
        int $members = 2,
        int $unknown = 0,
        float $rate = 2 / 3000,
        int $k = 20,
        float $sumLog = 0.0,
        null|float $score = 1.0,
        null|float $imageLog = null,
        int $notPredictable = 0,
        array $players = [],
    ): void {
        $set->memberStart[] = count($set->memberPlayers);
        array_push($set->memberPlayers, ...$players);
        $set->id[] = 'time-' . count($set->id);
        $set->team[] = $team;
        $set->puzzle[] = $puzzle;
        $set->pieces[] = 500;
        $set->seconds[] = $seconds;
        $set->solved[] = self::START + $day * self::DAY;
        $set->tracked[] = self::START + $day * self::DAY;
        $set->first[] = $first;
        $set->competition[] = false;
        $set->members[] = $members;
        $set->unknown[] = $unknown;
        $set->rate[] = $rate;
        $set->rateWithoutDrift[] = $rate;
        $set->k[] = $k;
        $set->sumLog[] = $sumLog;
        $set->score[] = $score;
        $set->imageLog[] = $imageLog;
        $set->notPredictable[] = $notPredictable;
        $set->fingerprint[] = md5((string) count($set->id));
    }

    /**
     * @param array<string, float> $coordination
     * @param list<string> $off
     */
    private static function parameters(array $coordination = [], float $repeatLogRatio = 0.0, array $off = []): GroupModelParameters
    {
        $quantiles = ['first' => [-0.2, -0.1, 0.1, 0.2], 'repeat' => [-0.2, -0.1, 0.1, 0.2], 'own' => [-0.2, -0.1, 0.1, 0.2]];

        return new GroupModelParameters(GroupPredictionModel::MODEL_VERSION, self::TAU, $off, $coordination, 3.0, 1.0, null, 5.0, [], [], [], $repeatLogRatio, [], $quantiles, [], ['2' => [-0.2, -0.1, 0.1, 0.2]]);
    }

    private static function normal(): float
    {
        $u = max(1e-12, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function correlation(array $a, array $b): float
    {
        $n = count($a);
        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;
        $covariance = 0.0;
        $varianceA = 0.0;
        $varianceB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $covariance += ($a[$i] - $meanA) * ($b[$i] - $meanB);
            $varianceA += ($a[$i] - $meanA) ** 2;
            $varianceB += ($b[$i] - $meanB) ** 2;
        }

        return $covariance / sqrt($varianceA * $varianceB);
    }
}
