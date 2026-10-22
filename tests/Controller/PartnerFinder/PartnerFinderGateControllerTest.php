<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\PartnerFinder;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The partner finder's gate page (docs/features/partner-finder.md "Part D as built").
 */
final class PartnerFinderGateControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string GATE = '/en/partner-finder';
    private const string FINDER = '/en/events/' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG . '/partners';

    public function testAGuestSeesThePickerAndTheEventsWithPeopleLooking(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::GATE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Find a pair or a team for your next puzzle event');
        self::assertCount(3, $crawler->filter('.pfg-step'));

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $content);
        self::assertStringContainsString('hreflang="cs"', $content);

        // The picker: the fixture event with its finder's URL and "2 looking", the swap fair without a count
        $option = $crawler->filter('#pfg-event option[value="' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN . '"]');
        self::assertCount(1, $option);
        /** @var array{name: string, url: string, looking: null|string, flag: null|string} $data */
        $data = json_decode((string) $option->attr('data-data'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Partner Finder Open', $data['name']);
        self::assertSame(self::FINDER, $data['url']);
        self::assertSame('2 looking', $data['looking']);
        self::assertSame('fi fi-cz', $data['flag']);

        $swapFair = $crawler->filter('#pfg-event option[value="' . MarketplaceEventFixture::COMPETITION_SWAP_FAIR . '"]');
        self::assertCount(1, $swapFair);
        /** @var array{looking: null|string} $swapFairData */
        $swapFairData = json_decode((string) $swapFair->attr('data-data'), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($swapFairData['looking']);

        // Events with people looking: the one event somebody is listed at
        $cards = $crawler->filter('.pfg-card');
        self::assertCount(1, $cards);
        self::assertStringContainsString('Partner Finder Open', $cards->text());
        self::assertStringContainsString('2 looking for a partner', $cards->text());
        self::assertCount(1, $cards->filter('a[href="' . self::FINDER . '"]'));

        // Nothing of anybody's listing reaches the page
        self::assertStringNotContainsString('/player-profile/', $content);
    }

    public function testPickingAnEventWithoutJavaScriptRedirectsToItsFinder(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::GATE . '?event=' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);

        self::assertResponseRedirects(self::FINDER, 302);
    }

    public function testAnEventWithoutAFinderShowsThePageAgain(): void
    {
        $browser = self::createClient();

        foreach ([OrganizationFixture::COMPETITION_DRAFT_NIGHT, 'nonsense'] as $event) {
            $browser->request('GET', self::GATE . '?event=' . $event);

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('<meta name="robots" content="noindex, follow">', (string) $browser->getResponse()->getContent());
        }
    }

    public function testNobodyListedAnywhere(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE partner_listing SET closed_at = NOW() WHERE closed_at IS NULL');

        $crawler = $browser->request('GET', self::GATE);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pfg-card'));
        self::assertSelectorExists('.pfg-popular-empty');
        self::assertCount(1, $crawler->filter('#pfg-event option[value="' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN . '"]'));
    }

    public function testTheGuestPageCostsOneStatement(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::GATE);

        $this->startCountingQueries($browser);
        $browser->request('GET', self::GATE);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->queryCount($browser));

        // The redirect reads the same list
        $this->startCountingQueries($browser);
        $browser->request('GET', self::GATE . '?event=' . PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN);
        self::assertResponseRedirects(self::FINDER);
        self::assertSame(1, $this->queryCount($browser));
    }

    public function testASignedInPlayerGetsTheSamePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', self::GATE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.pfg-card'));
    }

    public function testTheStaticSitemapListsTheGate(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/sitemap-static.xml');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/en/partner-finder<', (string) $browser->getResponse()->getContent());
    }
}
