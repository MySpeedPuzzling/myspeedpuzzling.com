<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Services\CompetitionToolkit\AdvancementStepGraph;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The rules of declared advancement steps (competition toolkit A8, A-round-formats.md §3.5): one step's own checks,
 * the graph of all steps (a round fed once, no cycles) and the stages of the rounds.
 */
final class AdvancementStepGraphTest extends TestCase
{
    private const array ROUNDS = [
        'group-a' => RoundCategory::Solo,
        'group-b' => RoundCategory::Solo,
        'group-c' => RoundCategory::Solo,
        'semi-1' => RoundCategory::Solo,
        'semi-2' => RoundCategory::Solo,
        'final' => RoundCategory::Solo,
        'pairs' => RoundCategory::Duo,
        'pairs-final' => RoundCategory::Duo,
    ];

    public function testAWjpcBracketIsValidAndHasThreeStages(): void
    {
        $steps = [
            'groups' => self::step(['group-a', 'group-b', 'group-c'], ['semi-1', 'semi-2'], AdvanceDistribution::Balanced, qualify: 25, country: 1),
            'semis' => self::step(['semi-1', 'semi-2'], ['final'], AdvanceDistribution::Single, qualify: 10),
            'pairs' => self::step(['pairs'], ['pairs-final'], AdvanceDistribution::Single),
        ];

        AdvancementStepGraph::validate($steps, self::ROUNDS);

        self::assertSame(
            ['group-a' => 1, 'group-b' => 1, 'group-c' => 1, 'semi-1' => 2, 'semi-2' => 2, 'final' => 3, 'pairs' => 1, 'pairs-final' => 2],
            self::sorted(AdvancementStepGraph::stages($steps)),
        );
    }

    public function testTheStageIsTheLongestPathNotTheShortest(): void
    {
        // A round that feeds the final straight and through a semifinal: the final is stage 3, not 2
        $steps = [
            'groups' => self::step(['group-a'], ['semi-1'], AdvanceDistribution::Single),
            'wildcards' => self::step(['group-b'], ['semi-2'], AdvanceDistribution::Single),
            'final' => self::step(['semi-1', 'group-c'], ['final'], AdvanceDistribution::Single),
        ];

        AdvancementStepGraph::validate($steps, self::ROUNDS);

        $stages = AdvancementStepGraph::stages($steps);
        self::assertSame(3, $stages['final']);
        self::assertSame(1, $stages['group-c']);
        self::assertSame(2, $stages['semi-2']);
    }

    /**
     * @return iterable<string, array{AdvancementStepSettings, string}>
     */
    public static function invalidSteps(): iterable
    {
        yield 'no source' => [self::step([], ['final'], AdvanceDistribution::Single), 'no_sources'];
        yield 'no target' => [self::step(['group-a'], [], AdvanceDistribution::Single), 'no_targets'];
        yield 'a round of another event' => [self::step(['group-x'], ['final'], AdvanceDistribution::Single), 'unknown_round'];
        yield 'source is target' => [self::step(['group-a', 'final'], ['final'], AdvanceDistribution::Single), 'source_is_target'];
        yield 'another category' => [self::step(['group-a'], ['pairs-final'], AdvanceDistribution::Single), 'mixed_categories'];
        yield 'mixed sources' => [self::step(['group-a', 'pairs'], ['final'], AdvanceDistribution::Single), 'mixed_categories'];
        yield 'single with two targets' => [self::step(['group-a'], ['semi-1', 'semi-2'], AdvanceDistribution::Single), 'single_needs_one_target'];
        yield 'balanced with one target' => [self::step(['group-a', 'group-b'], ['final'], AdvanceDistribution::Balanced), 'balanced_needs_two_targets'];
        yield 'by source without a map' => [self::step(['group-a', 'group-b'], ['semi-1', 'semi-2'], AdvanceDistribution::BySource, map: ['group-a' => 'semi-1']), 'by_source_incomplete'];
        yield 'by source onto a round outside the step' => [self::step(['group-a'], ['semi-1'], AdvanceDistribution::BySource, map: ['group-a' => 'final']), 'by_source_incomplete'];
        yield 'qualify 0' => [self::step(['group-a'], ['final'], AdvanceDistribution::Single, qualify: 0), 'invalid_qualify_count'];
        yield 'qualify too many' => [self::step(['group-a'], ['final'], AdvanceDistribution::Single, qualify: 10000), 'invalid_qualify_count'];
        yield 'country 0' => [self::step(['group-a'], ['final'], AdvanceDistribution::Single, country: 0), 'invalid_country_count'];
        yield 'country 100' => [self::step(['group-a'], ['final'], AdvanceDistribution::Single, country: 100), 'invalid_country_count'];
    }

