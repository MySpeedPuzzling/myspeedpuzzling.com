<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\AssertsInPageConfirmations;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The personal links of the Online Test Cup (docs/features/online-competitions/README.md §5.4, stream C1): the links
 * page (online_links), its CSV (online_links_csv) and QR sheet (online_links_qr_sheet), and "New link"
 * (online_link_regenerate). Olivia (linked to PLAYER_REGULAR) and Arthur have links; Nora, going without one, gets hers
 * when the organiser opens any of the three. Every page is a secret: never cached.
 */
final class OnlineLinksControllerTest extends WebTestCase
{
    use AssertsInPageConfirmations;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string LINKS = '/en/online-competitions/' . self::COMPETITION . '/links';
    private const string REMOVED = '018d0050-0000-0000-0000-0000000002c1';
    private const string WAITLISTED = '018d0050-0000-0000-0000-0000000002c2';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testEverybodyGoingIsListedWithTheirLink(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::assertNull($this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));

        $crawler = $browser->request('GET', self::LINKS);

        self::assertResponseIsSuccessful();
        self::assertNeverCached($browser);

        // By name; Nora - added after the links were made - got hers as the page opened
        $nora = $this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($nora);
        self::assertSame([
            OnlineRoundsFixture::PARTICIPANT_ANONYMOUS => 'http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS,
            OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN => 'http://localhost/en/compete/' . $nora,
            OnlineRoundsFixture::PARTICIPANT_CONNECTED => 'http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED,
        ], $this->links($crawler));

        $crawler->filter('[data-online-link]')->each(static function (Crawler $row): void {
            $id = (string) $row->attr('data-online-link');
            $name = $row->filter('th[scope="row"] [data-online-link-name]')->text();

            // No QR drawn on the page (hundreds of rows) - each row opens its own card of the QR sheet
            self::assertCount(0, $row->filter('svg'));
            self::assertSame(self::LINKS . '/qr-sheet?participant=' . $id, $row->filter('[data-online-link-qr]')->attr('href'));
            self::assertSame('QR code of the personal link of ' . $name, $row->filter('[data-online-link-qr]')->attr('aria-label'));
            // Every button says whose link it is
            self::assertSame('Copy the personal link of ' . $name, $row->filter('[data-online-link-copy]')->attr('aria-label'));
            self::assertSame('New link for ' . $name, $row->filter('[data-online-link-regenerate]')->attr('aria-label'));
            self::assertSame('csrf-token', $row->filter('form input[name="_token"]')->attr('value'));
        });

        // The round uses online timing; the CSV and the QR sheet are one click away
        self::assertSame('on', $crawler->filter('[data-online-round="' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '"]')->attr('data-online'));
        self::assertSame(self::LINKS . '.csv', $crawler->filter('[data-online-links-csv]')->attr('href'));
        self::assertSame(self::LINKS . '/qr-sheet', $crawler->filter('[data-online-links-qr-sheet]')->attr('href'));

