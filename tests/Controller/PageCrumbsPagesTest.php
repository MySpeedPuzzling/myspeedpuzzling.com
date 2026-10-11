<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The breadcrumb + back button row (templates/_page_crumbs.html.twig, docs/features/xp-levels/ux-polish/design-spec.md §3):
 * the back button goes to a valid ?return= or the logical parent, the trail lists the parents, public pages carry the
 * BreadcrumbList JSON-LD, the owner pages only the back button next to the compact player strip.
 */
final class PageCrumbsPagesTest extends WebTestCase
{
    private const string HUB = '/en/how-progress-works';

    /**
     * @return iterable<string, array{string, non-empty-list<string>, non-empty-string}>
     */
    public static function publicPages(): iterable
    {
        yield 'how XP works' => ['/en/how-xp-works', [self::HUB], 'How XP & Levels work'];
        yield 'fair play' => ['/en/fair-play-xp', [self::HUB, '/en/how-xp-works'], 'Fair play'];
        yield 'how karma works' => ['/en/how-karma-works', [self::HUB], 'How karma works'];
        yield 'methodology' => ['/en/methodology', [self::HUB], 'Puzzle Difficulty'];
    }

    /**
     * @param non-empty-list<string> $parents
     * @param non-empty-string $currentStart
     */
    #[DataProvider('publicPages')]
    public function testPublicPageHasTrailBackToItsParentAndJsonLd(string $url, array $parents, string $currentStart): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();

        $trail = $crawler->filter('.page-crumbs-trail a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame($parents, $trail);

        $back = $crawler->filter('.page-crumbs-back');
        self::assertCount(1, $back);
        self::assertSame($parents[count($parents) - 1], $back->attr('href'));
        self::assertStringStartsWith('Back to ', (string) $back->attr('aria-label'));
        self::assertSame($back->attr('aria-label'), $back->attr('title'));
        self::assertNull($back->attr('data-controller'), 'The parent is a plain link, never history.back()');

        $breadcrumbList = $this->breadcrumbJsonLd($crawler);
        self::assertNotNull($breadcrumbList, 'Public pages carry the BreadcrumbList JSON-LD');
        /** @var list<array<string, mixed>> $items */
        $items = $breadcrumbList['itemListElement'];
        self::assertCount(count($parents) + 1, $items);

        foreach ($parents as $index => $parent) {
            self::assertSame($index + 1, $items[$index]['position']);
            self::assertSame('http://localhost' . $parent, $items[$index]['item']);
        }

        $current = $items[count($parents)];
        self::assertArrayNotHasKey('item', $current, 'The current page is the last item, without `item`');
        self::assertIsString($current['name']);
        self::assertStringStartsWith($currentStart, $current['name']);
    }

    public function testFairPlayNamesItsParentByThatPagesTitle(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/fair-play-xp');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('.page-crumbs-trail a');
        // The crumb says what the parent page's heading says (QA 2026-10-11: "Level & XP" vs "How XP & Levels work")
        self::assertSame('How XP & Levels work', trim($links->eq(1)->text()));
        self::assertSame('Back to How XP & Levels work', $crawler->filter('.page-crumbs-back')->attr('aria-label'));

        $breadcrumbList = $this->breadcrumbJsonLd($crawler);
        self::assertNotNull($breadcrumbList);
        /** @var list<array<string, mixed>> $items */
        $items = $breadcrumbList['itemListElement'];
        self::assertSame('How XP & Levels work', $items[1]['name']);
    }

