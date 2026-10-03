<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\FindSimilarSpeedPuzzler;
use SpeedPuzzling\Web\Results\SimilarSpeedPuzzler;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FindSimilarSpeedPuzzlerTest extends KernelTestCase
{
    use ComparisonSeeding;

    private FindSimilarSpeedPuzzler $query;
    private DateTimeImmutable $now;
    private string $viewer;

    /** @var list<string> */
    private array $viewerPuzzles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(FindSimilarSpeedPuzzler::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();

        $this->viewer = $this->seedPlayer('Vera Viewer');

        // Puzzles nobody from the fixtures solved - only the seeded candidates can share them
        for ($i = 0; $i < 6; $i++) {
            $puzzle = $this->seedPuzzle(500);
            $this->viewerPuzzles[] = $puzzle;
            $this->seedTime($this->viewer, $puzzle, 2000, $this->daysAgo(40));
        }
    }

    public function testFindsSomebodyAtYourSpeedWithReasons(): void
    {
        $this->seedSkill($this->viewer, 60.0, SkillTier::Proficient->value);
        $match = $this->candidate('Mia Match', skill: 62.5, shared: 5, recentSolves: 2);

        $found = $this->query->find($this->viewer, [], 'seed1');

        self::assertNotNull($found);
        self::assertSame($match, $found->playerId);
        self::assertSame('Mia Match', $found->playerName);
        self::assertSame(SimilarSpeedPuzzler::BASIS_SKILL, $found->basis);
        self::assertSame(500, $found->piecesCount);
        self::assertSame(SkillTier::Proficient, $found->viewerSkillTier);
        self::assertSame(60.0, $found->viewerSkillPercentile);
        self::assertSame(SkillTier::Proficient, $found->skillTier);
        self::assertSame(62.5, $found->skillPercentile);
        self::assertSame(38, $found->topPercent());
        self::assertSame(5, $found->sharedPuzzles);
        self::assertSame(2, $found->recentSolves);
        self::assertNull($found->viewerBaselineSeconds);
    }

    public function testNobodyWhoMustNotBeSuggested(): void
    {
        $this->seedSkill($this->viewer, 60.0, SkillTier::Proficient->value);

        $this->candidate('Pia Private', skill: 60.1, private: true);
        $this->candidate('Otto Opted Out', skill: 60.2, rankingOptedOut: true);
        $blocked = $this->candidate('Bob Blocked', skill: 60.3);
        $this->seedBlock($this->viewer, $blocked);
        $blocker = $this->candidate('Barbara Blocker', skill: 60.4);
        $this->seedBlock($blocker, $this->viewer);
        $inLineUp = $this->candidate('Lena Line-up', skill: 60.5);
        $this->candidate('Fiona Few', skill: 60.6, shared: 4);
        $this->candidate('Ivan Inactive', skill: 60.7, activeDaysAgo: 400);

        foreach (['a', 'b', 'c', 'd', 'e'] as $seed) {
            self::assertNull($this->query->find($this->viewer, [$inLineUp], $seed), "Seed {$seed}");
        }

        // Without the line-up exclusion Lena is the one
        self::assertSame($inLineUp, $this->query->find($this->viewer, [], 'a')?->playerId);
    }

    public function testTheSeedPicksAmongTheNearestDeterministically(): void
    {
        $this->seedSkill($this->viewer, 60.0, SkillTier::Proficient->value);
        $candidates = [
            $this->candidate('One', skill: 59.0),
            $this->candidate('Two', skill: 61.0),
            $this->candidate('Three', skill: 63.0),
        ];

        $picked = [];

        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'] as $seed) {
            $expected = $candidates;
            usort($expected, static fn(string $a, string $b): int => strcmp(md5($seed . $a), md5($seed . $b)));

            $found = $this->query->find($this->viewer, [], $seed);

            self::assertSame($expected[0], $found?->playerId, "Seed {$seed} shuffles like md5(seed || id)");
            self::assertSame($found->playerId, $this->query->find($this->viewer, [], $seed)?->playerId, 'Same seed, same suggestion');
            $picked[$found->playerId] = true;
        }

        self::assertGreaterThan(1, count($picked), '"Roll again" (another seed) can suggest somebody else');
    }

    public function testFallsBackToTheBaselineOfTheMainPieceCount(): void
    {
        // No skill yet; most qualifying solves at 1000 pieces
        $this->seedBaseline($this->viewer, 500, 2400, qualifyingSolves: 3);
        $this->seedBaseline($this->viewer, 1000, 6000, qualifyingSolves: 12);

        $near = $this->candidate('Nora Near', baseline: [1000, 6300]);
        $this->candidate('Wrong Size', baseline: [500, 2400]);

        $found = $this->query->find($this->viewer, [], 'seed1');

        self::assertNotNull($found);
        self::assertSame($near, $found->playerId);
        self::assertSame(SimilarSpeedPuzzler::BASIS_BASELINE, $found->basis);
        self::assertSame(1000, $found->piecesCount);
        self::assertSame(6000, $found->viewerBaselineSeconds);
        self::assertSame(6300, $found->baselineSeconds);
        self::assertNull($found->skillTier);
        self::assertNull($found->topPercent());
    }

    public function testFallsBackWhenNobodyAtYourSkillPasses(): void
    {
        $this->seedSkill($this->viewer, 60.0, SkillTier::Proficient->value);
        $this->seedBaseline($this->viewer, 500, 2400);
        $this->candidate('Skilled But Few', skill: 60.1, shared: 2);
        $byBaseline = $this->candidate('Baseline Bea', baseline: [500, 2500]);

        $found = $this->query->find($this->viewer, [], 'x');

        self::assertSame($byBaseline, $found?->playerId);
        self::assertSame(SimilarSpeedPuzzler::BASIS_BASELINE, $found->basis);
    }

    public function testNothingToGoOn(): void
    {
        self::assertNull($this->query->find($this->viewer, [], 'x'), 'Neither skill nor baseline yet');
        self::assertNull($this->query->find('not-a-uuid', [], 'x'));
    }

    /**
     * A player who shares $shared of the viewer's puzzles (solo), solved something $activeDaysAgo, $recentSolves of
     * those times within the last 30 days.
     *
     * @param null|array{int, int} $baseline pieces, seconds
     */
    private function candidate(
        string $name,
        null|float $skill = null,
        int $shared = 6,
        bool $private = false,
        bool $rankingOptedOut = false,
        int $activeDaysAgo = 50,
        int $recentSolves = 0,
        null|array $baseline = null,
    ): string {
        $playerId = $this->seedPlayer($name, private: $private, rankingOptedOut: $rankingOptedOut);

        if ($skill !== null) {
            $this->seedSkill($playerId, $skill, SkillTier::fromPercentile($skill)->value);
        }

        if ($baseline !== null) {
            $this->seedBaseline($playerId, $baseline[0], $baseline[1]);
        }

        foreach (array_slice($this->viewerPuzzles, 0, $shared) as $index => $puzzle) {
            $this->seedTime($playerId, $puzzle, 2100, $this->daysAgo($index < $recentSolves ? 5 : $activeDaysAgo));
        }

        return $playerId;
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return $this->now->modify("-{$days} days");
    }
}
