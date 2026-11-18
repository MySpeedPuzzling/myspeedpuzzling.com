<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * The embeddable standing (real events L3, `standing_embed`, standings.md "Embeddable standing"): one published standing
 * as an <iframe> page for an organiser's website - frameable by any site (this route only: every other page says
 * the site's default), stateless, the public page's table as a guest sees it, 404 like the page, revalidated by ETag, rendered
 * once per generation, and the organiser's copyable snippet.
 */
final class StandingEmbedTest extends WebTestCase
{
    use QueryCountAssertions;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testThePublishedStandingIsAFrameablePageOfItsOwn(): void
    {
        $standingId = $this->overallSolo();

        $crawler = $this->browser->request('GET', '/embed/standing/' . $standingId);
        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        $content = (string) $response->getContent();

        // Framing: any site, this route only
        self::assertSame('frame-ancestors *', $response->headers->get('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Frame-Options'));
        // Stateless, shared, revalidated
        self::assertSame([], $response->headers->getCookies(), 'the stateless firewall never sets a cookie');
        self::assertSame('max-age=0, must-revalidate, public', $response->headers->get('Cache-Control'));
        self::assertNotEmpty($response->getEtag());
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        // Lean: no site layout, every link in a new tab
        self::assertStringContainsString('<base target="_blank">', $content);
        self::assertSelectorNotExists('nav.navbar');
        self::assertSelectorNotExists('footer.footer');
        self::assertStringNotContainsString('service-worker', $content);

        // The event, the standing, the public page's table (Group A: 1, 2, 2, 4) and the link to the public page
        $eventName = $this->database->fetchOne('SELECT name FROM competition WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        self::assertIsString($eventName);
        self::assertSelectorTextContains('[data-standing-embed-event]', $eventName);
        self::assertSelectorTextContains('[data-standing-title]', 'Overall solo');
        self::assertSame(['1', '2', '2', '4'], self::ranks($crawler));
        self::assertStringContainsString('Ben Steady', $content);
        self::assertSame('http://localhost/en/events/results-cup/standings/overall-solo', $crawler->filter('[data-standing-embed-brand]')->attr('href'));
        self::assertSelectorTextContains('[data-standing-embed-brand]', 'Results by MySpeedPuzzling');
        self::assertSame('/embed/standing/' . $standingId, $crawler->filter('#standing-embed')->attr('data-url'));
        self::assertSame('/competition-standings/' . strtolower(OfficialResultsFixture::COMPETITION_RESULTS_CUP), $crawler->filter('#standing-embed')->attr('data-topic'));

        // Only the embed relaxes framing: the public standing page sends no frame-ancestors policy of its own
        $this->browser->request('GET', '/en/events/results-cup/standings/overall-solo');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('frame-ancestors', (string) $this->browser->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheLocaleAndTheDarkTheme(): void
    {
        $standingId = $this->overallSolo();

        $crawler = $this->browser->request('GET', '/embed/standing/' . $standingId . '/cs?theme=dark');
        self::assertResponseIsSuccessful();
        self::assertSame('cs', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('standing-embed--dark', (string) $crawler->filter('body')->attr('class'));
        self::assertSame('http://localhost/eventy/results-cup/poradi/overall-solo', $crawler->filter('[data-standing-embed-brand]')->attr('href'));
        // The live refetch asks for the same locale and theme
        self::assertSame('/embed/standing/' . $standingId . '/cs?theme=dark', $crawler->filter('#standing-embed')->attr('data-url'));
        self::assertSame(['1', '2', '2', '4'], self::ranks($crawler));

        // An unknown theme is the light one
        $crawler = $this->browser->request('GET', '/embed/standing/' . $standingId . '?theme=pink');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('standing-embed--light', (string) $crawler->filter('body')->attr('class'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testAnUnchangedEmbedAnswers304(): void
    {
        $standingId = $this->overallSolo();

        $this->browser->request('GET', '/embed/standing/' . $standingId);
        $etag = $this->browser->getResponse()->getEtag();
        self::assertIsString($etag);

        $this->browser->request('GET', '/embed/standing/' . $standingId, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testUnknownUnpublishedAndNotPublicStandingsAreAFrameable404(): void
    {
        $unpublished = PublishedStanding::insert($this->database, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'hidden', 'Hidden', 'solo', [OfficialResultsFixture::ROUND_GROUP_A], published: false);
        $published = $this->overallSolo();

        foreach (['/embed/standing/' . $unpublished, '/embed/standing/018d0020-0000-0000-0000-00000000dead'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
            self::assertSelectorExists('[data-standing-embed-not-found]');
            self::assertSame('frame-ancestors *', $this->browser->getResponse()->headers->get('Content-Security-Policy'));
            self::assertFalse($this->browser->getResponse()->headers->has('X-Frame-Options'));
            self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
            self::assertSame([], $this->browser->getResponse()->headers->getCookies());
        }

        // The event turning unapproved: gone at once (the visibility is read before anything cached)
        $this->database->executeStatement('UPDATE competition SET approved_at = NULL WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        $this->browser->request('GET', '/embed/standing/' . $published);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheEmbedIsRenderedOncePerGeneration(): void
    {
        $standingId = $this->overallSolo([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]);
        $url = '/embed/standing/' . $standingId;
        // The test pool is an array adapter emptied by the reset after every request (production: Redis, kept)
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('l3_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/standing-embed-test'));

        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-standing-state]', 'after 1 of 2 rounds');
        // The standing's event, visibility, the standing with its slugs, visibility (the builder's own), the rounds, the entries
        $this->assertQueryCountAtMost($this->browser, 7, 'cold');

        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->queryCount($this->browser), 'warm: ' . implode("\n", $this->executedSql($this->browser)));

        // The after-commit hook's new generation is seen at once
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);
        $this->browser->getContainer()->get(CompetitionDisplayLiveUpdates::class)->roundChanged(OfficialResultsFixture::ROUND_GROUP_B);
        $this->browser->request('GET', $url);
        self::assertSelectorNotExists('[data-standing-provisional]');
    }

    public function testTheOrganiserCopiesTheSnippetOfAPublishedStanding(): void
    {
        $published = $this->overallSolo();
        $unpublished = PublishedStanding::insert($this->database, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'hidden', 'Hidden', 'solo', [OfficialResultsFixture::ROUND_GROUP_A], published: false);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertResponseIsSuccessful();

        $box = $crawler->filter('[data-standing="' . $published . '"] [data-standing-embed-box]');
        self::assertCount(1, $box);
        $snippet = $box->filter('[data-standing-embed-snippet]')->text();
        self::assertStringStartsWith('<iframe src="http://localhost/embed/standing/' . strtolower($published) . '"', $snippet);
        self::assertStringContainsString('width="100%"', $snippet);
        self::assertStringContainsString('height="600"', $snippet);
        self::assertStringContainsString('title="Overall solo"', $snippet);
        self::assertSame('http://localhost/embed/standing/' . strtolower($published), $box->filter('[data-standing-embed-preview]')->attr('href'));

        // Nothing to embed before it is published
        self::assertCount(0, $crawler->filter('[data-standing="' . $unpublished . '"] [data-standing-embed-box]'));
    }

    /**
     * @param list<string> $roundIds
     */
    private function overallSolo(array $roundIds = [OfficialResultsFixture::ROUND_GROUP_A]): string
    {
        return PublishedStanding::insert($this->database, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'overall-solo', 'Overall solo', 'solo', $roundIds);
    }

    /**
     * @return list<string>
     */
    private static function ranks(Crawler $crawler): array
    {
        return array_values($crawler->filter('[data-standing-row]')->each(static fn (Crawler $row): string => (string) $row->attr('data-standing-rank')));
    }
}
