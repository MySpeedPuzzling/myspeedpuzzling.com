<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Referees;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCompetitionReferees;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The referees page of a competition (docs/features/competitions-management/live-results.md "Referees"): organisers
 * only, add by player search, remove with CSRF, the link for referees with its QR, each referee's activity (judge-speed
 * J1: results whose last change was theirs, the last change in its round's zone).
 */
final class CompetitionRefereesPageTest extends WebTestCase
{
    use QueryCountAssertions;

    // The page's statements before the activity column (counted on main @ 757fcf227) - the column costs none
    private const int PAGE_STATEMENTS = 13;

    private const string PAGE = '/en/manage-event-referees/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        $this->browser->request('GET', self::PAGE);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testOnlyTheOrganisersMayOpenIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);

        $this->browser->request('GET', '/en/manage-event-referees/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/qr-code.svg');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOrganiserSeesTheLinkForRefereesWithItsQr(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame(
            'http://localhost/en/live-results/event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            $crawler->filter('[data-controller="clipboard"] input')->attr('value'),
        );
        self::assertSelectorExists('img[src="/en/manage-event-referees/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/qr-code.svg"]');
        self::assertSelectorTextContains('main', 'No referees yet.');

        $this->browser->request('GET', '/en/manage-event-referees/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/qr-code.svg');
        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $this->browser->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('<svg', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('max-age=86400', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheEditPageLinksTheRefereesPage(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', '/en/edit-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertSelectorExists('a[data-referees-link][href^="' . self::PAGE . '"]');
    }

    public function testTheOrganiserAddsAReferee(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', self::PAGE);

        $this->browser->submitForm('Add as referees', [
            'add_competition_referees_form[players]' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::PAGE);
        self::assertSame(
            ['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'added_by_id' => self::ORGANISER],
            $this->database()->fetchAssociative(
                'SELECT player_id, added_by_id FROM competition_referee WHERE competition_id = :id',
                ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
            ),
        );

        $this->browser->followRedirect();
        self::assertSelectorTextContains('main', 'Added 1 referee.');
        self::assertSelectorExists('tr[data-referee="' . PlayerFixture::PLAYER_WITH_FAVORITES . '"]');
        self::assertSelectorTextContains('main', 'Referees (1)');
    }

    public function testAddingAnOrganiserOrSomebodyTwiceChangesNothingAndSaysSo(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', self::PAGE);

        $this->browser->submitForm('Add as referees', [
            'add_competition_referees_form[players]' => PlayerFixture::PLAYER_WITH_FAVORITES . ',' . self::ORGANISER,
        ]);

        self::assertResponseRedirects(self::PAGE);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('main', 'Already a referee - nothing changed.');
        self::assertSelectorTextContains('main', 'Organises this event and can enter results anyway - not added as a referee.');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM competition_referee'));
    }

    public function testAddingNobodyIsRefusedWithTheFormAgain(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', self::PAGE);

        $this->browser->submitForm('Add as referees', [
            'add_competition_referees_form[players]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose at least one player.');
    }

    public function testTheOrganiserRemovesAReferee(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $crawler = $this->browser->request('GET', self::PAGE);

        $form = $crawler->filter('tr[data-referee="' . PlayerFixture::PLAYER_WITH_FAVORITES . '"] form');
        self::assertStringContainsString('Remove', (string) $form->attr('onsubmit'));
        $this->browser->submit($form->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::PAGE);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM competition_referee'));
    }

    public function testARemovalWithoutAValidTokenRemovesNothing(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('POST', self::PAGE . '/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/remove', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This page was open too long');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM competition_referee'));
    }

    public function testNobodyButAnOrganiserMayRemove(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        // The referee themselves included
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('POST', self::PAGE . '/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/remove', ['_token' => 'any']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM competition_referee'));
    }

    public function testASeriesMaintainerManagesTheRefereesOfItsEditions(): void
    {
        $this->database()->insert('competition_series_maintainer', [
            'competition_series_id' => CompetitionSeriesFixture::SERIES_OFFLINE,
            'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES,
        ]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('GET', '/en/manage-event-referees/' . CompetitionSeriesFixture::EDITION_OFFLINE_1);
        self::assertResponseIsSuccessful();

        $this->browser->submitForm('Add as referees', [
            'add_competition_referees_form[players]' => PlayerFixture::PLAYER_REGULAR,
        ]);
        self::assertResponseRedirects('/en/manage-event-referees/' . CompetitionSeriesFixture::EDITION_OFFLINE_1);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM competition_referee WHERE competition_id = :id AND player_id = :player',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1, 'player' => PlayerFixture::PLAYER_REGULAR],
        ));
    }

    public function testARefereeWithoutResultsShowsNoResultsYet(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('tr[data-referee="' . PlayerFixture::PLAYER_WITH_FAVORITES . '"] [data-referee-activity]', 'No results yet');
        self::assertSelectorTextSame('tr[data-referee="' . PlayerFixture::PLAYER_WITH_FAVORITES . '"] [data-referee-activity-narrow]', 'No results yet');
        self::assertSelectorTextContains('main', 'Activity counts the results whose last change was theirs.');
    }

    public function testSoloAndTeamResultsCountAndTheLastChangeIsShownInItsRoundsZone(): void
    {
        $referee = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->insertReferee($referee);
        // Frozen now 2026-10-14 12:00 UTC - 08:42 UTC is 10:42 in Prague (the rounds' zone), the same day
        $this->enteredBy($referee, 'competition_participant_round', OfficialResultsFixture::ENTRY_A_ANNA, '2026-10-14 07:00:00');
        $this->enteredBy($referee, 'competition_participant_round', OfficialResultsFixture::ENTRY_B_GINA, '2026-10-14 08:42:00');
        $this->enteredBy($referee, 'competition_team', OfficialResultsFixture::TEAM_SHARKS, '2026-10-14 08:00:00');
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->startCountingQueries($this->browser);
        $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame(self::PAGE_STATEMENTS, $this->queryCount($this->browser), 'The activity column adds no statement to the page.');
        self::assertSelectorTextSame('tr[data-referee="' . $referee . '"] [data-referee-activity]', '3 results · last 10:42 (Group B)');
    }

    public function testAClearedResultCountsNothingButMovesTheLastChange(): void
    {
        $referee = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->insertReferee($referee);
        $this->enteredBy($referee, 'competition_participant_round', OfficialResultsFixture::ENTRY_A_ANNA, '2026-10-14 07:00:00');
        // Entered by the referee, then cleared by them - the last change, counting nothing
        $this->enteredBy($referee, 'competition_participant_round', OfficialResultsFixture::ENTRY_B_IVAN, '2026-10-12 08:42:00');
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = NULL, result_did_not_start = false, result_entered_at = :at WHERE id = :id',
            ['at' => '2026-10-14 09:15:00', 'id' => OfficialResultsFixture::ENTRY_B_IVAN],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::PAGE);

        self::assertSelectorTextSame('tr[data-referee="' . $referee . '"] [data-referee-activity]', '1 result · last 11:15 (Group B)');
    }

    public function testAnOlderDayShowsItsDateAndOnlyThisEventCounts(): void
    {
        $referee = PlayerFixture::PLAYER_WITH_FAVORITES;
        $this->insertReferee($referee);
        $this->enteredBy($referee, 'competition_team', OfficialResultsFixture::TEAM_EDGES, '2026-10-12 08:42:00');

        // A result of another event entered by the same player - later, but neither counted nor "last" here
        $otherEntry = $this->database()->fetchOne(
            'SELECT cpr.id FROM competition_participant_round cpr INNER JOIN competition_round r ON r.id = cpr.round_id WHERE r.competition_id <> :id ORDER BY cpr.id LIMIT 1',
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );
        self::assertIsString($otherEntry, 'The fixtures have entries of another event.');
        $this->enteredBy($referee, 'competition_participant_round', $otherEntry, '2026-10-14 11:00:00');
        $this->database()->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = 3000 WHERE id = :id',
            ['id' => $otherEntry],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', self::PAGE);

        self::assertSelectorTextSame('tr[data-referee="' . $referee . '"] [data-referee-activity]', '1 result · last 12 Oct 10:42 (Pairs)');
    }

    public function testTheWizardsListOfRefereesStaysWithoutActivity(): void
    {
        $this->insertReferee(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->enteredBy(PlayerFixture::PLAYER_WITH_FAVORITES, 'competition_participant_round', OfficialResultsFixture::ENTRY_A_ANNA, '2026-10-14 07:00:00');

        $query = self::getContainer()->get(GetCompetitionReferees::class);
        $plain = $query->ofCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        $withActivity = $query->withActivity(OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertCount(1, $plain);
        self::assertSame(0, $plain[0]->resultsEntered);
        self::assertNull($plain[0]->lastEnteredAt);
        self::assertCount(1, $withActivity);
        self::assertSame(1, $withActivity[0]->resultsEntered);
        self::assertSame('Group A', $withActivity[0]->lastRoundName);
        self::assertSame('Europe/Prague', $withActivity[0]->lastRoundTimezone);
        self::assertSame([], $query->withActivity('not-a-uuid'));
    }

    private function enteredBy(string $playerId, string $table, string $id, string $at): void
    {
        $this->database()->executeStatement(
            sprintf('UPDATE %s SET result_entered_by_id = :player, result_entered_at = :at WHERE id = :id', $table),
            ['player' => $playerId, 'at' => $at, 'id' => $id],
        );
    }

    private function insertReferee(string $playerId): void
    {
        $this->database()->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => $playerId,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countRows(string $sql, array $parameters = []): int
    {
        $count = $this->database()->fetchOne($sql, $parameters);
        assert(is_int($count));

        return $count;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
