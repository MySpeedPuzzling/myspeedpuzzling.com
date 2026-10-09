<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The puzzle page costs a fixed number of statements, measured on the high-frequency series foundation commit before
 * "Used at" became round lines (docs/features/events-page/high-frequency-series-plan.md, WS-D) and pinned exactly - a
 * higher number is a bug to explain, not a new number. The second request is counted (the first warms the caches).
 */
final class PuzzleDetailQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, null|string, int}>
     */
    public static function providePages(): iterable
    {
        // Measured on the foundation commit 387125b6 (2026-10-09): "Used at" rides on the summary statement, so a puzzle
        // used at three events costs what a puzzle used nowhere costs
        yield 'puzzle used at events, guest' => [PuzzleFixture::PUZZLE_500_01, null, 13];
        // + the signed-in overhead, the viewer's statuses and collections
        yield 'puzzle used at events, player' => [PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_REGULAR, 18];
        // + the member's insights (difficulty tiers, prediction)
        yield 'puzzle used at events, member' => [PuzzleFixture::PUZZLE_500_01, PlayerFixture::PLAYER_WITH_STRIPE, 22];
        yield 'puzzle used nowhere, guest' => [PuzzleFixture::PUZZLE_4000, null, 13];
        yield 'puzzle used nowhere, player' => [PuzzleFixture::PUZZLE_4000, PlayerFixture::PLAYER_REGULAR, 18];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $puzzleId, null|string $playerId, int $statements): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        self::assertSame($statements, $this->measure($browser, '/en/puzzle/' . $puzzleId), ($playerId ?? 'guest') . ' ' . $puzzleId);
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
