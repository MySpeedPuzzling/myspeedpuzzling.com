<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantLinking;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Is this you?" on the event page (participant-linking.md §6.1), `?participant=` of a scanned name tag (§6.4, R12), the
 * join page's pre-selection and same-name hint, and the participants list's "This is me" (§6.3).
 *
 * WJPC 2024: 'Jane Unconnected' (us) is the one unlinked name; PLAYER_ADMIN is 'Admin User' from cz and not going.
 */
final class IsThisYouCardTest extends WebTestCase
{
    private const string WJPC_PAGE = '/en/events/wjpc-2024';
    private const string UNCONNECTED = CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED;

    public function testTheOneNameOnTheListThatIsTheViewersGetsTheCard(): void
    {
        $browser = self::createClient();
        $this->rename(self::UNCONNECTED, 'Admin  user', 'cz');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::WJPC_PAGE);

        $this->assertResponseIsSuccessful();
        $card = $page->filter('[data-participant-claim-card="' . self::UNCONNECTED . '"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Is this you?', $card->text());
        // The organiser's spelling (the crawler's text() folds the double space)
        self::assertStringContainsString('Admin user', $card->filter('[data-participant-claim-name]')->text());
        self::assertCount(1, $card->filter('[data-participant-claim-no]'));
        self::assertSame('/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024 . '?participant=' . self::UNCONNECTED, $card->filter('[data-participant-claim-find]')->attr('href'));
        // The header's "I'm going" leads down to the card, not into a GET that would link
        self::assertSame('#taking-part', $page->filter('[data-participant-claim-action]')->attr('href'));
        // Nothing written by looking
        self::assertNull($this->linkOf(self::UNCONNECTED));

        // Yes (open policy): linked at once
        $browser->submit($card->filter('form')->first()->form());
        $this->assertResponseRedirects();
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkOf(self::UNCONNECTED));
    }

    public function testNoGoesAsThemselves(): void
    {
        $browser = self::createClient();
        $this->rename(self::UNCONNECTED, 'Admin User', 'cz');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::WJPC_PAGE);
        $browser->submit($page->filter('[data-participant-claim-card] form')->eq(1)->form());

        $this->assertResponseRedirects();
        self::assertNull($this->linkOf(self::UNCONNECTED));
        self::assertSame(1, $this->rowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testTwoPeopleOfTheNameGetNoCardButTheHint(): void
    {
        $browser = self::createClient();
        $this->rename(self::UNCONNECTED, 'Admin User', 'cz');
        $this->insertParticipant(CompetitionFixture::COMPETITION_WJPC_2024, 'admin user', null);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::WJPC_PAGE);
        self::assertCount(0, $page->filter('[data-participant-claim-card]'));

        $join = $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('2 people on the list are called Admin User - pick yours.', $join->filter('[data-same-name-hint]')->text());
        self::assertCount(0, $join->filter('select[name="participant_id"] option[selected]'));
        self::assertNull($this->linkOf(self::UNCONNECTED));
    }

    public function testNoCardForAnotherCountryARefusedPairOrThreeRefusals(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->rename(self::UNCONNECTED, 'Admin User', 'de');
        self::assertCount(0, $this->card($browser, self::WJPC_PAGE), 'another country');

        $this->rename(self::UNCONNECTED, 'Admin User', null);
        self::assertCount(1, $this->card($browser, self::WJPC_PAGE), 'no country on the list fits anybody');

        $this->refusal(self::UNCONNECTED, PlayerFixture::PLAYER_ADMIN);
        self::assertCount(0, $this->card($browser, self::WJPC_PAGE), 'a refused pair');
    }

    public function testNoCardForAPlayerWithThreeRefusals(): void
    {
        $browser = self::createClient();
        $this->rename(self::UNCONNECTED, 'Admin User', 'cz');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::assertCount(1, $this->card($browser, self::WJPC_PAGE));

        foreach ([CompetitionParticipantFixture::PARTICIPANT_CONNECTED, CompetitionParticipantFixture::PARTICIPANT_PRIVATE, CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED] as $refused) {
            $this->refusal($refused, PlayerFixture::PLAYER_ADMIN);
        }

        self::assertCount(0, $this->card($browser, self::WJPC_PAGE));
    }

    public function testNoCardForAPlayerWhoIsGoingOrAGuest(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR (John Doe, cz) is going as 'John Regular'
        $this->rename(self::UNCONNECTED, 'John Doe', 'cz');

        self::assertCount(0, $this->card($browser, self::WJPC_PAGE), 'a guest');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $this->card($browser, self::WJPC_PAGE), 'going already');
    }

    public function testUnderApprovalYesWaitsForTheOrganiser(): void
    {
        $browser = self::createClient();
        $this->database()->executeStatement("UPDATE competition SET participant_claims = 'approval' WHERE id = :id", ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        $this->rename(self::UNCONNECTED, 'Admin User', 'cz');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $card = $this->card($browser, self::WJPC_PAGE);
        $browser->submit($card->filter('form')->first()->form());

        $this->assertResponseRedirects();
        $page = $browser->followRedirect();
        self::assertNull($this->linkOf(self::UNCONNECTED));
        self::assertCount(1, $page->filter('[data-participant-link-pending]'));
        self::assertCount(0, $page->filter('[data-participant-claim-card]'), 'the waiting state, not the card');
    }

    public function testAScannedTagAsksForItsPersonWhateverTheName(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // 'Jane Unconnected' (us) is nobody's name match - the scan is the signal
        self::assertCount(1, $this->card($browser, self::WJPC_PAGE . '?participant=' . self::UNCONNECTED));

        // Linked, removed, of another event, not an id: no card
        foreach ([CompetitionParticipantFixture::PARTICIPANT_CONNECTED, CompetitionParticipantFixture::PARTICIPANT_DELETED, OfficialResultsFixture::PARTICIPANT_IVAN, 'nonsense'] as $participant) {
            self::assertCount(0, $this->card($browser, self::WJPC_PAGE . '?participant=' . $participant), $participant);
        }
    }

    public function testAManagedEventPostsThePickerFormWithItsToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $page = '/en/events/riverside-puzzle-open';

        // A waitlisted name is not going - no card for it
        self::assertCount(0, $this->card($browser, $page . '?participant=' . EventsPageFixture::PARTICIPANT_RIVERSIDE_WAITLISTED));

        $card = $this->card($browser, $page . '?participant=' . EventsPageFixture::PARTICIPANT_RIVERSIDE_B);
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('input[name="_token"]'));
        // Above the registration card
        self::assertCount(1, $browser->getCrawler()->filter('[data-participant-claim-card] + [data-registration-card]'));

        $browser->submit($card->filter('form')->first()->form());
        $this->assertResponseRedirects();
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkOf(EventsPageFixture::PARTICIPANT_RIVERSIDE_B));
    }

    public function testTheJoinPagePreselectsOnlyAPickableParticipant(): void
    {
        $browser = self::createClient();
        // A second unlinked name, so the list stays to pick from whatever happens to Jane
        $this->insertParticipant(CompetitionFixture::COMPETITION_WJPC_2024, 'Extra Person', 'gb');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $join = '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024 . '?participant=';

        $crawler = $browser->request('GET', $join . self::UNCONNECTED);
        $this->assertResponseIsSuccessful();
        self::assertSame(self::UNCONNECTED, $crawler->filter('select[name="participant_id"] option[selected]')->attr('value'));
        self::assertNull($this->linkOf(self::UNCONNECTED), 'a GET never links');

        // A name match on an open event would link on a GET - not while a participant is asked for
        $this->rename(self::UNCONNECTED, 'Admin User', 'cz');
        $crawler = $browser->request('GET', $join . self::UNCONNECTED);
        $this->assertResponseIsSuccessful();
        self::assertSame(self::UNCONNECTED, $crawler->filter('select[name="participant_id"] option[selected]')->attr('value'));
        self::assertNull($this->linkOf(self::UNCONNECTED));

        foreach ([CompetitionParticipantFixture::PARTICIPANT_CONNECTED, CompetitionParticipantFixture::PARTICIPANT_DELETED, OfficialResultsFixture::PARTICIPANT_IVAN] as $ignored) {
            $this->rename(self::UNCONNECTED, 'Jane Unconnected', 'us');
            $crawler = $browser->request('GET', $join . $ignored);
            $this->assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('select[name="participant_id"] option[selected]'), $ignored);
        }

        $this->database()->executeStatement("UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id", ['id' => self::UNCONNECTED]);
        $crawler = $browser->request('GET', $join . self::UNCONNECTED);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="participant_id"] option[selected]'), 'waitlisted');
    }

    public function testTheParticipantsListOffersThisIsMeToAViewerWhoIsNotGoing(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::WJPC_PAGE);
        self::assertCount(0, $browser->getCrawler()->filter('[data-participant-this-is-me]'), 'a guest');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::WJPC_PAGE);
        self::assertCount(0, $browser->getCrawler()->filter('[data-participant-this-is-me]'), 'going already');

        $browser->restart();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::WJPC_PAGE);
        $link = $page->filter('[data-participant-this-is-me="' . self::UNCONNECTED . '"]');
        self::assertCount(1, $link);
        self::assertSame('/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024 . '?participant=' . self::UNCONNECTED, $link->attr('href'));
    }

    private function card(KernelBrowser $browser, string $url): Crawler
    {
        $page = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $page->filter('[data-participant-claim-card]');
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function rename(string $participantId, string $name, null|string $country): void
    {
        $this->database()->executeStatement(
            'UPDATE competition_participant SET name = :name, country = :country WHERE id = :id',
            ['name' => $name, 'country' => $country, 'id' => $participantId],
        );
    }

    private function insertParticipant(string $competitionId, string $name, null|string $country): void
    {
        $this->database()->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, :name, :country, :competition, 'imported')",
            ['id' => Uuid::uuid7()->toString(), 'name' => $name, 'country' => $country, 'competition' => $competitionId],
        );
    }

    private function refusal(string $participantId, string $playerId): void
    {
        $this->database()->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (:id, :competition, :participant, 'x', :player, 'y', 'not_this_person', NULL, NULL, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'competition' => CompetitionFixture::COMPETITION_WJPC_2024, 'participant' => $participantId, 'player' => $playerId],
        );
    }

    private function linkOf(string $participantId): null|string
    {
        $playerId = $this->database()->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($playerId) ? $playerId : null;
    }

    private function rowsOf(string $playerId, string $competitionId): int
    {
        $count = $this->database()->fetchOne(
            'SELECT count(*) FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => $competitionId, 'pid' => $playerId],
        );
        assert(is_int($count));

        return $count;
    }
}
