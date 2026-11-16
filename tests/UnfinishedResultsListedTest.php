<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Tier 3 of docs/features/competitions-management/unfinished-results-plan.md: every list that shows somebody's results
 * shows an unfinished one as "479 / 500 pcs" (_unfinished_result_badge) - never as a relax solve, never as a time.
 *
 * PLAYER_WITH_STRIPE (a member) has no result of PUZZLE_500_05.
 */
final class UnfinishedResultsListedTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use UnfinishedResultSeeding;

    private const string PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string PUZZLE = PuzzleFixture::PUZZLE_500_05;
    private const string RESULTS_CUP_ROUND = OfficialResultsFixture::ROUND_GROUP_A;
    private const string BADGE = 'data-testid="unfinished-result"';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testTheProfileResultsListShowsThePiecesPlaced(): void
    {
        $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, finishedLaterSeconds: 6492);
        TestingLogin::asPlayer($this->browser, self::PLAYER);

        $html = $this->render('PlayerSolvedPuzzles', ['playerId' => self::PLAYER]);

        self::assertSame(1, substr_count($html, self::BADGE));
        self::assertStringContainsString('479 / 500 pcs', $html);
        self::assertStringContainsString('finished in 01:48:12', $html);
    }

    public function testTheRelaxFilterLeavesAnUnfinishedResultOut(): void
    {
        $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479);
        TestingLogin::asPlayer($this->browser, self::PLAYER);

        $html = $this->render('PlayerSolvedPuzzles', ['playerId' => self::PLAYER, 'onlyRelax' => true]);

        self::assertStringNotContainsString(self::BADGE, $html);
        self::assertStringNotContainsString('479 / 500', $html);
    }

    public function testThePlayersFeedShowsThePiecesPlaced(): void
    {
        $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, daysAgo: 0);

        $html = $this->render('RecentActivity', ['playerId' => self::PLAYER, 'limit' => 20, 'isOnProfile' => true]);

        self::assertSame(1, substr_count($html, self::BADGE));
        self::assertStringContainsString('479 / 500 pcs', $html);
    }

    public function testTheActivityCalendarMarksTheDayAndListsThePiecesPlaced(): void
    {
        $time = $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, daysAgo: 2);
        self::assertNotNull($time->finishedAt);
        TestingLogin::asPlayer($this->browser, self::PLAYER);

        $html = $this->render('ActivityCalendar', [
            'playerId' => self::PLAYER,
            'year' => (int) $time->finishedAt->format('Y'),
            'month' => (int) $time->finishedAt->format('n'),
            'selectedDay' => $time->finishedAt->format('Y-m-d'),
        ]);

        self::assertSame(1, substr_count($html, 'data-testid="calendar-unfinished-pill"'));
        self::assertSame(1, substr_count($html, self::BADGE));
        self::assertStringContainsString('479 / 500 pcs', $html);
    }

    public function testTheRecapShowsWhatWasPlacedAndLinksTheRoundResults(): void
    {
        // Results Cup's round, on a puzzle nobody of the round has
        $round = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRound::class, self::RESULTS_CUP_ROUND);
        self::assertNotNull($round);
        $time = $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, finishedLaterSeconds: 6492, round: $round);
        TestingLogin::asPlayer($this->browser, self::PLAYER);

        $this->browser->request('GET', '/en/time-added/' . $time->id->toString());

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('data-testid="recap-unfinished"', $content);
        self::assertStringContainsString('479 / 500 pieces', $content);
        self::assertStringContainsString('Pieces left: 21', $content);
        self::assertStringContainsString('finished in 01:48:12', $content);
        self::assertStringContainsString('See round results', $content);
        self::assertStringContainsString('/results/' . $round->slug, $content);
    }

    public function testAFavoritesNotificationNeverCallsItASolve(): void
    {
        $time = $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479, daysAgo: 0);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO notification (id, type, notified_at, player_id, target_solving_time_id) VALUES (:id, 'SubscribedPlayerAddedTime', " . TestClock::SQL_NOW . ', :reader, :time)',
            ['id' => Uuid::uuid7()->toString(), 'reader' => PlayerFixture::PLAYER_WITH_FAVORITES, 'time' => $time->id->toString()],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('GET', '/en/notifications');

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertSame(1, substr_count($content, self::BADGE));
        self::assertStringContainsString('competed with puzzle', $content);
        self::assertStringContainsString('479 / 500 pcs', $content);
    }

    public function testTheTeamPageListsThePiecesPlaced(): void
    {
        $time = $this->seedUnfinishedResult(PlayerFixture::PLAYER_REGULAR, self::PUZZLE, 321, puzzlers: [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE]);
        self::assertNotNull($time->puzzlingTeam);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/teams/' . $time->puzzlingTeam->id->toString());

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertSame(1, substr_count($content, self::BADGE));
        self::assertStringContainsString('321 / 500 pcs', $content);
    }

    /**
     * "Your week" (an admin's page until the XP launch): a pair's unfinished result is the only pair result of the week -
     * its line says the pieces, never "0 puzzles"; the result lists the pieces placed
     */
    public function testYourWeekNeverCallsItAPuzzleSolved(): void
    {
        $this->seedUnfinishedResult(PlayerFixture::PLAYER_ADMIN, self::PUZZLE, 479, puzzlers: [PlayerFixture::PLAYER_ADMIN, 'Rita Guest'], daysAgo: 1);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/my/activity');

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('479 pieces', $content);
        self::assertStringNotContainsString('0 puzzles', $content);
        self::assertSame(1, substr_count($content, self::BADGE));
    }

    public function testTheShareImageOfAnUnfinishedResultIsDrawnAndKeptApartFromATime(): void
    {
        $time = $this->seedUnfinishedResult(self::PLAYER, self::PUZZLE, 479);

        $this->browser->request('GET', '/result-image/' . $time->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->browser->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props): string
    {
        $live = $this->createLiveComponent($component, $props, $this->browser);
        $live->setRouteLocale('en');

        return $live->render()->toString();
    }
}
