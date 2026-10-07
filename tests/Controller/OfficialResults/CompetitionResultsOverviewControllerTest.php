<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The organiser's control room (competition_results_overview): every round with its progress and its tools.
 */
final class CompetitionResultsOverviewControllerTest extends WebTestCase
{
    private const string PAGE = '/en/manage-event-results/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInIsSentToSignIn(): void
    {
        $this->browser->request('GET', self::PAGE);

        self::assertResponseRedirects();
    }

    public function testOnlyTheEventsOrganisersMayOpenIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::PAGE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryRoundWithItsProgressAndTools(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $rows = $crawler->filter('[data-overview-round]');
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], $rows->each(static fn (Crawler $row): string => $row->filter('th .badge')->text()));

        $groupA = $crawler->filter('[data-overview-round="' . OfficialResultsFixture::ROUND_GROUP_A . '"]');
        self::assertSame('6', $groupA->filter('[data-overview-field="entries"]')->text());
        self::assertSame('5 / 6', $groupA->filter('[data-overview-field="results"]')->text());
        self::assertSame('2', $groupA->filter('[data-overview-field="qualified"]')->text());
        self::assertSame('5 / 6', $groupA->filter('[data-overview-field="tables"]')->text());
        self::assertNull($groupA->filter('[data-overview-published="true"]')->attr('hidden'));
        self::assertSame('/en/events/results-cup/results/group-a', $groupA->filter('[data-overview-published="true"] a')->attr('href'));
        self::assertSame('/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A, explode('?', (string) $groupA->filter('[data-round-tool="desk"]')->attr('href'))[0]);
        self::assertCount(1, $groupA->filter('[data-round-tool="live"]'));
        self::assertCount(1, $groupA->filter('[data-round-tool="seating"]'));

        $groupB = $crawler->filter('[data-overview-round="' . OfficialResultsFixture::ROUND_GROUP_B . '"]');
        self::assertNotNull($groupB->filter('[data-overview-published="true"]')->attr('hidden'));
        self::assertNull($groupB->filter('[data-overview-published="false"]')->attr('hidden'));

        // Advancing across the event's rounds, no source preselected
        self::assertSame('', $crawler->filter('[data-controller="advance-qualified"]')->attr('data-advance-qualified-source-value'));

        // The counters follow every round's private topic - with the page's own token, never the cookie
        $mercure = json_decode((string) $crawler->filter('[data-controller="results-overview"]')->attr('data-results-overview-mercure-value'), true);
        self::assertIsArray($mercure);
        self::assertIsArray($mercure['topics']);
        $topics = json_decode((string) $crawler->filter('[data-controller="mercure-hub"]')->attr('data-mercure-hub-topics-value'), true);
        self::assertIsArray($topics);
        foreach ([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_PAIRS_FINAL] as $roundId) {
            self::assertContains('/round-results/' . $roundId, $mercure['topics']);
            self::assertNotContains('/round-results/' . $roundId, $topics);
        }
        self::assertSame('/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $crawler->filter('[data-controller="results-overview"]')->attr('data-results-overview-state-url-value'));
    }

    public function testARoundWithoutTableNumbersSaysSo(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET table_numbers_off = true WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_B],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        $groupB = $crawler->filter('[data-overview-round="' . OfficialResultsFixture::ROUND_GROUP_B . '"]');
        self::assertSame('not used', $groupB->filter('[data-overview-field="tables"]')->text());
        // Seating stays reachable (to turn table numbers back on), marked as not used
        self::assertCount(1, $groupB->filter('[data-round-tool="seating"] [data-round-tool-not-used]'));
    }

    public function testAnOnlineEventHasNoSeating(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_online = true WHERE id = :id',
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-round-tool="seating"]'));
        self::assertCount(0, $crawler->filter('[data-overview-field="tables"]'));
        self::assertCount(5, $crawler->filter('[data-round-tool="desk"]'));
    }

    public function testAnEventWithoutRoundsSaysSo(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/manage-event-results/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-overview-round]'));
        self::assertCount(0, $crawler->filter('[data-controller="advance-qualified"]'));
    }

    public function testTheEventAndSeriesManagementPagesLinkIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertResponseIsSuccessful();
        self::assertSame(self::PAGE, $crawler->filter('[data-results-overview-link]')->attr('href'));

        $crawler = $this->browser->request('GET', '/en/manage-event-rounds/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertResponseIsSuccessful();
        self::assertSame(self::PAGE, $crawler->filter('[data-results-overview-link]')->attr('href'));
    }
}
