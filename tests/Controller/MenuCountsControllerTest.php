<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Menu's lazy numbers (route menu_counts, docs/features/navigation README §4.12 "Menu data" 3, MENU-01): the
 * viewer's own "Events you organize" count - the events page's "You organize (n)" rule - and what waits on Review your
 * results - the banner's count. Private, never stored, signed-in only.
 */
final class MenuCountsControllerTest extends WebTestCase
{
    public function testAnOrganiserGetsTheYouOrganizeCountAndTheReviewCount(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $data = $this->counts($browser);

        $organised = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_REGULAR)->organizedCount();
        self::assertGreaterThan(0, $organised, 'No canary: the organiser fixture organises nothing');
        self::assertSame([
            'organised' => $organised,
            'review' => self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer(PlayerFixture::PLAYER_REGULAR)->total(),
        ], $data);
    }

    public function testAPlayerWhoOrganisesNothingGetsNoEventCount(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        self::assertSame([
            'organised' => null,
            'review' => self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer(PlayerFixture::PLAYER_PRIVATE)->total(),
        ], $this->counts($browser));
    }

    public function testReviewYourResultsCountsWhatTheBannerCounts(): void
    {
        // Mia was told one of her results needs verification (SuspiciousTimesFixture) - the banner says 1
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_MARKED);

        $data = $this->counts($browser);

        self::assertSame(1, $data['review']);
        self::assertSame(self::getContainer()->get(GetPlayerReviewCounts::class)->forPlayer(SuspiciousTimesFixture::PLAYER_MARKED, withFirstTryConflicts: true)->total(), $data['review']);
    }

    public function testEveryLocaleHasTheEndpoint(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        foreach (['cs', 'de', 'en', 'es', 'fr', 'ja'] as $locale) {
            $browser->request('GET', '/' . $locale . '/menu-counts.json');
            self::assertResponseIsSuccessful($locale);
        }
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/menu-counts.json');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    /**
     * @return array{organised: null|int, review: int}
     */
    private function counts(KernelBrowser $browser): array
    {
        $browser->request('GET', '/en/menu-counts.json');

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        // The viewer's own numbers: never cached anywhere
        self::assertTrue($response->headers->hasCacheControlDirective('private'), (string) $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));

        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['organised', 'review'], array_keys($data));
        $organised = $data['organised'];
        $review = $data['review'];
        self::assertTrue($organised === null || is_int($organised));
        self::assertIsInt($review);

        return ['organised' => $organised, 'review' => $review];
    }
}
