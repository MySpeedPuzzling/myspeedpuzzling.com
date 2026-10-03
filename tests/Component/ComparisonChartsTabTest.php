<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The members' Charts tab of the compare page asks the aggregate for the difficulty tiers (the "By difficulty" card,
 * docs/features/player-comparison.md "Charts") - in the same one statement, without a filter or sort by difficulty.
 */
final class ComparisonChartsTabTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testMembersSeeHowTheLineUpDoesByDifficulty(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->createLiveComponent('Comparison', ['kind' => 'solo'], $client);
        $component->setRouteLocale('en');
        $crawler = $component->call('changeTab', ['tab' => 'charts'])->render()->crawler();

        // The fixtures rate three puzzles of the member's line-up Average (PuzzleIntelligenceFixture)
        $card = $crawler->filter('[data-testid="comparison-chart-difficulty"]');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('[data-testid="comparison-chart-note"]'));
        self::assertCount(1, $card->filter('[data-controller="comparison-chart"] canvas[role="img"]'));
        self::assertStringContainsString('per difficulty (Average)', (string) $card->filter('canvas')->attr('aria-label'));

        $rows = $card->filter('[data-testid="comparison-difficulty-h2h-row"]');
        self::assertCount(1, $rows);
        self::assertStringStartsWith('Average: You ', (string) $rows->attr('title'));
        self::assertSame('/difficulty-icons-sprite.svg#diff-average', $rows->filter('svg.diff-icon use')->attr('href'));
    }
}
