<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GettingStartedControllerTest extends WebTestCase
{
    /**
     * Anchors are linked from the Hub card and from empty states - renaming one breaks those links
     */
    private const array ANCHORS = ['track', 'profile', 'library', 'statistics', 'compare', 'favorites', 'more'];

    #[DataProvider('provideLocalizedUrls')]
    public function testGuideIsPublicInEveryLocale(string $url): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();

        foreach (self::ANCHORS as $anchor) {
            self::assertCount(1, $crawler->filter('#' . $anchor), sprintf('Missing #%s on %s', $anchor, $url));
        }

        // An untranslated key would be printed as is
        self::assertStringNotContainsString('onboarding.', $crawler->filter('main')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalizedUrls(): iterable
    {
        yield 'cs' => ['/jak-zacit'];
        yield 'en' => ['/en/getting-started'];
        yield 'es' => ['/es/primeros-pasos'];
        yield 'ja' => ['/ja/はじめに'];
        yield 'fr' => ['/fr/premiers-pas'];
        yield 'de' => ['/de/erste-schritte'];
    }

    public function testSignedInPlayerSeesWhatIsAlreadyDone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/getting-started');

        self::assertResponseIsSuccessful();
        // The regular fixture player has solving times
        self::assertCount(1, $crawler->filter('#track .guide-step-marker.is-done'));
    }

    public function testStepsThatLeaveNoTraceAreTickedOnceOpenedFromTheGuide(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/getting-started');
        self::assertCount(0, $crawler->filter('#statistics .guide-step-marker.is-done'));
        self::assertCount(0, $crawler->filter('#compare .guide-step-marker.is-done'));

        // What the mark-seen Stimulus controller sends when the button is clicked
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'guide_statistics_seen']);
        self::assertResponseStatusCodeSame(204);
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'guide_leaderboard_seen']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/getting-started');
        self::assertCount(1, $crawler->filter('#statistics .guide-step-marker.is-done'));
        self::assertCount(1, $crawler->filter('#compare .guide-step-marker.is-done'));
    }

    public function testHowProgressWorksLeadsTheMoreTilesOnceTheProgressionIsLive(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/getting-started');

        self::assertResponseIsSuccessful();
        $first = $crawler->filter('#more .guide-tile')->first();
        self::assertSame('/en/how-progress-works', $first->attr('href'));
        self::assertStringContainsString('How progress works', $first->text());

        // Before the launch switch the tile is not there (the hub's deep pages would 404)
        FixedXpLaunchReadiness::$ready = false;

        try {
            $crawler = $browser->request('GET', '/en/getting-started');
            self::assertCount(0, $crawler->filter('#more a.guide-tile[href="/en/how-progress-works"]'));
        } finally {
            FixedXpLaunchReadiness::$ready = true;
        }
    }

    public function testFooterLinksToTheGuideFromEveryPage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        self::assertGreaterThan(0, $crawler->filter('footer a[href="/en/getting-started"]')->count());
    }
}
