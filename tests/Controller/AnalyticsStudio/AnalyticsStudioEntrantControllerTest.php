<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioProfile;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The analytics studio's entrant detail and the compare set's predictions (analytics-studio.md §3.3, R3, R19) on the
 * "Results Cup".
 */
final class AnalyticsStudioEntrantControllerTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string HUGO = PlayerFixture::PLAYER_REGULAR;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheDetailListsEveryAttemptRecentSolvesAndForm(): void
    {
        $browser = self::createClient();
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_5000, 20000, '2026-08-01 10:00:00', firstAttempt: true);
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_5000, null, '2026-09-20 10:00:00', piecesPlaced: 3000);
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_5000, 16000, '2026-09-01 00:00:00', precision: 'month');
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_5000, 8000, '2026-10-10 10:00:00');
        $this->studioDatabase()->executeStatement('UPDATE puzzle SET pieces_count = 5000 WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_6000]);
        $this->seedStudioTime(self::HUGO, PuzzleFixture::PUZZLE_6000, 18000, '2026-09-10 10:00:00');
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_GROUP_B);

        $entrant = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/' . F::ENTRY_B_HUGO . '?v=x');
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(F::ENTRY_B_HUGO, self::dig($entrant, 'key'));

        // The same row as in the analytics answer
        $analytics = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/analytics');
        self::assertSame(self::dig($analytics, 'entrants', F::ENTRY_B_HUGO), self::dig($entrant, 'analytics'));

        $hugo = self::dig($entrant, 'members', 0);
        self::assertFalse(self::dig($hugo, 'predictionsOptedOut'));
        self::assertSame([
            ['solvedAtMs' => self::ms('2026-09-20 10:00:00'), 'solvedAtPrecision' => 'day', 'seconds' => null, 'piecesPlaced' => 3000, 'firstAttempt' => false, 'kind' => 'solo', 'unboxed' => false],
            ['solvedAtMs' => self::ms('2026-09-01 00:00:00'), 'solvedAtPrecision' => 'month', 'seconds' => 16000, 'piecesPlaced' => null, 'firstAttempt' => false, 'kind' => 'solo', 'unboxed' => false],
            ['solvedAtMs' => self::ms('2026-08-01 10:00:00'), 'solvedAtPrecision' => 'day', 'seconds' => 20000, 'piecesPlaced' => null, 'firstAttempt' => true, 'kind' => 'solo', 'unboxed' => false],
        ], self::dig($hugo, 'attempts', $slot), 'newest first, after the cut-off left out');

        self::assertCount(1, self::digArray($hugo, 'recentSimilar', $slot));
        self::assertSame(18000, self::dig($hugo, 'recentSimilar', $slot, 0, 'seconds'));
        self::assertSame(['medianSeconds' => 18000, 'seconds' => [18000, 16000, 20000]], self::dig($hugo, 'recentForm', '5000'));

        // Gina (private) and Ivan: nothing
        $gina = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/' . F::ENTRY_B_GINA);
        self::assertSame([[]], self::dig($gina, 'members'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());
    }

    public function testEarlierPublishedRoundsAtThisEvent(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $entrant = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_FINAL . '/entrant/' . F::ENTRY_FINAL_ANNA);
        self::assertSame([[
            'roundName' => 'Group A',
            'slug' => 'group-a',
            'category' => 'solo',
            'rank' => 1,
            'seconds' => 3600,
            'piecesPlaced' => null,
            'piecesCount' => null,
            'qualified' => true,
            'teamName' => null,
        ]], self::dig($entrant, 'earlierRounds'));

        // The Puzzle Sharks: Anna and Ben both were in Group A
        $sharks = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_PAIRS . '/entrant/' . F::TEAM_SHARKS);
        self::assertSame([1, 2], self::column(self::dig($sharks, 'earlierRounds'), 'rank'));
        self::assertArrayHasKey('lineupAttempts', self::digArray($sharks));
    }

    public function testARoundPublishedOrWithdrawnMeanwhileShowsAtOnce(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();
        $url = '/analytics-studio/' . F::ROUND_FINAL . '/entrant/' . F::ENTRY_FINAL_ANNA;

        self::assertCount(1, self::digArray($this->studioJson($browser, $url), 'earlierRounds'));

        // The entry's own part stays cached - its earlier rounds are read on every answer
        $this->studioDatabase()->executeStatement('UPDATE competition_round SET results_published_at = NULL WHERE id = :id', ['id' => F::ROUND_GROUP_A]);
        self::assertSame([], self::dig($this->studioJson($browser, $url), 'earlierRounds'));
    }

    public function testAnEntryOfAnotherRoundIsNotThere(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/' . F::ENTRY_A_ANNA, 404);
        $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/018d0020-0000-0000-0000-00000000ffff', 404);
    }

    public function testPredictionsAreThePuzzlePagesOnesAndHonourTheOptOut(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        // Group B on a puzzle PLAYER_REGULAR (Hugo) has solved three times: a personal prediction
        $database->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle WHERE round_id = :round', ['puzzle' => PuzzleFixture::PUZZLE_500_02, 'round' => F::ROUND_GROUP_B]);
        $this->clearAnalyticsStudioCache();
        $slot = $this->slotOf(F::ROUND_GROUP_B);

        $expected = self::getContainer()->get(GetPlayerPredictions::class)->forPuzzles(self::HUGO, [PuzzleFixture::PUZZLE_500_02])[PuzzleFixture::PUZZLE_500_02] ?? null;
        self::assertNotNull($expected, 'no canary: no prediction for the fixture player');

        $entrant = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/' . F::ENTRY_B_HUGO);
        $prediction = self::dig($entrant, 'members', 0, 'prediction', $slot);
        self::assertSame([$expected->predictedSeconds, $expected->rangeLowSeconds, $expected->rangeHighSeconds], [self::dig($prediction, 'seconds'), self::dig($prediction, 'low50'), self::dig($prediction, 'high50')]);
        self::assertSame('personal', self::dig($prediction, 'method'));

        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/predictions?entries=' . implode(',', [F::ENTRY_B_HUGO, F::ENTRY_B_GINA, F::ENTRY_A_ANNA, 'junk']) . '&v=x';
        $predictions = $this->studioJson($browser, $url);
        self::assertSame([F::ENTRY_B_GINA, F::ENTRY_B_HUGO], array_keys(self::digArray($predictions, 'entrants')), 'only entries of the round');
        self::assertSame($prediction, self::dig($predictions, 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'prediction', $slot));
        self::assertSame([], self::dig($predictions, 'entrants', F::ENTRY_B_GINA, 'members', 0), 'a private player gets none');

        $database->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => self::HUGO]);
        $predictions = $this->studioJson($browser, $url);
        self::assertSame(['predictionsOptedOut' => true, 'prediction' => null], self::dig($predictions, 'entrants', F::ENTRY_B_HUGO, 'members', 0));
    }

    public function testTheProfilePathIsThePlayerProfileRoute(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        self::assertSame(
            $router->generate('player_profile', ['playerId' => self::HUGO, '_locale' => 'en']),
            AnalyticsStudioProfile::path(self::HUGO),
        );
    }

    private function slotOf(string $roundId): string
    {
        return strtolower($this->studioString('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => $roundId]));
    }

    private static function ms(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() * 1000;
    }
}
