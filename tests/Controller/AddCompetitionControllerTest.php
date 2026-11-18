<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The classic create forms were retired on 2026-11-18 (docs/features/competitions-management/creation-wizard.md
 * "Classic create retired"): their old addresses - "Add event" (`add_competition`, `?classic=1` too), "Add edition"
 * (`add_edition`) and "Add several dates" (`add_editions`) - lead to the event creation wizard with the context it takes
 * (the organization, a series), a GET with 302, a POST of a form left open in a tab with 303 - creating nothing.
 */
final class AddCompetitionControllerTest extends WebTestCase
{
    private const string SERIES = OrganizationFixture::SERIES_LANTERN_NIGHTS;

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        foreach (['/en/add-event', '/en/add-edition/' . self::SERIES, '/en/add-editions/' . self::SERIES] as $url) {
            $browser->request('GET', $url);
            self::assertResponseRedirects(null, null, $url);
            self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'), $url);
        }
    }

    public function testAddEventLeadsToTheWizardsEntry(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        self::assertResponseRedirects('/en/new-event', 302);

        // An old bookmark of the classic form
        $browser->request('GET', '/en/add-event?classic=1');
        self::assertResponseRedirects('/en/new-event', 302);

        // The organization goes along (the wizard pre-selects it for its team only); the classic return link does not
        $browser->request('GET', '/en/add-event?classic=1&organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND . '&return=/en/events');
        self::assertResponseRedirects('/en/new-event?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND, 302);

        // Czech (no locale prefix on the old address) stays Czech
        $browser->request('GET', '/pridat-udalost');
        self::assertResponseRedirects('/cs/new-event', 302);
    }

    public function testRecurringLeadsToTheSeriesPath(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/add-event?classic=1&recurring=1&organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseRedirects('/en/new-event/series/basics?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND, 302);
    }

    public function testAPostOfTheClassicFormCreatesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $events = $this->rowCount('SELECT COUNT(*) FROM competition');
        $series = $this->rowCount('SELECT COUNT(*) FROM competition_series');

        $browser->request('POST', '/en/add-event?classic=1', ['competition_form' => [
            'name' => 'Left Open In A Tab',
            'isOnline' => '0',
            'location' => 'Brno',
            'dateFrom' => '15.06.2027',
            'dateTo' => '15.06.2027',
        ]]);
        self::assertResponseRedirects('/en/new-event', 303);

        $browser->request('POST', '/en/add-event?recurring=1', ['competition_form' => ['name' => 'Left Open Series', 'isRecurring' => '1']]);
        self::assertResponseRedirects('/en/new-event/series/basics', 303);

        self::assertSame($events, $this->rowCount('SELECT COUNT(*) FROM competition'));
        self::assertSame($series, $this->rowCount('SELECT COUNT(*) FROM competition_series'));
    }

    public function testAddEditionAndAddSeveralDatesLeadToTheNextDates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $nextDates = '/en/new-event/series/' . self::SERIES . '/next-dates';

        $browser->request('GET', '/en/add-edition/' . self::SERIES . '?return=/en/events');
        self::assertResponseRedirects($nextDates, 302);

        // A bookmarked rule keeps its preview - without the classic page's own return link
        $browser->request('GET', '/en/add-editions/' . self::SERIES . '?how=pick&dates=05.03.2028&namePattern=X&return=/en/events&return_title=Events');
        self::assertResponseRedirects($nextDates . '?how=pick&dates=05.03.2028&namePattern=X', 302);

        // Forms left open in a tab create nothing - 303 to the same place
        $editions = $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = \'' . self::SERIES . '\'');
        $browser->request('POST', '/en/add-edition/' . self::SERIES, ['edition_form' => ['name' => 'Left Open', 'dateFrom' => '05.03.2028']]);
        self::assertResponseRedirects($nextDates, 303);
        $browser->request('POST', '/en/add-editions/' . self::SERIES . '?how=pick&dates=05.03.2028&namePattern=X', ['_token' => 'any', 'selected' => ['2028-03-05']]);
        self::assertResponseRedirects($nextDates . '?how=pick&dates=05.03.2028&namePattern=X', 303);
        self::assertSame($editions, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = \'' . self::SERIES . '\''));

        // The next dates page itself checks who may add dates
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/add-edition/' . self::SERIES);
        self::assertResponseRedirects($nextDates, 302);
        $browser->followRedirect();
        self::assertResponseStatusCodeSame(403);
    }

    private function rowCount(string $query): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $count = $connection->fetchOne($query);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }
}
