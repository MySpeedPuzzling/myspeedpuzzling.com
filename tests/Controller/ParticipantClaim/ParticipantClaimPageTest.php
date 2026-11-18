<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantClaim;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\ParticipantClaim\ParticipantClaimController;
use SpeedPuzzling\Web\Results\ParticipantClaimPage;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * A personal claim link's page (docs/features/competitions-management/participant-linking.md §6.5): every state of the
 * table, the buttons (303), the link without a language, the headers that keep the token private and the limiter.
 * WJPC 2024: Jane Unconnected (PARTICIPANT_UNCONNECTED) holds the link; John Regular is PLAYER_REGULAR's row (import),
 * Michael Johnson PLAYER_WITH_FAVORITES' own row.
 */
final class ParticipantClaimPageTest extends WebTestCase
{
    private const string TOKEN = 'C1aimJaneUnconnected22';
    private const string PAGE = '/en/claim/' . self::TOKEN;
    private const string NOT_VALID = 'This link is not valid any more. Ask the organiser for a new one.';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->database->executeStatement('UPDATE competition_participant SET claim_token = :token WHERE id = :id', ['token' => self::TOKEN, 'id' => Wjpc::PARTICIPANT_UNCONNECTED]);
    }

    public function testAnUnknownLinkIsNotValidAndNamesNothing(): void
    {
        $page = $this->browser->request('GET', '/en/claim/UnknownCa1mTokenHere22');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('not_valid', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertSelectorTextContains('[data-claim-message]', self::NOT_VALID);
        $this->assertPrivate();

        // Asked again: the same, from the memo
        $this->browser->request('GET', '/en/claim/UnknownCa1mTokenHere22');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARemovedParticipantsLinkIsNotValid(): void
    {
        $this->database->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]);

        $page = $this->browser->request('GET', self::PAGE);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('[data-claim-message]', self::NOT_VALID);
        self::assertStringNotContainsString('Jane Unconnected', $page->text());
    }

    /**
     * A draft names nothing to anybody off its team; its team learns the link works once it is published
     */
    public function testADraftsLinkIsNotValidOffItsTeam(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);

        foreach ([null, PlayerFixture::PLAYER_REGULAR] as $viewer) {
            $this->browser->restart();

            if ($viewer !== null) {
                TestingLogin::asPlayer($this->browser, $viewer);
            }

            $page = $this->browser->request('GET', self::PAGE);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertStringNotContainsString('Jane Unconnected', $page->text());
            self::assertStringNotContainsString('WJPC 2024', $page->filter('[data-claim-state]')->text());
        }

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $page = $this->browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSame('draft_team', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertSelectorTextContains('[data-claim-message]', 'This link works once the event is published.');

        // Nothing links on a draft - not even for its team
        $this->browser->request('POST', self::PAGE, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
        self::assertNull($this->linkedPlayer());
    }

    public function testAGuestIsAskedToSignInOrCreateAnAccountAndComesBack(): void
    {
        $page = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('guest', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertSelectorTextContains('[data-claim-name]', 'Jane Unconnected');
        self::assertSelectorTextContains('[data-claim-event]', 'WJPC 2024');
        self::assertSelectorTextContains('[data-claim-message]', 'Jane Unconnected - is this you?');
        self::assertSame('/login?return=' . self::PAGE, $page->filter('[data-claim-sign-in]')->attr('href'));
        self::assertSame('/register?return=' . self::PAGE, $page->filter('[data-claim-register]')->attr('href'));
        self::assertCount(0, $page->filter('[data-claim-state] form'));
        $this->assertPrivate();

        // A guest's POST goes to the sign-in page
        $this->browser->request('POST', self::PAGE, ['_token' => 'csrf-token']);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertNull($this->linkedPlayer());
    }

    public function testASignedInPlayerLinksTheirProfile(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $page = $this->browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSame('unlinked', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        // A GET never writes
        self::assertNull($this->linkedPlayer());

        $this->confirm($page->filter('[data-claim-confirm]')->closest('form')?->filter('input[name="_token"]')->attr('value'));

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertStringEndsWith('#taking-part', (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkedPlayer());
        self::assertSame('claim_link', $this->database->fetchOne('SELECT connected_via FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Linked - this name on the participant list is you now.');

        // Opened again
        $page = $this->browser->request('GET', self::PAGE);
        self::assertSame('already_yours', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertStringEndsWith('#taking-part', (string) $page->filter('[data-claim-open-event]')->attr('href'));
    }

    public function testAnExpiredFormLinksNothing(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('POST', self::PAGE, ['_token' => 'forged'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
        self::assertNull($this->linkedPlayer());
    }

    public function testANameLinkedToAnotherProfileOffersTheDispute(): void
    {
        $this->database->executeStatement(
            "UPDATE competition_participant SET player_id = :player, connected_at = " . TestClock::SQL_NOW . ", connected_via = 'organiser' WHERE id = :id",
            ['player' => PlayerFixture::PLAYER_PRIVATE, 'id' => Wjpc::PARTICIPANT_UNCONNECTED],
        );
        // PLAYER_PRIVATE's own row of the event is let go of first (one row per player)
        $this->database->executeStatement('UPDATE competition_participant SET player_id = NULL WHERE id = :id', ['id' => Wjpc::PARTICIPANT_PRIVATE]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $page = $this->browser->request('GET', self::PAGE);
        self::assertSame('belongs_to_other', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertSelectorTextContains('[data-claim-message]', 'This name is already linked to another profile.');
        // Nothing of the other profile
        self::assertStringNotContainsString('Secret', $page->filter('[data-claim-state]')->text());

        $this->confirm('csrf-token', ['action' => 'dispute']);

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $this->linkedPlayer());
        self::assertSame(
            ['player_id' => PlayerFixture::PLAYER_ADMIN, 'kind' => 'dispute', 'status' => 'pending'],
            $this->database->fetchAssociative('SELECT player_id, kind, status FROM participant_link_request WHERE participant_id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]),
        );
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'We asked the organiser to check who this name belongs to.');
    }

    public function testSomebodyOnTheListAsAnotherPersonSwitches(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $page = $this->browser->request('GET', self::PAGE);
        self::assertSame('other_participant', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertSelectorTextContains('[data-claim-message]', 'You are on this list as Michael Johnson. Switch to Jane Unconnected?');

        // Without "Switch" nothing changes - the page asks again
        $this->confirm('csrf-token');
        self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
        self::assertNull($this->linkedPlayer());

        $this->confirm('csrf-token', ['switch' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer());
        self::assertNotNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_SELF_JOINED]));
    }

    /**
     * `/claim/{token}` - what the slips, the CSV and the copied links hand out: the page in the visitor's language
     */
    public function testTheLinkWithoutALanguageLeadsToThePage(): void
    {
        $this->browser->request('GET', '/claim/' . self::TOKEN, server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        self::assertResponseRedirects('/de/claim/' . self::TOKEN);
        $this->assertPrivate();

        $this->browser->request('GET', '/claim/' . self::TOKEN);
        self::assertResponseRedirects(self::PAGE);
    }

    /**
     * Limiter participant_claim_page: a guest per client IP - this test's own address, so no other test meets the limit
     */
    public function testTooManyVisitsFromOneAddressAreStopped(): void
    {
        $address = '203.0.113.47';
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.participant_claim_page');
        $limiter->create($address)->reset();
        $limiter->create($address)->consume(100000);

        try {
            $page = $this->browser->request('GET', self::PAGE, server: ['REMOTE_ADDR' => $address]);

            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            self::assertSame('too_many', $page->filter('[data-claim-state]')->attr('data-claim-state'));
            self::assertStringNotContainsString('Jane Unconnected', $page->text());
        } finally {
            $limiter->create($address)->reset();
        }
    }

    /**
     * Signed in, the limit counts per player (participant_claim_page_player) - a venue's shared Wi-Fi is one address for a
     * whole hall at check-in. PLAYER_PRIVATE opens no claim page in any other test: the budget is theirs only.
     */
    public function testASignedInPlayerIsCountedByPlayerNotByAddress(): void
    {
        // The limiter's test storage lives in the kernel - keep the one kernel across the requests
        $this->browser->disableReboot();
        $address = '203.0.113.48';
        /** @var RateLimiterFactoryInterface $byAddress */
        $byAddress = self::getContainer()->get('limiter.participant_claim_page');
        /** @var RateLimiterFactoryInterface $byPlayer */
        $byPlayer = self::getContainer()->get('limiter.participant_claim_page_player');
        $byAddress->create($address)->reset();
        $byAddress->create($address)->consume(100000);
        $byPlayer->create(PlayerFixture::PLAYER_PRIVATE)->reset();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);

        try {
            // The address is used up - the signed-in player is not
            $this->browser->request('GET', self::PAGE, server: ['REMOTE_ADDR' => $address]);
            self::assertResponseIsSuccessful();

            $byPlayer->create(PlayerFixture::PLAYER_PRIVATE)->consume(100000);
            $page = $this->browser->request('GET', self::PAGE, server: ['REMOTE_ADDR' => '203.0.113.49']);
            self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
            self::assertSame('too_many', $page->filter('[data-claim-state]')->attr('data-claim-state'));

            // The button counts the same way
            $this->browser->request('POST', self::PAGE, ['_token' => 'csrf-token'], server: ['REMOTE_ADDR' => '203.0.113.49', 'HTTP_ORIGIN' => 'http://localhost']);
            self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
            self::assertNull($this->linkedPlayer());
        } finally {
            $byAddress->create($address)->reset();
            $byPlayer->create(PlayerFixture::PLAYER_PRIVATE)->reset();
        }
    }

    /**
     * "It's me - tell the organiser" from somebody holding their own paid registration on a managed event: approved,
     * the dispute lets go of it - the form asks for their yes, and without it nothing is sent (never a loop)
     */
    public function testTheDisputeOfAPaidRegistrantAsksForTheirYes(): void
    {
        $this->managedWithPaidOwnRow();
        $this->database->executeStatement(
            "UPDATE competition_participant SET player_id = :player, connected_at = " . TestClock::SQL_NOW . ", connected_via = 'organiser' WHERE id = :id",
            ['player' => PlayerFixture::PLAYER_PRIVATE, 'id' => Wjpc::PARTICIPANT_UNCONNECTED],
        );
        $this->database->executeStatement('UPDATE competition_participant SET player_id = NULL WHERE id = :id', ['id' => Wjpc::PARTICIPANT_PRIVATE]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $page = $this->browser->request('GET', self::PAGE);
        self::assertSame('belongs_to_other', $page->filter('[data-claim-state]')->attr('data-claim-state'));
        self::assertCount(1, $page->filter('[data-claim-release-paid] input[type="checkbox"][name="release_paid"]'));

        $this->confirm('csrf-token', ['action' => 'dispute']);
        self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
        self::assertSame(0, $this->requestCount());

        $this->confirm('csrf-token', ['action' => 'dispute', 'release_paid' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertSame(
            ['kind' => 'dispute', 'releases_own_registration' => true],
            $this->database->fetchAssociative('SELECT kind, releases_own_registration FROM participant_link_request WHERE participant_id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]),
        );
    }

    /**
     * The switch page tells a paid registrant it lets go of their registration - "Switch" is their yes; a registration
     * that became paid after the page was shown sends them back to the page, which says it now
     */
    public function testTheSwitchOfAPaidRegistrantSaysItAndChecksAgain(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);

        $page = $this->browser->request('GET', self::PAGE);
        self::assertCount(0, $page->filter('[data-claim-paid-note]'));
        self::assertCount(0, $page->filter('[data-claim-release-paid]'));

        // Paid meanwhile: the switch from the page shown before is refused
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => Wjpc::PARTICIPANT_SELF_JOINED]);
        $this->confirm('csrf-token', ['switch' => '1']);
        self::assertResponseRedirects(self::PAGE, Response::HTTP_SEE_OTHER);
        self::assertNull($this->linkedPlayer());
        self::assertNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_SELF_JOINED]));

        $page = $this->browser->followRedirect();
        self::assertCount(1, $page->filter('[data-claim-paid-note]'));
        self::assertCount(1, $page->filter('form [data-claim-release-paid]'));

        $this->confirm('csrf-token', ['switch' => '1', 'release_paid' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->linkedPlayer());
    }

    /**
     * Signed in to an account without a player profile (a failed registration - RetrieveLoggedUserProfile makes the
     * profile otherwise, so no fixture account lacks one): its own state, nothing to link and no sign-in buttons that
     * would lead back here
     */
    public function testAnAccountWithoutAProfileGetsItsOwnState(): void
    {
        $page = new ParticipantClaimPage(
            participantId: Wjpc::PARTICIPANT_UNCONNECTED,
            participantName: 'Jane Unconnected',
            participantCountry: 'us',
            removed: false,
            linkedPlayerId: null,
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            competitionName: 'WJPC 2024',
            dateFrom: null,
            dateTo: null,
            location: null,
            publiclyVisible: true,
            viewerOtherName: null,
            viewerOtherPaid: false,
        );

        self::assertSame('no_profile', ParticipantClaimController::state($page, null, signedIn: true));
        self::assertSame('guest', ParticipantClaimController::state($page, null, signedIn: false));
        self::assertSame('unlinked', ParticipantClaimController::state($page, PlayerFixture::PLAYER_ADMIN, signedIn: true));
    }

    private function managedWithPaidOwnRow(): void
    {
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => Wjpc::PARTICIPANT_SELF_JOINED]);
    }

    private function requestCount(): int
    {
        $count = $this->database->fetchOne('SELECT count(*) FROM participant_link_request WHERE participant_id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param array<string, string> $fields
     */
    private function confirm(null|string $csrfToken, array $fields = []): void
    {
        $this->browser->request('POST', self::PAGE, ['_token' => (string) $csrfToken, ...$fields], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function linkedPlayer(): null|string
    {
        $playerId = $this->database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_UNCONNECTED]);

        return is_string($playerId) ? $playerId : null;
    }

    private function assertPrivate(): void
    {
        $headers = $this->browser->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));
    }
}
