<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Your week" (MyActivityController). The test environment runs with the XP launch switch off, so the page is an
 * admin's - the admin fixture is the owner here; a player is 404 until the switch is on, like every XP surface.
 */
final class MyActivityControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string OWNER = PlayerFixture::PLAYER_ADMIN;

    public function testAGuestIsSentToSignInAndBack(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/my/activity/2026-W42');

        self::assertResponseRedirects();
        self::assertStringContainsString('return=', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testThePageFollowsTheLaunchSwitch(): void
    {
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/my/activity');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOwnerSeesTheResultsOfThePeriod(): void
    {
        $browser = self::createClient();
        $this->insertSolve($browser, self::OWNER, $this->momentInCurrentWeek());
        TestingLogin::asPlayer($browser, self::OWNER);

        $crawler = $browser->request('GET', '/en/my/activity');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-testid="my-activity-result"]')->count());
        self::assertStringContainsString('/en/puzzle/' . PuzzleFixture::PUZZLE_500_04, (string) $browser->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-testid="my-activity-next"]'), 'No next period while this one is running');
        self::assertCount(1, $crawler->filter('[data-testid="my-activity-previous"]'));
    }

    public function testEveryPeriodLengthRenders(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);

        foreach ([DigestPeriodLength::Week, DigestPeriodLength::TwoWeeks, DigestPeriodLength::Month] as $length) {
            $period = DigestPeriod::containing($length, new DateTimeImmutable())->previous();
            $crawler = $browser->request('GET', '/en/my/activity/' . $period->key);

            self::assertResponseIsSuccessful($period->key);
            self::assertCount(1, $crawler->filter('[data-testid="my-activity-next"]'), $period->key);
        }
    }

    public function testAnotherPlayerOnlyEverSeesTheirOwnActivity(): void
    {
        $browser = self::createClient();
        // A time nobody else has: 1:12:01
        $this->insertSolve($browser, PlayerFixture::PLAYER_WITH_STRIPE, $this->momentInCurrentWeek(), seconds: 4321);
        TestingLogin::asPlayer($browser, self::OWNER);

        $browser->request('GET', '/en/my/activity');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('01:12:01', (string) $browser->getResponse()->getContent());
    }

    public function testInvalidAndFuturePeriodsAre404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);

        foreach (['2026-W54', '2026-W43-W44', '2026-13', '2099-W01', '2019-W10'] as $key) {
            $browser->request('GET', '/en/my/activity/' . $key);
            self::assertResponseStatusCodeSame(404, $key);
        }
    }

    public function testThePageCostsTheSameWhateverThePeriodHolds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $period = DigestPeriod::containing(DigestPeriodLength::Week, new DateTimeImmutable())->previous();
        $url = '/en/my/activity/' . $period->key;
        $moment = $period->start->modify('+2 days 10 hours');

        $this->insertSolve($browser, self::OWNER, $moment);
        $withOne = $this->measure($browser, $url);

        $this->insertSolve($browser, self::OWNER, $moment->modify('+1 hour'), PuzzleFixture::PUZZLE_1000_01);
        $this->insertSolve($browser, self::OWNER, $moment->modify('+2 hours'), PuzzleFixture::PUZZLE_500_01);
        $withThree = $this->measure($browser, $url);

        self::assertSame($withOne, $withThree, 'One statement for the results, whatever their number');
        // Measured 2026-10-10 for a member: the signed-in overhead + player row, results, places, achievements, rating
        // snapshots + ladder place, streak, favourites, most solved, next achievement tiers. Higher = a bug to explain
        // (23 since the navbar ring reads the viewer's profile row)
        self::assertSame(23, $withThree, 'The page budget');
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function momentInCurrentWeek(): DateTimeImmutable
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $weekStart = DigestPeriod::containing(DigestPeriodLength::Week, $now)->start;

        // A minute ago, unless the week started less than a minute ago
        return max($weekStart, $now->modify('-1 minute'));
    }

    private function insertSolve(KernelBrowser $browser, string $playerId, DateTimeImmutable $at, string $puzzleId = PuzzleFixture::PUZZLE_500_04, int $seconds = 3600): void
    {
        $utc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, :seconds, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, true, false, 1, 'solo', false, 500)",
            ['id' => Uuid::uuid7()->toString(), 'seconds' => $seconds, 'playerId' => $playerId, 'puzzleId' => $puzzleId, 'at' => $utc],
        );
    }
}
