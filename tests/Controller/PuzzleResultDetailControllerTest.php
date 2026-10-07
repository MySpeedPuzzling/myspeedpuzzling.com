<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/puzzle-result-detail.md. The route only takes RFC 4122 uuids, so every test opens a copy of a
 * fixture time (ClonesSolvingTimes) - the copy joins the same subject's attempts.
 */
final class PuzzleResultDetailControllerTest extends WebTestCase
{
    use ClonesSolvingTimes;
    use QueryCountAssertions;

    public function testFullPageIsNoindexWithCanonicalToThePuzzle(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);

        $crawler = $browser->request('GET', '/en/result/' . $timeId);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/en/puzzle/' . PuzzleFixture::PUZZLE_500_02, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(0, $crawler->filter('turbo-frame#modal-frame [data-modal-size]'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        // Back to the puzzle when nothing says where the visitor came from
        self::assertCount(1, $crawler->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_500_02 . '"].btn-outline-secondary'));
    }

    public function testModalWhenOpenedIntoTheModalFrame(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);

        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim($content));
        self::assertStringNotContainsString('<html', $content);
        self::assertSame('modal-lg', $crawler->filter('[data-modal-size]')->attr('data-modal-size'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));

        // Every link out of the result leaves the modal
        $profileLink = $crawler->filter('a[href="/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '"]');
        self::assertSame('_top', $profileLink->attr('data-turbo-frame'));

        // Pinned header with the puzzle, the player and the best time; a sheet on phones; back closes it
        $root = $crawler->filter('[data-modal-size]');
        foreach (['data-modal-scrollable', 'data-modal-sheet', 'data-modal-history'] as $attribute) {
            self::assertNotNull($root->attr($attribute), $attribute);
        }
        self::assertCount(1, $crawler->filter('.modal-header .pr-puzzle'));
        self::assertCount(1, $crawler->filter('.modal-header .pr-best-time'));
    }

    public function testSoloShowsOnlyThatPlayersAttemptsWithStanding(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR on PUZZLE_500_02: 2200, 1900, 1700 + this 2400 from yesterday
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);

        $crawler = $this->modal($browser, $timeId);

        $attempts = $crawler->filter('li.pr-attempt');
        self::assertCount(4, $attempts);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $crawler->text());

        // Newest first: yesterday's 40:00 is 11:40 slower than the previous attempt and the best
        self::assertSame('true', $attempts->eq(0)->attr('aria-current'));
        self::assertStringContainsString('00:40:00', $attempts->eq(0)->text());
        self::assertStringContainsString('+11:40', $attempts->eq(0)->filter('.text-danger')->first()->text());
        // The best: 28:20, 3:20 faster than the attempt before it
        self::assertCount(1, $attempts->eq(1)->filter('.bi-star-fill'));
        self::assertStringContainsString('-03:20', $attempts->eq(1)->filter('.text-success')->text());

        // Leaderboard for a guest: 22:30, 28:20, 33:20, 35:00
        $text = $crawler->text();
        self::assertStringContainsString('00:28:20', $text);
        self::assertStringContainsString('Rank 2 of 4', $text);
        self::assertStringContainsString('+05:50', $crawler->filter('.bi-1-circle')->closest('span')?->text() ?? '');
        self::assertCount(0, $crawler->filter('.bi-arrow-up'), 'The next faster time is the leader');
    }

    public function testNextFasterGapWhenItIsNotTheLeader(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_45_UNBOXED, ['seconds_to_solve' => 2300, 'days_ago' => 1, 'unboxed' => false]);

        $crawler = $this->modal($browser, $timeId);

        self::assertStringContainsString('Rank 3 of 4', $crawler->text());
        self::assertStringContainsString('+10:50', $crawler->filter('.bi-1-circle')->closest('span')?->text() ?? '');
        self::assertStringContainsString('+05:00', $crawler->filter('.bi-arrow-up')->closest('span')?->text() ?? '');
    }

    public function testPairShowsOnlyThatExactPair(): void
    {
        $browser = self::createClient();
        // Another pair with PLAYER_REGULAR on the same puzzle must not mix in
        $otherPairTime = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, PuzzleFixture::PUZZLE_1000_01, '01:30:00', ['#admin']);
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_12, ['seconds_to_solve' => 3300, 'days_ago' => 1, 'first_attempt' => false]);

        $crawler = $this->modal($browser, $timeId);

        self::assertCount(2, $crawler->filter('li.pr-attempt'));
        self::assertStringNotContainsString('01:30:00', $crawler->text());
        self::assertStringContainsString('Rank 1 of 2', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href^="/en/teams/"]'));
        // Per person PPM next to the pair's
        self::assertStringContainsString('(2×', $crawler->text());
        // The private member stays masked
        self::assertStringNotContainsString('Jane Smith', $crawler->text());

        $crawler = $this->modal($browser, $otherPairTime);
        self::assertCount(1, $crawler->filter('li.pr-attempt'));
        self::assertStringContainsString('Rank 2 of 2', $crawler->text());
        self::assertStringContainsString('+35:00', $crawler->filter('.bi-1-circle')->closest('span')?->text() ?? '');
    }

    public function testPrivatePlayerIsNotFoundForStrangersButShownToTheOwnerAndTheFriend(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_02, ['days_ago' => 1]);

        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();

        // On her allow list
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
    }

    public function testPairOfAPrivatePlayerAndAGuestIsShownLikeOnTheLeaderboard(): void
    {
        // A guest is never private, so the leaderboard lists this pair for everybody - its detail must open too
        $browser = self::createClient();
        $timeId = $this->addTime(PlayerFixture::PLAYER_PRIVATE_USER_ID, PuzzleFixture::PUZZLE_1000_01, '01:40:00', ['Grandma']);

        $crawler = $this->modal($browser, $timeId);

        self::assertStringContainsString('Grandma', $crawler->text());
        self::assertStringNotContainsString('Jane Smith', $crawler->text());
    }

    public function testUnknownTimeIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/result/' . Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/result/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMembersSeeTheChartOthersALinkToMembership(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);

        $crawler = $this->modal($browser, $timeId);
        self::assertCount(0, $crawler->filter('canvas'));
        $placeholder = $crawler->filter('.player-chart-placeholder a');
        self::assertSame('/en/membership', $placeholder->attr('href'));
        self::assertSame('_top', $placeholder->attr('data-turbo-frame'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->modal($browser, $timeId);
        self::assertCount(1, $crawler->filter('canvas'));
        self::assertCount(0, $crawler->filter('.player-chart-placeholder'));

        // The puzzle's fastest and median time as captioned reference lines (time_chart_controller.js draws them)
        $chartData = (string) $crawler->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value');
        self::assertStringContainsString('"referenceCaption":"Fastest ', $chartData);
        self::assertStringContainsString('"referenceCaption":"Median ', $chartData);
    }

    public function testEditButtonAndSuspiciousTimesOnlyForTheOwner(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_06],
        );

        $crawler = $this->modal($browser, $timeId);
        self::assertCount(3, $crawler->filter('li.pr-attempt'));
        self::assertCount(0, $crawler->filter('a[href*="/edit-time/"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->modal($browser, $timeId);
        self::assertCount(4, $crawler->filter('li.pr-attempt'));
        self::assertCount(4, $crawler->filter('a[href*="/edit-time/"][data-turbo-frame="modal-frame"]'));
        self::assertStringContainsString('Verification needed', $crawler->text());
    }

    public function testSuspiciousOnlyResultIsShownToTheSubjectAdminsAndModerators(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 600, 'days_ago' => 1]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = (SELECT player_id FROM puzzle_solving_time WHERE id = :id)
                AND puzzle_id = (SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id) AND team IS NULL',
            ['id' => $timeId],
        );
        // A community moderator (no admin)
        $moderator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($moderator);
        $moderator->moderatorSince = new DateTimeImmutable('-1 year');
        $entityManager->flush();

        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseStatusCodeSame(404, 'a guest');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseStatusCodeSame(404, 'another player');

        foreach ([PlayerFixture::PLAYER_REGULAR => 'the player', PlayerFixture::PLAYER_ADMIN => 'an admin', PlayerFixture::PLAYER_WITH_STRIPE => 'a moderator'] as $playerId => $who) {
            TestingLogin::asPlayer($browser, $playerId);
            $crawler = $browser->request('GET', '/en/result/' . $timeId);
            self::assertResponseIsSuccessful($who);
            self::assertGreaterThan(0, $crawler->filter('[data-testid="suspicious-badge"]')->count(), $who);
        }
    }

    public function testBlockedPlayersResultIsNotFoundForTheBlocker(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_45_UNBOXED, ['days_ago' => 1]);
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testModalCostsTwoQueries(): void
    {
        $browser = self::createClient();
        $timeId = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_08, ['seconds_to_solve' => 2400, 'days_ago' => 1]);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        // Attempts (with the puzzle and the subject) + standing
        $this->assertQueryCountAtMost($browser, 2, 'Result detail modal for a guest');
    }

    private function modal(KernelBrowser $browser, string $timeId): Crawler
    {
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $userId, string $puzzleId, string $time, array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }
}