        // A second view writes nothing more
        $tokens = $this->tokens($browser);
        $browser->request('GET', self::LINKS);
        self::assertSame($tokens, $this->tokens($browser));
    }

    /**
     * "New link" stops the current link at once, so it asks first - in the page (docs/features/navigation GEST-13: iOS
     * Safari refuses native dialogs after a Back to a layer's entry), as a danger, the action named on its button
     */
    public function testANewLinkIsAskedForInThePage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::LINKS);
        self::assertResponseIsSuccessful();
        self::assertAsksInThePage($crawler, 3);

        $crawler->filter('[data-online-link]')->each(static function (Crawler $row): void {
            $name = $row->filter('th[scope="row"] [data-online-link-name]')->text();
            $form = $row->filter('[data-online-link-regenerate]')->closest('form');
            self::assertNotNull($form);

            self::assertSame('The current link of ' . $name . ' will stop working at once. Create a new one?', $form->attr('data-turbo-confirm'));
            self::assertNotNull($form->attr('data-turbo-confirm-danger'));
            self::assertNull($form->attr('onsubmit'));
            self::assertSame('New link', $row->filter('[data-online-link-regenerate]')->attr('data-turbo-confirm-action'));
        });
    }

    public function testTheLinkedProfileIsShownTheWayTheParticipantsSheetShowsIt(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::LINKS);
        $profile = $this->row($crawler, OnlineRoundsFixture::PARTICIPANT_CONNECTED)->filter('[data-online-link-profile]');
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME . ' #PLAYER1', $profile->text());
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $profile->filter('a')->attr('href'));
        self::assertSame('—', $this->row($crawler, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS)->filter('[data-online-link-profile]')->text());

        // The viewer blocks Olivia's player: the row stays, the profile's identity does not
        $this->connection($browser)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES ('018d0050-0000-0000-0000-0000000009c1', :blocker, :blocked, NOW(), 'self')",
            ['blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', self::LINKS);
        $profile = $this->row($crawler, OnlineRoundsFixture::PARTICIPANT_CONNECTED)->filter('[data-online-link-profile]');
        self::assertSame('Linked to a MySpeedPuzzling profile', $profile->text());
        self::assertCount(0, $profile->filter('a'));
        self::assertSame('Olivia Online', $this->row($crawler, OnlineRoundsFixture::PARTICIPANT_CONNECTED)->filter('[data-online-link-name]')->text());
    }

    public function testRemovedAndWaitlistedPeopleGetNoLink(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addPeopleWhoDoNotCompete($browser);

        $crawler = $browser->request('GET', self::LINKS);

        self::assertCount(3, $crawler->filter('[data-online-link]'));
        self::assertCount(0, $crawler->filter('[data-online-link="' . self::REMOVED . '"]'));
        self::assertCount(0, $crawler->filter('[data-online-link="' . self::WAITLISTED . '"]'));
        self::assertStringContainsString('1 person on the waitlist gets their link once they have a spot.', $crawler->filter('[data-online-links-waitlisted]')->text());
        self::assertNull($this->token($browser, self::REMOVED));
        self::assertNull($this->token($browser, self::WAITLISTED));
    }

    public function testAnEventWithoutOnlineTimingListsItsRoundsAndNoLinks(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        // In person: no online timing at all
        $crawler = $browser->request('GET', '/en/online-competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/links');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-online-rounds-in-person]'));
        self::assertCount(0, $crawler->filter('[data-online-links]'));

        // Online, but no round uses online timing yet: the round's set-up, no links made
        $this->connection($browser)->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        $crawler = $browser->request('GET', self::LINKS);
        self::assertResponseIsSuccessful();
        self::assertSame('off', $crawler->filter('[data-online-round="' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '"]')->attr('data-online'));
        self::assertSame('Set up', trim($crawler->filter('[data-online-round-settings-link]')->text()));
        self::assertStringStartsWith('/en/online-rounds/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/settings?return=', (string) $crawler->filter('[data-online-round-settings-link]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-online-links]'));
        self::assertNull($this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));
    }

    public function testOnlyOrganisersForWhomTheFeatureIsOnSeeTheLinks(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::LINKS);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        // PLAYER_WITH_STRIPE organises the Online Test Cup too
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([self::LINKS, self::LINKS . '.csv', self::LINKS . '/qr-sheet'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach ([self::LINKS, self::LINKS . '.csv', self::LINKS . '/qr-sheet'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        // A participant is no organiser
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::LINKS);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCsvHasEveryLinkAndNoFormula(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addPeopleWhoDoNotCompete($browser);
        // A name somebody typed to be run by a spreadsheet
        $this->connection($browser)->executeStatement(
            "UPDATE competition_participant SET name = '=HYPERLINK(\"http://example.com\",\"Arthur\")' WHERE id = :id",
            ['id' => OnlineRoundsFixture::PARTICIPANT_ANONYMOUS],
        );

        $browser->request('GET', self::LINKS . '.csv');

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertNeverCached($browser);
        self::assertStringContainsString('attachment; filename=online-test-cup-personal-links.csv', (string) $response->headers->get('Content-Disposition'));

        $csv = (string) $response->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($csv, 3))));
        $nora = $this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($nora, 'the CSV gives the missing links too');

        // Links without a language (stream RP): each participant's page speaks their own, never the organiser's page's
        self::assertSame([
            ['Name', 'Personal link'],
            // Text, never a formula
            ['\'=HYPERLINK("http://example.com","Arthur")', 'http://localhost/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS],
            ['Nora Notoken', 'http://localhost/compete/' . $nora],
            ['Olivia Online', 'http://localhost/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED],
        ], $lines);

        // The same from the Czech links page
        $browser->request('GET', '/online-souteze/' . self::COMPETITION . '/odkazy.csv');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('http://localhost/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED, (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString('/soutezit/', (string) $browser->getResponse()->getContent());
    }

    public function testTheQrSheetHasACardForEverybodyGoing(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addPeopleWhoDoNotCompete($browser);

        $crawler = $browser->request('GET', self::LINKS . '/qr-sheet');

        self::assertResponseIsSuccessful();
        self::assertNeverCached($browser);
        self::assertCount(3, $crawler->filter('[data-online-qr-card]'));
        self::assertSame('3 cards', $crawler->filter('[data-online-qr-count]')->text());

        $olivia = $crawler->filter('[data-online-qr-card="' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '"]');
        self::assertStringContainsString('Olivia Online', $olivia->text());
        self::assertSame('localhost/en/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED, $olivia->filter('[data-online-qr-link]')->text());
        self::assertCount(1, $olivia->filter('svg'));
        self::assertSame('QR code of the personal link of Olivia Online', $olivia->filter('[role="img"]')->attr('aria-label'));

        // A heading and the landmarks of a page
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main [data-online-qr-card="' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '"]'));
        self::assertCount(1, $crawler->filter('header [data-online-qr-count]'));

        // One participant's card - a row's "QR code" on the links page
        $crawler = $browser->request('GET', self::LINKS . '/qr-sheet?participant=' . OnlineRoundsFixture::PARTICIPANT_CONNECTED);
        self::assertSame([OnlineRoundsFixture::PARTICIPANT_CONNECTED], $crawler->filter('[data-online-qr-card]')->each(
            static fn (Crawler $card): string => (string) $card->attr('data-online-qr-card'),
        ));
        self::assertSame('1 card', $crawler->filter('[data-online-qr-count]')->text());
    }

    public function testANewLinkReplacesTheOldOne(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', self::LINKS);

        // The participant API's cached heartbeat lookup of the old link (OnlineAccessLight, contract §6)
        $cache = self::getContainer()->get('cache.app');
        $cache->get(OnlineAccessLight::cacheKey(OnlineRoundsFixture::TOKEN_CONNECTED), static fn (ItemInterface $item): string => 'cached lookup');

        $browser->submit($this->row($crawler, OnlineRoundsFixture::PARTICIPANT_CONNECTED)->filter('form')->form());

        self::assertResponseRedirects(self::LINKS . '#participant-' . OnlineRoundsFixture::PARTICIPANT_CONNECTED, 303);
        $token = $this->token($browser, OnlineRoundsFixture::PARTICIPANT_CONNECTED);
        self::assertNotNull($token);
        self::assertNotSame(OnlineRoundsFixture::TOKEN_CONNECTED, $token);
        self::assertTrue(OnlineToken::looksValid($token));
        // Nobody else's link changed
        self::assertSame(OnlineRoundsFixture::TOKEN_ANONYMOUS, $this->token($browser, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        // Dropped once the new link was committed - the next heartbeat on the old link reads it again
        self::assertSame('read again', self::getContainer()->get('cache.app')->get(
            OnlineAccessLight::cacheKey(OnlineRoundsFixture::TOKEN_CONNECTED),
            static fn (ItemInterface $item): string => 'read again',
        ));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Olivia Online has a new personal link. The old one no longer works.', $crawler->filter('.alert-success')->text());
        self::assertSame('http://localhost/en/compete/' . $token, $this->links($crawler)[OnlineRoundsFixture::PARTICIPANT_CONNECTED]);
    }

    public function testANewLinkIsRefusedWhileNoRoundUsesOnlineTiming(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::LINKS);
        $this->connection($browser)->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);

        $browser->request('POST', self::LINKS . '/' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '/regenerate', ['_token' => 'csrf-token']);

        self::assertResponseRedirects(self::LINKS . '#participant-' . OnlineRoundsFixture::PARTICIPANT_CONNECTED, 303);
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $this->token($browser, OnlineRoundsFixture::PARTICIPANT_CONNECTED));
        self::assertStringContainsString('No round of this event uses online timing', $browser->followRedirect()->filter('.alert-warning')->text());
    }

    public function testANewLinkNeedsTheFormsToken(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::LINKS);

        $browser->request('POST', self::LINKS . '/' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '/regenerate', ['_token' => 'forged']);

        self::assertResponseRedirects(self::LINKS . '#participant-' . OnlineRoundsFixture::PARTICIPANT_CONNECTED, 303);
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $this->token($browser, OnlineRoundsFixture::PARTICIPANT_CONNECTED));
        self::assertStringContainsString('The page was open for too long', $browser->followRedirect()->filter('.alert-danger')->text());

        // Without any token
        $browser->request('POST', self::LINKS . '/' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '/regenerate');
        self::assertResponseRedirects();
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $this->token($browser, OnlineRoundsFixture::PARTICIPANT_CONNECTED));
    }

    public function testANewLinkOnlyForAParticipantOfTheEvent(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addPeopleWhoDoNotCompete($browser);
        $browser->request('GET', self::LINKS);

        // Removed, or of another event: not found
        $browser->request('POST', self::LINKS . '/' . self::REMOVED . '/regenerate', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', self::LINKS . '/' . OfficialResultsFixture::PARTICIPANT_ANNA . '/regenerate', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);

        // Waitlisted: said, nothing made
        $browser->request('GET', self::LINKS);
        $browser->request('POST', self::LINKS . '/' . self::WAITLISTED . '/regenerate', ['_token' => 'csrf-token']);
        self::assertResponseRedirects(self::LINKS . '#participant-' . self::WAITLISTED, 303);
        self::assertStringContainsString('Walter Waiting is on the waitlist', $browser->followRedirect()->filter('.alert-warning')->text());
        self::assertNull($this->token($browser, self::WAITLISTED));
    }

    /**
     * The links are secrets: no browser or proxy keeps a copy (Symfony adds its own directives for a session)
     */
    private static function assertNeverCached(KernelBrowser $browser): void
    {
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function row(Crawler $crawler, string $participantId): Crawler
    {
        $row = $crawler->filter('[data-online-link="' . $participantId . '"]');
        self::assertCount(1, $row, $participantId);

        return $row;
    }

    /**
     * @return array<string, string> participant id => the link shown
     */
    private function links(Crawler $crawler): array
    {
        $links = [];

        $crawler->filter('[data-online-link]')->each(static function (Crawler $row) use (&$links): void {
            $links[(string) $row->attr('data-online-link')] = (string) $row->filter('[data-online-link-url]')->attr('value');
        });

        return $links;
    }

    private function addPeopleWhoDoNotCompete(KernelBrowser $browser): void
    {
        $this->connection($browser)->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source, deleted_at) VALUES (:id, 'Rita Removed', :competition, 'imported', NOW())",
            ['id' => self::REMOVED, 'competition' => self::COMPETITION],
        );
        $this->connection($browser)->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source, registration_status, registered_at) VALUES (:id, 'Walter Waiting', :competition, 'self_joined', 'waitlisted', NOW())",
            ['id' => self::WAITLISTED, 'competition' => self::COMPETITION],
        );
    }

    private function token(KernelBrowser $browser, string $participantId): null|string
    {
        $token = $this->connection($browser)->fetchOne('SELECT online_token FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($token) ? $token : null;
    }

    /**
     * @return array<string, null|string>
     */
    private function tokens(KernelBrowser $browser): array
    {
        /** @var array<string, null|string> $tokens */
        $tokens = $this->connection($browser)->fetchAllKeyValue(
            'SELECT id, online_token FROM competition_participant WHERE competition_id = :competition ORDER BY id',
            ['competition' => self::COMPETITION],
        );

        return $tokens;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
