<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Query\GetAdminQueueCounts;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Query\GetOAuth2ClientRequests;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleMergeReviewQueue;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeQueue;
use SpeedPuzzling\Web\Results\AdminQueueCounts;
use SpeedPuzzling\Web\Results\OAuth2ClientRequestOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OAuth2ClientRequestStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The backlog badges of the key menu must say what each queue's own page says - one statement for all of them.
 */
final class GetAdminQueueCountsTest extends WebTestCase
{
    public function testEveryCountIsTheQueuesOwnCountForAnAdmin(): void
    {
        self::bootKernel();
        $counts = self::getContainer()->get(GetAdminQueueCounts::class)->forViewer(isAdmin: true);

        self::assertEquals($this->expected(isAdmin: true), $counts);
    }

    public function testAModeratorGetsTheirQueuesOnlyAndNoAdminCounts(): void
    {
        self::bootKernel();
        $counts = self::getContainer()->get(GetAdminQueueCounts::class)->forViewer(isAdmin: false);

        self::assertEquals($this->expected(isAdmin: false), $counts);
        self::assertNull($counts->competitionApprovals);
        self::assertNull($counts->oauth2Requests);
        self::assertNull($counts->duplicatePuzzleSignals);
    }

    public function testTheKeyMenuShowsTheBacklogToAnAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $counts = self::getContainer()->get(GetAdminQueueCounts::class)->forViewer(isAdmin: true);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        $badges = [
            'change-requests' => $counts->changeRequests,
            'merge-requests' => $counts->mergeRequests,
            'puzzle-approvals' => $counts->puzzleApprovals,
            'time-verification' => $counts->timeVerification,
            'competition-approvals' => $counts->competitionApprovals,
            'oauth2-requests' => $counts->oauth2Requests,
            'duplicate-puzzle-signals' => $counts->duplicatePuzzleSignals,
        ];
        // The fixtures leave something waiting in some queues and nothing in others - both colours are checked
        self::assertNotEmpty(array_filter($badges, static fn (null|int $count): bool => $count > 0));
        self::assertNotEmpty(array_filter($badges, static fn (null|int $count): bool => $count === 0));

        foreach ($badges as $key => $count) {
            $badge = $crawler->filter("[data-testid=\"queue-count-{$key}\"]");

            // Every queue shows its count - an empty one a green zero, a backlog in warning colour
            self::assertCount(1, $badge, $key);
            self::assertSame((string) $count, trim($badge->text()), $key);
            self::assertStringContainsString($count > 0 ? 'bg-warning' : 'bg-success', (string) $badge->attr('class'), $key);
        }
    }

    public function testAModeratorSeesTheirBadgesButNoAdminOnes(): void
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        foreach (['competition-approvals', 'oauth2-requests', 'duplicate-puzzle-signals'] as $key) {
            self::assertCount(0, $crawler->filter("[data-testid=\"queue-count-{$key}\"]"), $key);
        }
    }

    public function testNobodyElsePaysForTheCounts(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid^="queue-count-"]'));
    }

    private function expected(bool $isAdmin): AdminQueueCounts
    {
        $container = self::getContainer();

        $competitionApprovals = count($container->get(GetCompetitionEvents::class)->allUnapproved())
            + count($container->get(GetCompetitionSeries::class)->allUnapproved());
        $oauth2Requests = count(array_filter(
            $container->get(GetOAuth2ClientRequests::class)->all(),
            static fn (OAuth2ClientRequestOverview $request): bool => $request->status === OAuth2ClientRequestStatus::Pending->value,
        ));

        return new AdminQueueCounts(
            changeRequests: $container->get(GetPuzzleChangeRequests::class)->countByStatus(includeSecret: $isAdmin)['pending'],
            mergeRequests: $container->get(GetPuzzleMergeReviewQueue::class)->countPending(),
            puzzleApprovals: $container->get(GetPuzzleApprovals::class)->countPending(),
            timeVerification: $container->get(GetSuspiciousTimeQueue::class)->countPending(),
            competitionApprovals: $isAdmin ? $competitionApprovals : null,
            oauth2Requests: $isAdmin ? $oauth2Requests : null,
            duplicatePuzzleSignals: $isAdmin ? $container->get(GetDuplicatePuzzleSignals::class)->openCountsByStrength()['strong'] : null,
        );
    }
}