    #[DataProvider('invalidSteps')]
    public function testOneStepsOwnRules(AdvancementStepSettings $step, string $reason): void
    {
        try {
            AdvancementStepGraph::validate(['step' => $step], self::ROUNDS);
            self::fail('Refusal expected: ' . $reason);
        } catch (InvalidAdvancementStep $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame('advancement_step.refused.' . $reason, $refused->translationKey());
        }
    }

    public function testByASourceMapCoveringEverySourceIsValid(): void
    {
        $step = self::step(['group-a', 'group-b', 'group-c'], ['semi-1', 'semi-2'], AdvanceDistribution::BySource, map: ['group-a' => 'semi-1', 'group-b' => 'semi-2', 'group-c' => 'semi-1']);
        AdvancementStepGraph::validate(['step' => $step], self::ROUNDS);

        self::assertSame(['group-a' => 'semi-1', 'group-b' => 'semi-2', 'group-c' => 'semi-1'], $step->targetBySource);
    }

    public function testARoundIsFedByOneStepOnly(): void
    {
        $this->expectRefusal('round_fed_twice', [
            'a' => self::step(['group-a'], ['final'], AdvanceDistribution::Single),
            'b' => self::step(['group-b'], ['final'], AdvanceDistribution::Single),
        ]);
    }

    public function testARoundMayFeedTwoSteps(): void
    {
        // A group feeds the semifinal and a consolation round alike - each fed once
        $steps = [
            'main' => self::step(['group-a'], ['semi-1'], AdvanceDistribution::Single, qualify: 10),
            'consolation' => self::step(['group-a'], ['semi-2'], AdvanceDistribution::Single),
        ];
        AdvancementStepGraph::validate($steps, self::ROUNDS);

        self::assertSame(['group-a' => 1, 'semi-1' => 2, 'semi-2' => 2], self::sorted(AdvancementStepGraph::stages($steps)));
    }

    public function testCyclesAreRefused(): void
    {
        $this->expectRefusal('cycle', [
            'forth' => self::step(['group-a'], ['semi-1'], AdvanceDistribution::Single),
            'back' => self::step(['semi-1'], ['group-a'], AdvanceDistribution::Single),
        ]);

        $this->expectRefusal('cycle', [
            'one' => self::step(['group-a'], ['semi-1'], AdvanceDistribution::Single),
            'two' => self::step(['semi-1'], ['final'], AdvanceDistribution::Single),
            'three' => self::step(['final'], ['group-a'], AdvanceDistribution::Single),
        ]);
    }

    public function testNormalisingLowerCasesDropsRepeatsAndKeepsTheMapOnlyForBySource(): void
    {
        $step = new AdvancementStepSettings(
            sourceRoundIds: ['GROUP-A', 'group-a', 'Group-B'],
            targetRoundIds: ['SEMI-1', 'semi-2'],
            distribution: AdvanceDistribution::BySource,
            targetBySource: ['GROUP-A' => 'SEMI-1', 'group-b' => 'semi-2', 'group-c' => 'semi-1', 'group-x' => 'final'],
            qualifyCount: 8,
            qualifyScope: AdvancementQualifyScope::Overall,
        );

        $normalised = $step->normalised();

        self::assertSame(['group-a', 'group-b'], $normalised->sourceRoundIds);
        self::assertSame(['semi-1', 'semi-2'], $normalised->targetRoundIds);
        self::assertSame(['group-a' => 'semi-1', 'group-b' => 'semi-2'], $normalised->targetBySource);
        self::assertSame(AdvancementQualifyScope::Overall, $normalised->qualifyScope);

        $balanced = new AdvancementStepSettings(['group-a'], ['semi-1', 'semi-2'], AdvanceDistribution::Balanced, ['group-a' => 'semi-1']);
        self::assertSame([], $balanced->normalised()->targetBySource);
    }

    /**
     * @param array<string, AdvancementStepSettings> $steps
     */
    private function expectRefusal(string $reason, array $steps): void
    {
        try {
            AdvancementStepGraph::validate($steps, self::ROUNDS);
            self::fail('Refusal expected: ' . $reason);
        } catch (InvalidAdvancementStep $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    /**
     * @param list<string> $sources
     * @param list<string> $targets
     * @param array<string, string> $map
     */
    private static function step(array $sources, array $targets, AdvanceDistribution $distribution, array $map = [], null|int $qualify = null, null|int $country = null): AdvancementStepSettings
    {
        return new AdvancementStepSettings($sources, $targets, $distribution, $map, $qualify, AdvancementQualifyScope::PerRound, $country)->normalised();
    }

    /**
     * @param array<string, int> $stages
     * @return array<string, int>
     */
    private static function sorted(array $stages): array
    {
        $order = array_flip(array_keys(self::ROUNDS));
        uksort($stages, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $stages;
    }
}
