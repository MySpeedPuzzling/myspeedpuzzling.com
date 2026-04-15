<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class PuzzleTimesMyAttemptsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testCardShowsNewPersonalBestWhenLatestEqualsFastest(): void
    {
        // PLAYER_REGULAR has 3 solo attempts on PUZZLE_500_02:
        //   - 2200s, 20 days ago (first)
        //   - 1900s, 15 days ago
        //   - 1700s, 10 days ago (latest AND fastest -> PB)
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_02,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');

        $html = $component->render()->toString();

        self::assertStringContainsString('Latest is your new personal best', $html);
        self::assertStringContainsString('Show all my times (3)', $html);
        self::assertStringContainsString('Jump to me', $html);
        self::assertStringContainsString('leaderboard-row-', $html);
    }

    public function testCollapsibleAbsentForSingleAttempt(): void
    {
        // PLAYER_WITH_STRIPE has exactly one solo attempt on PUZZLE_500_01
        // (registered as TIME_05 in fixtures).
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');

        $html = $component->render()->toString();

        self::assertStringNotContainsString('Show all my times', $html);
        self::assertStringNotContainsString('Latest is your new personal best', $html);
        self::assertStringContainsString('My time:', $html);
    }

    public function testCardShowsLastAndFastestWhenLatestIsNotPersonalBest(): void
    {
        // PLAYER_REGULAR has 2 solo attempts on PUZZLE_1000_02:
        //   - 3950s (TIME_29), 16 days ago  (fastest)
        //   - 4500s (TIME_19), 4 days ago   (latest, slower than fastest)
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_02,
            'piecesCount' => 1000,
        ], $client);
        $component->setRouteLocale('en');

        $html = $component->render()->toString();

        self::assertStringContainsString('Last:', $html);
        self::assertStringContainsString('Fastest:', $html);
        self::assertStringNotContainsString('Latest is your new personal best', $html);
        self::assertStringContainsString('Show all my times (2)', $html);
    }

    public function testCardHiddenForLoggedOutVisitor(): void
    {
        $client = self::createClient();

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_02,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');

        $html = $component->render()->toString();

        self::assertStringNotContainsString('Latest is your new personal best', $html);
        self::assertStringNotContainsString('Show all my times', $html);
        self::assertStringNotContainsString('Jump to me', $html);
    }
}