    public function testValidReturnIsHonouredWithItsTitleAndStaysOutOfTheCanonical(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/how-xp-works?return=' . rawurlencode('/en/ladder?pieces=500') . '&return_title=Leaderboards');

        self::assertResponseIsSuccessful();
        $back = $crawler->filter('.page-crumbs-back');
        self::assertSame('/en/ladder?pieces=500', $back->attr('href'));
        self::assertSame('Back to Leaderboards', $back->attr('aria-label'));
        // A ?return= that is the page we came from goes back in history (design-spec §8.7)
        self::assertSame('page-back', $back->attr('data-controller'));
        self::assertSame('page-back#back', $back->attr('data-action'));

        // The trail still says where the page lives
        self::assertSame(self::HUB, $crawler->filter('.page-crumbs-trail a')->attr('href'));

        $canonical = (string) $crawler->filter('link[rel="canonical"]')->attr('href');
        self::assertSame('http://localhost/en/how-xp-works', $canonical);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileReturns(): iterable
    {
        yield 'scheme-relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'absolute' => ['https://evil.example/'];
        yield 'encoded' => ['%2F%2Fevil.example'];
    }

    #[DataProvider('hostileReturns')]
    public function testHostileReturnFallsBackToTheParent(string $return): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/how-karma-works?return=' . rawurlencode($return) . '&return_title=Evil');

        self::assertResponseIsSuccessful();
        $back = $crawler->filter('.page-crumbs-back');
        self::assertSame(self::HUB, $back->attr('href'));
        self::assertSame('Back to How progress works', $back->attr('aria-label'));
        self::assertNull($back->attr('data-controller'));
        self::assertCount(0, $crawler->filter('a[href*="evil.example"]:not([href^="/"])'), 'No link leaves the site');
        self::assertStringNotContainsString('Back to Evil', (string) $browser->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownerPages(): iterable
    {
        yield 'XP history' => ['/en/my/xp-history'];
        yield 'karma history' => ['/en/my/karma'];
    }

    #[DataProvider('ownerPages')]
    public function testOwnerPageHasOnlyTheBackButtonNextToTheStrip(string $url): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $crawler->filter('.page-crumbs-back')->attr('href'));
        self::assertCount(1, $crawler->filter('.page-crumbs > .player-strip'), 'The compact player strip is the trail');
        self::assertCount(0, $crawler->filter('.page-crumbs-trail'));
        self::assertNull($this->breadcrumbJsonLd($crawler), 'Owner pages carry no BreadcrumbList');

        $crawler = $browser->request('GET', $url . '?return=' . rawurlencode('/en/hub') . '&return_title=Hub');
        self::assertSame('/en/hub', $crawler->filter('.page-crumbs-back')->attr('href'));
        self::assertSame('Back to Hub', $crawler->filter('.page-crumbs-back')->attr('aria-label'));
    }

    public function testRevealPageHasTheBackButtonToTheOwnProfile(): void
    {
        $browser = $this->signedIn();
        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        if ($browser->getResponse()->isRedirection()) {
            self::markTestSkipped('No XP reveal for this fixture player');
        }

        self::assertResponseIsSuccessful();
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $crawler->filter('.page-crumbs-back')->attr('href'));
        self::assertNull($this->breadcrumbJsonLd($crawler));
    }

    public function testHubSendsTheBoardsAndFairPlayLinksWithReturn(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::HUB);

        self::assertResponseIsSuccessful();
        $fairPlay = $crawler->filter('a[href^="/en/fair-play-xp?"]');
        self::assertCount(1, $fairPlay);
        self::assertSame('nofollow', $fairPlay->attr('rel'));
        self::assertStringContainsString('return=' . self::HUB, (string) $fairPlay->attr('href'));

        // The cards stay clean, followed links: their pages go back to the hub anyway
        self::assertCount(1, $crawler->filter('a.progression-hub-row[href="/en/how-xp-works"]'));
    }

    public function testHubIsTopLevelSoItsBackButtonComesOnlyWithReturn(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::HUB);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.page-crumbs-back'));

        $crawler = $browser->request('GET', self::HUB . '?return=/en/achievements&return_title=Achievements');
        self::assertSame('/en/achievements', $crawler->filter('.page-crumbs-back')->attr('href'));
        self::assertSame('Back to Achievements', $crawler->filter('.page-crumbs-back')->attr('aria-label'));

        // The achievements reference sends its link to the hub with the way back
        $crawler = $browser->request('GET', '/en/achievements');
        self::assertCount(1, $crawler->filter('a[href^="' . self::HUB . '?return=/en/achievements"][rel="nofollow"]'));
    }

    public function testPlayerHeaderXpRingCarriesReturnToTheProfile(): void
    {
        $browser = $this->signedIn();
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN;
        $crawler = $browser->request('GET', $profile);

        self::assertResponseIsSuccessful();
        $ring = $crawler->filter('a.xp-avatar-ring');

        if ($ring->count() === 0) {
            self::markTestSkipped('The fixture player shows no XP ring');
        }

        $href = (string) $ring->first()->attr('href');
        self::assertStringStartsWith('/en/how-xp-works?', $href);
        self::assertStringContainsString('return=' . $profile, $href);
        self::assertSame('nofollow', $ring->first()->attr('rel'));

        $crawler = $browser->request('GET', $href);
        self::assertSame($profile, $crawler->filter('.page-crumbs-back')->attr('href'));
    }

    private function signedIn(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function breadcrumbJsonLd(Crawler $crawler): null|array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            if (($data['@type'] ?? null) === 'BreadcrumbList') {
                /** @var array<string, mixed> $data */
                return $data;
            }
        }

        return null;
    }
}
