<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\AdvancementStepOverview;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketConnector;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Services\CompetitionToolkit\BracketLayout;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\BracketRoundStatus;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The bracket's layout data (competition toolkit A9): stages as columns, rounds of a stage in schedule order, the lines
 * between neighbouring stages with their y in the connector's viewBox, who feeds each round; broken steps left out, a
 * clash between steps gives no bracket, one bracket per category.
 *
 * Groups A, B → Semifinals A, B → Final (solo); Pairs → Pairs final (duo).
 */
final class BracketLayoutTest extends TestCase
{
    private const string GROUP_A = '00000000-0000-0000-0000-00000000000a';
    private const string GROUP_B = '00000000-0000-0000-0000-00000000000b';
    private const string SEMI_A = '00000000-0000-0000-0000-0000000000c1';
    private const string SEMI_B = '00000000-0000-0000-0000-0000000000c2';
    private const string FINAL = '00000000-0000-0000-0000-0000000000f1';
    private const string PAIRS = '00000000-0000-0000-0000-0000000000d1';
    private const string PAIRS_FINAL = '00000000-0000-0000-0000-0000000000d2';

    public function testGroupsSemifinalsFinal(): void
    {
        $brackets = BracketLayout::of([
            self::step('s1', [self::GROUP_A, self::GROUP_B], [self::SEMI_A, self::SEMI_B], AdvanceDistribution::Balanced, 8),
            self::step('s2', [self::SEMI_A, self::SEMI_B], [self::FINAL], AdvanceDistribution::Single, 4),
            self::step('s3', [self::PAIRS], [self::PAIRS_FINAL], AdvanceDistribution::Single, null),
        ], self::rounds());

        self::assertCount(2, $brackets);
        [$solo, $pairs] = $brackets;

        self::assertSame(RoundCategory::Solo, $solo->category);
        self::assertSame([['Group A', 'Group B'], ['Semifinal A', 'Semifinal B'], ['Final']], self::names($solo));
        // Spread evenly: every group can send people into every semifinal
        self::assertSame([
            [self::GROUP_A, self::SEMI_A, 250, 250],
            [self::GROUP_A, self::SEMI_B, 250, 750],
            [self::GROUP_B, self::SEMI_A, 750, 250],
            [self::GROUP_B, self::SEMI_B, 750, 750],
        ], self::lines($solo, 0));
        self::assertSame([[self::SEMI_A, self::FINAL, 250, 500], [self::SEMI_B, self::FINAL, 750, 500]], self::lines($solo, 1));
        self::assertSame('M0 250 C50 250 50 500 100 500', $solo->gaps[1][0]->path());

        self::assertNull($solo->feedOf(self::GROUP_A));
        $semiFeed = $solo->feedOf(self::SEMI_B);
        self::assertNotNull($semiFeed);
        self::assertSame('s1', $semiFeed->step->id);
        self::assertSame(['Group A', 'Group B'], $semiFeed->sourceNames);
        self::assertSame('s2', $solo->feedOf(self::FINAL)?->step->id);
        self::assertNull($solo->feedOf(self::PAIRS_FINAL), 'feeds stay in their own category');

        self::assertSame(RoundCategory::Duo, $pairs->category);
        self::assertSame([['Pairs'], ['Pairs final']], self::names($pairs));
        self::assertSame([[self::PAIRS, self::PAIRS_FINAL, 500, 500]], self::lines($pairs, 0));
        self::assertSame(['Pairs'], $pairs->feedOf(self::PAIRS_FINAL)?->sourceNames);
    }

    public function testBySourceDrawsOnlyEachSourcesOwnTarget(): void
    {
        [$solo] = BracketLayout::of([
            self::step('s1', [self::GROUP_A, self::GROUP_B], [self::SEMI_A, self::SEMI_B], AdvanceDistribution::BySource, 8, [self::GROUP_A => self::SEMI_B, self::GROUP_B => self::SEMI_A]),
        ], self::rounds());

        self::assertSame([[self::GROUP_A, self::SEMI_B, 250, 750], [self::GROUP_B, self::SEMI_A, 750, 250]], self::lines($solo, 0));
    }

    public function testRoundsOfAStageFollowTheSchedule(): void
    {
        $rounds = self::rounds();
        // Group B now runs first
        $rounds = [$rounds[1], $rounds[0], ...array_slice($rounds, 2)];

        [$solo] = BracketLayout::of([
            self::step('s1', [self::GROUP_A, self::GROUP_B], [self::FINAL], AdvanceDistribution::Single, 2),
        ], $rounds);

        self::assertSame([['Group B', 'Group A'], ['Final']], self::names($solo));
        self::assertSame(['Group B', 'Group A'], $solo->feedOf(self::FINAL)?->sourceNames);
    }

