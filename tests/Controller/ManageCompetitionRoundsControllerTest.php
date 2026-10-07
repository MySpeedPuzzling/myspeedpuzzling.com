<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ManageCompetitionRoundsControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseIsSuccessful();
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEveryRoundLinksItsResultsToolsAndShowsTheTablesReadiness(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // Group A is about to start; every other round of the Results Cup is over
        self::startsIn(OfficialResultsFixture::ROUND_GROUP_A, '+3 hours');

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        $this->assertResponseIsSuccessful();
        self::assertSame('/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-results-overview-link]')->attr('href'));

        $groupA = self::card($crawler, 'Group A');
        self::assertCount(1, $groupA->filter('[data-round-tool="live"]'));
        self::assertCount(1, $groupA->filter('[data-round-tool="seating"]'));
        self::assertStringStartsWith('/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '?', (string) $groupA->filter('[data-round-tool="desk"]')->attr('href'));
        self::assertSame('Results desk', trim($groupA->filter('[data-round-tool="desk"]')->text()));
        self::assertSame('Tables: 5 / 6 assigned - recommended before the round starts', trim((string) preg_replace('/\s+/', ' ', $groupA->filter('[data-seating-readiness]')->text())));

        // review2-b m5: a past round never nags - also one that was (partly) seated - and a round without entries has
        // nothing to seat
        self::assertCount(0, self::card($crawler, 'Group B')->filter('[data-seating-readiness]'));
        self::assertCount(0, self::card($crawler, 'Final')->filter('[data-seating-readiness]'));
        self::assertCount(0, self::card($crawler, 'Pairs Final')->filter('[data-seating-readiness]'));
    }

    /**
     * review2-b m5: one rule everywhere - a round whose stopwatch runs is under way, the round list stays quiet like the
     * stopwatch page.
     */
    public function testARoundUnderWayShowsNoReadiness(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::startsIn(OfficialResultsFixture::ROUND_GROUP_A, '+3 hours');
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = NOW() WHERE id = :id",
            ['id' => OfficialResultsFixture::ROUND_GROUP_A],
        );

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertCount(0, self::card($crawler, 'Group A')->filter('[data-seating-readiness]'));
    }

    public function testAnUpcomingInPersonRoundRecommendsTableNumbers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter('[data-round-badge]')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === 'Qualification Round')->closest('.card');
        self::assertNotNull($card);
        self::assertMatchesRegularExpression('/^Tables: 0 \/ \d+ assigned - recommended before the round starts$/', trim((string) preg_replace('/\s+/', ' ', $card->filter('[data-seating-readiness]')->text())));
        self::assertSame('/en/manage-round-results/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, explode('?', (string) $card->filter('[data-round-tool="desk"]')->attr('href'))[0]);
    }

    private static function startsIn(string $roundId, string $modifier): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition_round SET starts_at = :startsAt WHERE id = :id', [
            'startsAt' => self::getContainer()->get(ClockInterface::class)->now()->modify($modifier)->format('Y-m-d H:i:s'),
            'id' => $roundId,
        ]);
    }

    private static function card(Crawler $crawler, string $roundName): Crawler
    {
        $card = $crawler->filter('[data-round-badge]')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === $roundName)->closest('.card');
        self::assertNotNull($card);

        return $card;
    }
}
