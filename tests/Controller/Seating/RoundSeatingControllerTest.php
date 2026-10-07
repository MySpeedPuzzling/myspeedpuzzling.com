<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Seating;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The seating page of a round (docs/features/competitions-management/seating.md).
 */
final class RoundSeatingControllerTest extends WebTestCase
{
    private const string PAGE = '/en/round-seating/' . OfficialResultsFixture::ROUND_GROUP_A;

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

    public function testOnlyTheEventsOrganisersMaySeat(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::PAGE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOrganiserGetsTheRoundsEntrantsAndItsLiveUpdates(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $seating = $crawler->filter('[data-controller="round-seating"]');
        self::assertCount(1, $seating);

        $entries = json_decode((string) $seating->attr('data-round-seating-entries-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);
        self::assertCount(6, $entries);
        self::assertContains('Anna Fast', array_column($entries, 'displayName'));

        $round = json_decode((string) $seating->attr('data-round-seating-round-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($round);
        self::assertSame(['total' => 6, 'withTableNumber' => 5, 'withResult' => 5, 'qualified' => 2], $round['entries']);

        self::assertStringContainsString('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/table-numbers', (string) $seating->attr('data-round-seating-assign-url-value'));
        self::assertStringContainsString('/seating-proposal', (string) $seating->attr('data-round-seating-proposal-url-value'));
        self::assertNotSame('', (string) $seating->attr('data-round-seating-csrf-token-value'));

        // "Tables: 5 / 6 assigned"
        self::assertStringContainsString('Tables: 5 / 6 assigned', $crawler->filter('[data-round-seating-target="readinessProgress"]')->text());

        // Other organisers' changes arrive over the round's private topic
        self::assertContains('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, self::mercureTopics($crawler));

        // Print views
        self::assertCount(1, $crawler->filter('a[href="/en/print-round-seating/' . OfficialResultsFixture::ROUND_GROUP_A . '?list=names"]'));
    }

    public function testARoundWithoutTableNumbersOffersToTurnThemBackOn(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET table_numbers_off = true WHERE id = :id',
            ['id' => OfficialResultsFixture::ROUND_GROUP_A],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-round-seating-target="offPanel"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-round-seating-target="onPanel"]')->attr('hidden'));
        self::assertStringContainsString('Use table numbers', $crawler->filter('[data-round-seating-target="offPanel"]')->text());
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
        self::assertSelectorTextContains('h2', 'No seating for online events');
        self::assertCount(0, $crawler->filter('[data-controller="round-seating"]'));
        self::assertNotContains('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, self::mercureTopics($crawler));
    }

    public function testSeatThemNowOpensTheProposalForAKnownSourceOnly(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?propose=earlier_rounds');
        self::assertSame('earlier_rounds', $crawler->filter('[data-controller="round-seating"]')->attr('data-round-seating-propose-value'));

        $crawler = $this->browser->request('GET', self::PAGE . '?propose=auto');
        self::assertSame('auto', $crawler->filter('[data-controller="round-seating"]')->attr('data-round-seating-propose-value'));

        $crawler = $this->browser->request('GET', self::PAGE . '?propose=%3Cscript%3E');
        self::assertSame('', $crawler->filter('[data-controller="round-seating"]')->attr('data-round-seating-propose-value'));
        self::assertSame('1', $crawler->filter('[data-round-seating-target="firstInput"]')->attr('value'));

        // The second semifinal's hall starts at table 101
        $crawler = $this->browser->request('GET', self::PAGE . '?propose=earlier_rounds&first=101');
        self::assertSame('101', $crawler->filter('[data-round-seating-target="firstInput"]')->attr('value'));

        foreach (['0', '10000', 'abc'] as $first) {
            $crawler = $this->browser->request('GET', self::PAGE . '?first=' . $first);
            self::assertSame('1', $crawler->filter('[data-round-seating-target="firstInput"]')->attr('value'));
        }
    }

    public function testUnknownRoundsAreNotFound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/round-seating/018d0020-0000-0000-0000-000000009999');
        self::assertResponseStatusCodeSame(404);

        $this->browser->request('GET', '/en/round-seating/not-a-round');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryLanguageHasTheSeatingPage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/zasedaci-poradek-kola/' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/de/round-seating/' . OfficialResultsFixture::ROUND_PAIRS);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<string>
     */
    private static function mercureTopics(Crawler $crawler): array
    {
        $topics = json_decode((string) $crawler->filter('[data-controller="mercure-hub"]')->attr('data-mercure-hub-topics-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($topics);

        /** @var list<string> $topics */
        return $topics;
    }
}
