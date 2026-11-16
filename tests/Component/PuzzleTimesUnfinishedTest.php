<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use SpeedPuzzling\Web\Tests\UnfinishedResultSeeding;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Unfinished results on the puzzle page (docs/features/competitions-management/unfinished-results-plan.md "Puzzle
 * detail page"): after every ranked row, most pieces first, no rank, "finished in" when they kept going; only for those
 * without a ranked row; the ranked rows, their ranks and the numbers stay as they were.
 *
 * PUZZLE_1000_02 has solo times of PLAYER_REGULAR (2), PLAYER_PRIVATE and PLAYER_WITH_STRIPE, and no pair/team time.
 */
final class PuzzleTimesUnfinishedTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use TrackerlessTimeSeeding;
    use UnfinishedResultSeeding;

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_02;
    private const string ADMIN_NAME = 'Admin User';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testUnfinishedResultsFollowEveryRankedRowWithMostPiecesFirst(): void
    {
        $this->seedSoloResults();

        $html = $this->render('solo');

        self::assertSame(1, substr_count($html, 'data-testid="unfinished-section"'));
        self::assertStringContainsString("Didn&#039;t finish within the time limit", $html);
        self::assertSame(2, substr_count($html, 'data-testid="unfinished-row"'), 'One bottom row each for the two players without a time.');

        // The ranked rows are untouched: two players for a guest (PLAYER_PRIVATE is private), all of them before the section
        self::assertSame(2, substr_count($html, 'id="leaderboard-row-'));
        $section = strpos($html, 'data-testid="unfinished-section"');
        self::assertIsInt($section);
        self::assertLessThan($section, strrpos($html, 'id="leaderboard-row-'));

        // Most pieces first, no rank, "finished in" for the later finish
        $bottom = substr($html, $section);
        $michael = strpos($bottom, PlayerFixture::PLAYER_WITH_FAVORITES_NAME);
        $admin = strpos($bottom, self::ADMIN_NAME);
        self::assertIsInt($michael);
        self::assertIsInt($admin);
        self::assertLessThan($admin, $michael, '812 pieces come before 479.');
        self::assertStringContainsString('812 / 1', $bottom);
        self::assertStringContainsString('479 / 1', $bottom);
        self::assertStringContainsString('finished in 01:48:12', $bottom);
        self::assertSame(2, substr_count($bottom, '>–</span>'));

        // PLAYER_REGULAR has a time: no bottom row for his unfinished result
        self::assertStringNotContainsString('900 / 1', $html);

        // The tab counts every row it lists
        self::assertStringContainsString('Solo (4)', $html);
    }

    public function testFiltersApplyToTheBottomRowsLikeToTheRankedRows(): void
    {
        $this->seedSoloResults();

        $firstTries = $this->render('solo', ['onlyFirstTries' => true]);
        self::assertSame(1, substr_count($firstTries, 'data-testid="unfinished-row"'));
        self::assertStringContainsString('812 / 1', $firstTries);
        self::assertStringNotContainsString('479 / 1', $firstTries);

        // PLAYER_WITH_FAVORITES lives in Germany, PLAYER_ADMIN in the Czech Republic
        $germany = $this->render('solo', ['country' => 'de']);
        self::assertStringContainsString('812 / 1', $germany);
        self::assertStringNotContainsString('479 / 1', $germany);
    }

    public function testThePlayerWithATimeFindsTheUnfinishedResultAmongTheirAttempts(): void
    {
        $this->seedSoloResults();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $html = $this->render('solo');

        // Two times + the unfinished result; the latest time line stays about times
        self::assertStringContainsString('All my times (3)', $html);
        self::assertSame(1, substr_count($html, 'data-testid="my-unfinished-attempt"'));
        self::assertStringContainsString('900 / 1', $html);
        self::assertStringContainsString('Latest time:', $html);
        self::assertSame(2, substr_count($html, 'data-testid="unfinished-row"'), 'No bottom row of his own.');
    }

    public function testAPlayerWithOnlyAnUnfinishedResultSeesItAsTheirResult(): void
    {
        $this->seedSoloResults();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $html = $this->render('solo');

        self::assertStringContainsString('Your result', $html);
        self::assertStringNotContainsString('All my times', $html);
        self::assertStringNotContainsString('My time:', $html);
        self::assertMatchesRegularExpression('/class="lb-row lb-unfinished-row table-active-player"/', $html);
    }

    public function testATabWithOnlyUnfinishedResultsListsThemAndKeepsTheClaimButton(): void
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);
        $this->seedUnfinishedImportedTeam($round, self::PUZZLE, 'Corner Crew', 612);
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_REGULAR, self::PUZZLE, 700, puzzlers: [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            'Rita Guest',
        ]);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $html = $this->render('group');

        self::assertStringContainsString('Team (2)', $html);
        self::assertSame(2, substr_count($html, 'data-testid="unfinished-row"'));
        self::assertStringContainsString('Corner Crew', $html);
        self::assertStringContainsString('4 puzzlers', $html);
        self::assertSame(1, substr_count($html, 'data-testid="event-result-claim-button"'), 'The unclaimed team keeps "This was my team".');
        self::assertLessThan(strpos($html, 'Corner Crew'), strpos($html, '700 / 1'), '700 pieces before 612.');
        // Nothing ranked: no numbers, no chart
        self::assertStringNotContainsString('lb-stats', $html);
    }

    /**
     * The fixture pair (PLAYER_REGULAR + PLAYER_PRIVATE, TIME_12 on PUZZLE_1000_01) has a time: its unfinished result is
     * no bottom row - the pair key of both sides comes from the team snapshot
     */
    public function testAPairWithATimeKeepsItsRankedRowAndGetsNoBottomRow(): void
    {
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_1000_01, 640, puzzlers: [
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_REGULAR,
        ]);
        // Another pair without a time: a bottom row - the canary of this test
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_1000_01, 555, puzzlers: [
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ]);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'piecesCount' => 1000,
            'category' => 'duo',
        ], $this->browser);
        $component->setRouteLocale('en');
        $html = $component->render()->toString();

        self::assertSame(1, substr_count($html, 'data-testid="unfinished-row"'));
        self::assertStringContainsString('555 / 1', $html);
        self::assertStringNotContainsString('640 / 1', $html);
    }

    public function testThePuzzleWithoutUnfinishedResultsRendersAsBefore(): void
    {
        $html = $this->render('solo');

        self::assertStringNotContainsString('data-testid="unfinished', $html);
        self::assertStringContainsString('Solo (2)', $html);
    }

    public function testTheResultDetailOfAnUnfinishedResultOpens(): void
    {
        $time = $this->seedUnfinishedResult(PlayerFixture::PLAYER_ADMIN, self::PUZZLE, 479, finishedLaterSeconds: 6492);

        $this->browser->request('GET', '/en/result/' . $time->id->toString());

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('479 / 1', $content);
        self::assertStringNotContainsString('Relax', $content);
    }

    private function seedSoloResults(): void
    {
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_ADMIN, self::PUZZLE, 479, finishedLaterSeconds: 6492);
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_WITH_FAVORITES, self::PUZZLE, 812, firstAttempt: true);
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_REGULAR, self::PUZZLE, 900, daysAgo: 1);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $category, array $props = []): string
    {
        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => self::PUZZLE,
            'piecesCount' => 1000,
            'category' => $category,
            ...$props,
        ], $this->browser);
        $component->setRouteLocale('en');

        return $component->render()->toString();
    }
}
