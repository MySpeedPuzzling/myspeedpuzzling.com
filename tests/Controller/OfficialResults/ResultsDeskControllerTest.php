<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The results desk page (docs/features/competitions-management/results-desk.md): organisers only, never indexed or
 * stored, bootstrapped with the round's ranked entries and following the round's private Mercure topic.
 */
final class ResultsDeskControllerTest extends WebTestCase
{
    private const string GROUP_A = '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInIsSentToSignIn(): void
    {
        $this->browser->request('GET', self::GROUP_A);

        self::assertResponseRedirects();
    }

    public function testOnlyTheEventsOrganisersMayOpenIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::GROUP_A);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/manage-round-results/018d0020-0000-0000-0000-000000009999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOrganiserOfAnotherEventCannotOpenThisRound(): void
    {
        // PLAYER_REGULAR maintains other events (unapproved, online) - not the Results Cup
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_PAIRS);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDeskBootstrapsTheRankedRoundPrivately(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::GROUP_A);

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $this->browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));

        $desk = $crawler->filter('[data-controller="results-desk"]');
        self::assertCount(1, $desk);

        $state = self::decode($desk, 'data-results-desk-state-value');
        self::assertSame('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $state['topic']);
        self::assertIsArray($state['entries']);
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'],
            array_column($state['entries'], 'name'),
        );
        self::assertSame([1, 2, 2, 4, null, null], array_column($state['entries'], 'rank'));
        self::assertIsArray($state['round']);
        self::assertSame(1000, $state['round']['piecesCount']);
        self::assertTrue($state['round']['resultsPublished']);
        self::assertSame('/en/events/results-cup/results/group-a', $state['round']['publicUrl']);
        self::assertIsArray($state['rounds']);
        self::assertCount(5, $state['rounds']);

        $urls = self::decode($desk, 'data-results-desk-urls-value');
        self::assertSame('/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/changes', $urls['record']);
        self::assertSame('csrf-token', $desk->attr('data-results-desk-csrf-token-value'));

        // The page subscribes to the round's private topic
        $topics = json_decode((string) $crawler->filter('[data-controller="mercure-hub"]')->attr('data-mercure-hub-topics-value'), true);
        self::assertIsArray($topics);
        self::assertContains('/round-results/' . OfficialResultsFixture::ROUND_GROUP_A, $topics);

        // Published: the unpublish button shows, the public page is linked
        self::assertNull($crawler->filter('[data-publish-action="unpublish"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-publish-action="publish"]')->attr('hidden'));
        self::assertSame('/en/events/results-cup/results/group-a', $crawler->filter('[data-public-link]')->attr('href'));

        // Export, the other tools of the round, advancing
        self::assertCount(1, $crawler->filter('a[href="/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/csv"]'));
        self::assertCount(1, $crawler->filter('a[href="/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/xlsx"]'));
        self::assertCount(1, $crawler->filter('[data-results-desk-tools] [data-round-tool="live"]'));
        self::assertCount(1, $crawler->filter('[data-results-desk-tools] [data-round-tool="seating"]'));
        self::assertCount(0, $crawler->filter('[data-results-desk-tools] [data-round-tool="desk"]'));
        self::assertSame(
            $this->url('live_results', ['roundId' => OfficialResultsFixture::ROUND_GROUP_A]),
            explode('?', (string) $crawler->filter('[data-round-tool="live"]')->attr('href'))[0],
        );

        $advance = $crawler->filter('[data-controller="advance-qualified"]');
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $advance->attr('data-advance-qualified-source-value'));
        $advanceUrls = self::decode($advance, 'data-advance-qualified-urls-value');
        self::assertSame('/en/official-results/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/advance', $advanceUrls['advance']);

        // Group A is over: the seating step is drawn for the page's script, hidden - the one rule says no
        self::assertFalse($state['round']['tablesReadiness']);
        self::assertIsArray($state['competition']);
        self::assertTrue($state['competition']['isPubliclyVisible']);
        self::assertNotNull($crawler->filter('[data-seating-readiness]')->attr('hidden'));
    }

    public function testARoundAboutToStartShowsTheSeatingStep(): void
    {
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE competition_round SET starts_at = NOW() + INTERVAL '3 hours' WHERE id = :id", ['id' => OfficialResultsFixture::ROUND_GROUP_A]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::GROUP_A);

        $state = self::decode($crawler->filter('[data-controller="results-desk"]'), 'data-results-desk-state-value');
        self::assertIsArray($state['round']);
        self::assertTrue($state['round']['tablesReadiness']);
        // "Tables: 5 / 6" - one entry has no table yet
        $readiness = $crawler->filter('[data-seating-readiness]');
        self::assertNull($readiness->attr('hidden'));
        self::assertStringContainsString('Tables: 5 / 6 assigned', $readiness->text());
        self::assertStringContainsString('recommended before the round starts', $readiness->text());
    }

    public function testAnUnpublishedPairRoundOffersPublishing(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_PAIRS);

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-publish-action="publish"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-publish-action="unpublish"]')->attr('hidden'));
        self::assertSame('Pair / team', $crawler->filter('thead th')->eq(2)->text());

        $state = self::decode($crawler->filter('[data-controller="results-desk"]'), 'data-results-desk-state-value');
        self::assertIsArray($state['entries']);
        self::assertIsArray($state['entries'][0]);
        self::assertSame('team', $state['entries'][0]['kind']);
    }

    public function testAnOnlineEventHasNoSeatingAndNoTableColumn(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_online = true WHERE id = :id',
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::GROUP_A);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-round-tool="seating"]'));
        self::assertCount(0, $crawler->filter('[data-seating-readiness]'));
        self::assertNotNull($crawler->filter('[data-results-desk-target="tableColumn"]')->attr('hidden'));
    }

    public function testAnAdminMayOpenAnyEventsDesk(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/manage-round-results/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters): string
    {
        $generator = self::getContainer()->get(UrlGeneratorInterface::class);

        return $generator->generate($route, [...$parameters, '_locale' => 'en']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Crawler $element, string $attribute): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $element->attr($attribute), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