    public function testAStageCanBeSkipped(): void
    {
        // Group A → Semifinal A → Final, and Group B straight into the final: the final is stage 3, Group B's line is
        // not drawn (not a neighbouring stage), the final's "from" names it
        [$solo] = BracketLayout::of([
            self::step('s1', [self::GROUP_A], [self::SEMI_A], AdvanceDistribution::Single, 4),
            self::step('s2', [self::SEMI_A, self::GROUP_B], [self::FINAL], AdvanceDistribution::Single, 2),
        ], self::rounds());

        self::assertSame([['Group A', 'Group B'], ['Semifinal A'], ['Final']], self::names($solo));
        self::assertSame([[self::GROUP_A, self::SEMI_A, 250, 500]], self::lines($solo, 0));
        self::assertSame([[self::SEMI_A, self::FINAL, 500, 500]], self::lines($solo, 1));
        self::assertSame(['Group B', 'Semifinal A'], $solo->feedOf(self::FINAL)?->sourceNames);
    }

    public function testABrokenStepIsLeftOut(): void
    {
        $brackets = BracketLayout::of([
            self::step('s1', [self::GROUP_A, self::GROUP_B], [self::FINAL], AdvanceDistribution::Single, 2),
            // A round of another category - broken by a later change of the round
            self::step('s2', [self::FINAL], [self::PAIRS_FINAL], AdvanceDistribution::Single, 2),
            // A round that is gone
            self::step('s3', [self::PAIRS], ['00000000-0000-0000-0000-0000000000ee'], AdvanceDistribution::Single, 2),
        ], self::rounds());

        self::assertCount(1, $brackets);
        self::assertSame([['Group A', 'Group B'], ['Final']], self::names($brackets[0]));
    }

    public function testStepsClashingGiveNoBracket(): void
    {
        self::assertSame([], BracketLayout::of([
            self::step('s1', [self::GROUP_A], [self::FINAL], AdvanceDistribution::Single, 2),
            self::step('s2', [self::GROUP_B], [self::FINAL], AdvanceDistribution::Single, 2),
        ], self::rounds()));
        self::assertSame([], BracketLayout::of([], self::rounds()));
    }

    public function testTheStatusOfARound(): void
    {
        $now = new DateTimeImmutable('2026-11-17 12:00:00');
        $past = $now->modify('-1 hour');
        $future = $now->modify('+1 hour');

        self::assertSame(BracketRoundStatus::Upcoming, BracketRoundStatus::of(false, null, null, $future, $now));
        self::assertSame(BracketRoundStatus::Upcoming, BracketRoundStatus::of(false, 'scheduled', $future, $future, $now));
        self::assertSame(BracketRoundStatus::Live, BracketRoundStatus::of(false, 'scheduled', $past, $future, $now), 'a scheduled start that has passed runs');
        self::assertSame(BracketRoundStatus::Live, BracketRoundStatus::of(false, 'running', $past, $past, $now));
        self::assertSame(BracketRoundStatus::Finished, BracketRoundStatus::of(false, 'stopped', $past, $past, $now));
        self::assertSame(BracketRoundStatus::Finished, BracketRoundStatus::of(false, null, null, $past, $now));
        self::assertSame(BracketRoundStatus::Published, BracketRoundStatus::of(true, 'running', $past, $past, $now));
    }

    /**
     * @return list<BracketRound> in schedule order
     */
    private static function rounds(): array
    {
        $day = new DateTimeImmutable('2026-11-20 09:00:00');
        $round = static fn (string $id, string $name, int $hour, RoundCategory $category): BracketRound => new BracketRound(
            id: $id,
            name: $name,
            category: $category,
            startsAt: $day->setTime(9 + $hour, 0),
            startTimeKnown: true,
            timezone: 'Europe/Prague',
            status: BracketRoundStatus::Upcoming,
            slug: null,
        );

        return [
            $round(self::GROUP_A, 'Group A', 0, RoundCategory::Solo),
            $round(self::GROUP_B, 'Group B', 1, RoundCategory::Solo),
            $round(self::PAIRS, 'Pairs', 2, RoundCategory::Duo),
            $round(self::SEMI_A, 'Semifinal A', 3, RoundCategory::Solo),
            $round(self::SEMI_B, 'Semifinal B', 4, RoundCategory::Solo),
            $round(self::PAIRS_FINAL, 'Pairs final', 5, RoundCategory::Duo),
            $round(self::FINAL, 'Final', 6, RoundCategory::Solo),
        ];
    }

    /**
     * @param list<string> $sources
     * @param list<string> $targets
     * @param array<string, string> $map
     */
    private static function step(string $id, array $sources, array $targets, AdvanceDistribution $distribution, null|int $qualifyCount, array $map = []): AdvancementStepOverview
    {
        return new AdvancementStepOverview(
            id: $id,
            competitionId: '00000000-0000-0000-0000-000000000001',
            name: null,
            settings: (new AdvancementStepSettings($sources, $targets, $distribution, $map, $qualifyCount))->normalised(),
            position: 0,
        );
    }

    /**
     * @return list<list<string>>
     */
    private static function names(Bracket $bracket): array
    {
        return array_map(static fn (array $stage): array => array_map(static fn (BracketRound $round): string => $round->name, $stage), $bracket->stages);
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private static function lines(Bracket $bracket, int $gap): array
    {
        $lines = array_map(static fn (BracketConnector $line): array => [$line->fromRoundId, $line->toRoundId, $line->fromY, $line->toY], $bracket->gaps[$gap]);
        sort($lines);

        return $lines;
    }
}
