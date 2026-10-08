<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The events page and its routes (docs/features/events-page/implementation-plan.md, 1.10). The markup of the list,
 * calendar, organiser tools and archive is covered by the workstreams' own tests.
 */
final class EventsControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocalizedUrls(): iterable
    {
        yield 'cs' => ['/eventy'];
        yield 'en' => ['/en/events'];
        yield 'es' => ['/es/eventos'];
        yield 'ja' => ['/ja/イベント'];
        yield 'fr' => ['/fr/evenements'];
        yield 'de' => ['/de/veranstaltungen'];
    }

    #[DataProvider('provideLocalizedUrls')]
    public function testRendersForGuestsPlayersAndAdminsInEveryLocale(string $url): void
    {
        $browser = self::createClient();

        foreach ([null, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN] as $playerId) {
            if ($playerId !== null) {
                TestingLogin::asPlayer($browser, $playerId);
            }

            $browser->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s as %s', $url, $playerId ?? 'guest'));
            self::assertSelectorExists('.ev-page script[data-events-index]');
        }
    }

    public function testOnlyAdminsSeeEventsWaitingForApproval(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events');
        self::assertSelectorTextNotContains('main', 'Unapproved Puzzle Event');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/events');
        self::assertSelectorTextNotContains('main', 'Unapproved Puzzle Event');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/events');
        self::assertSelectorTextContains('main', 'Unapproved Puzzle Event');
    }

    public function testACountryScopeHidesOnlineRows(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events?country=de');

        self::assertResponseIsSuccessful();

        $riverside = $crawler->filter('.ev-row')->reduce(static fn ($row): bool => str_contains($row->text(), EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME));
        self::assertCount(1, $riverside);
        self::assertNull($riverside->attr('hidden'));
        self::assertSame('de', $riverside->attr('data-ev-scope'));

        $harbor = $crawler->filter('.ev-row')->reduce(static fn ($row): bool => str_contains($row->text(), EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME));
        self::assertGreaterThan(0, $harbor->count());

        $harbor->each(static function ($row): void {
            self::assertNotNull($row->attr('hidden'), 'an online row is hidden in a country view');
        });
    }

    public function testFollowWithJson(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, '/en/follow-event', 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, json: true);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['following' => true, 'target' => 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN],
            json_decode((string) $browser->getResponse()->getContent(), true),
        );

        $this->post($browser, '/en/unfollow-event', 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, json: true);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['following' => false, 'target' => 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN],
            json_decode((string) $browser->getResponse()->getContent(), true),
        );
    }

    public function testFollowWithoutJavaScriptRedirectsBack(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, '/en/follow-event', 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, return: '/en/events?country=at');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/en/events?country=at');
        $browser->followRedirect();
        self::assertSelectorTextContains('main', 'You follow ' . EventsPageFixture::SERIES_SUMMIT_LEAGUE_NAME);

        // An off-site return falls back to the events page
        $this->post($browser, '/en/unfollow-event', 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, return: '//evil.example.com/');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/en/events');
    }

    public function testABadTokenIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, '/en/follow-event', 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, json: true, foreignOrigin: true);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', (array) json_decode((string) $browser->getResponse()->getContent(), true));

        $this->post($browser, '/en/follow-event', 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, foreignOrigin: true);
        self::assertResponseRedirects('/en/events');
    }

    public function testAnEditionCannotBeFollowedOnItsOwn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, '/en/follow-event', 'competition:' . EventsPageFixture::EDITION_HARBOR_1, json: true);
        self::assertResponseStatusCodeSame(404);

        $this->post($browser, '/en/follow-event', 'competition:' . EventsPageFixture::EDITION_HARBOR_1);
        self::assertResponseRedirects('/en/events');
    }

    public function testGuestsAreSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/follow-event', ['target' => 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testOrganizedEventsPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/you-organize');
        self::assertResponseRedirects();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/you-organize');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED_NAME);
        self::assertSelectorTextContains('main', EventsPageFixture::GARDEN_SWAP_REJECTION_REASON);
        self::assertSelectorTextContains('main', 'Unapproved Puzzle Event');
        self::assertCount(3, $browser->getCrawler()->filter('[data-organized-id]'));
        self::assertSelectorExists('[data-organized-id="' . CompetitionFixture::COMPETITION_RECURRING_ONLINE . '"]');
    }

    public function testArchiveYears(): void
    {
        $browser = self::createClient();
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        $year = (int) $clock->now()->format('Y');

        $browser->request('GET', '/en/events/archive/' . ($year - 1));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', EventsPageFixture::COMPETITION_VALLEY_CUP_NAME);

        $browser->request('GET', '/en/events/archive/1999');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/events/archive/' . ($year + 1));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Stateless CSRF: any token, as long as the request comes from our own origin - a foreign origin is a bad token
     */
    private function post(KernelBrowser $browser, string $url, string $target, bool $json = false, bool $foreignOrigin = false, string $return = ''): void
    {
        $server = ['HTTP_ORIGIN' => $foreignOrigin ? 'https://evil.example' : 'http://localhost'];

        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $browser->request('POST', $url, ['_token' => 'csrf-token', 'target' => $target, 'return' => $return], server: $server);
    }
}
