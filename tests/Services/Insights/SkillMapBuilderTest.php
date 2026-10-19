<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\SkillMapBuilder;
use SpeedPuzzling\Web\Value\InsightsSkillGroup;
use SpeedPuzzling\Web\Value\InsightsVerdict;

final class SkillMapBuilderTest extends TestCase
{
    private const string FAST_BRAND = '00000000-0000-7000-a000-000000000002';

    public function testAClearlyFasterBrandIsAStrength(): void
    {
        $results = [];

        for ($i = 0; $i < 60; $i++) {
            $fast = $i % 4 === 0;
            $results[] = InsightsTestResults::result([
                'seconds' => $fast ? 2550 : 3000,
                'days_ago' => 300 - $i * 4,
                'manufacturerId' => $fast ? self::FAST_BRAND : '00000000-0000-7000-a000-000000000001',
                'manufacturerName' => $fast ? 'Brand Two' : 'Brand One',
                'imageTagKeys' => $fast ? ['trait.dark'] : [],
            ]);
        }

        $map = (new SkillMapBuilder())->build(InsightsTestResults::rows($results));

        self::assertSame(60, $map->sampleSize);
        self::assertNotEmpty($map->strengths);
        self::assertSame(InsightsSkillGroup::Brand, $map->strengths[0]->group);
        self::assertSame(self::FAST_BRAND, $map->strengths[0]->key);
        self::assertSame('Brand Two', $map->strengths[0]->label);
        self::assertSame(InsightsVerdict::Strength, $map->strengths[0]->stats->verdict);
        self::assertCount(10, $map->strengths[0]->drillDownTimeIds);

        $groups = array_map(static fn ($group) => $group->group, $map->groups);
        self::assertNotContains(InsightsSkillGroup::Pieces, $groups, 'one piece count only');
        self::assertContains(InsightsSkillGroup::ImageTraits, $groups);

        // The dark pictures are the same fast results - but image traits are a preview, never a strength
        foreach ($map->strengths as $strength) {
            self::assertNotSame(InsightsSkillGroup::ImageTraits, $strength->group);
        }
    }

    public function testSegmentsNeedFiveResultsAndThePieceGroupTwoClasses(): void
    {
        $results = [
            ...InsightsTestResults::series(12, 1.0),
            ...InsightsTestResults::series(4, 1.0, overrides: ['piecesCount' => 1000]),
        ];

        $map = (new SkillMapBuilder())->build(InsightsTestResults::rows($results));
        $pieces = array_values(array_filter($map->groups, static fn ($group) => $group->group === InsightsSkillGroup::Pieces));

        self::assertCount(1, $pieces);
        self::assertSame(['500'], array_map(static fn ($segment) => $segment->key, $pieces[0]->segments));
        self::assertSame([], $map->weakSpots);
    }
}
