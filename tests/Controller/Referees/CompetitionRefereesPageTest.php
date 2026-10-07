<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Referees;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The referees page of a competition (docs/features/competitions-management/live-results.md "Referees"): organisers
 * only, add by player search, remove with CSRF, the link for referees with its QR.
 */
final class CompetitionRefereesPageTest extends WebTestCase
{
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
