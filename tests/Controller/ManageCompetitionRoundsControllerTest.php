<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

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

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        $this->assertResponseIsSuccessful();
        self::assertSame('/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-results-overview-link]')->attr('href'));

        $groupA = self::card($crawler, 'Group A');
        self::assertCount(1, $groupA->filter('[data-round-tool="live"]'));
        self::assertCount(1, $groupA->filter('[data-round-tool="seating"]'));
        self::assertStringStartsWith('/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '?', (string) $groupA->filter('[data-round-tool="desk"]')->attr('href'));
        self::assertSame('Tables: 5 / 6 assigned · Recommended: give every entry a table number before the round starts', trim($groupA->filter('[data-tables-readiness]')->text()));

        self::assertSame('Tables: 3 / 3 assigned', trim(self::card($crawler, 'Group B')->filter('[data-tables-readiness]')->text()));
        // A past round that was never seated is history, not a to-do; a round without entries has nothing to seat
        self::assertCount(0, self::card($crawler, 'Final')->filter('[data-tables-readiness]'));
        self::assertCount(0, self::card($crawler, 'Pairs Final')->filter('[data-tables-readiness]'));
    }

    public function testAnUpcomingInPersonRoundRecommendsTableNumbers(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter('[data-round-badge]')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === 'Qualification Round')->closest('.card');
        self::assertNotNull($card);
        self::assertMatchesRegularExpression('/^Tables: 0 \/ \d+ assigned · Recommended: /', trim($card->filter('[data-tables-readiness]')->text()));
        self::assertSame('/en/manage-round-results/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, explode('?', (string) $card->filter('[data-round-tool="desk"]')->attr('href'))[0]);
    }

    private static function card(Crawler $crawler, string $roundName): Crawler
    {
        $card = $crawler->filter('[data-round-badge]')->reduce(static fn (Crawler $badge): bool => trim($badge->text()) === $roundName)->closest('.card');
        self::assertNotNull($card);

        return $card;
    }
}
