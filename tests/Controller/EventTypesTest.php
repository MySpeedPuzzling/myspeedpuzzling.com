<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * "What kind of event" (docs/features/events-page/event-types.md): optional, the forms and the internal API save it, the
 * byline of the series, edition and event pages and the events page's rows show it - never for a plain competition or
 * when none is set; an edition without its own shows its series'.
 */
final class EventTypesTest extends WebTestCase
{
    use InternalApiRequests;

    private const string LANTERN_1_URL = '/en/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS_SLUG . '/lantern-night-one';

    public function testNoneSetShowsNothing(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN_SLUG);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-event-types]'));

        $crawler = $browser->request('GET', '/en/events');
        self::assertCount(0, $crawler->filter('.ev-tag-event_type'));
    }

    public function testTheSeriesTypesShowOnItsEditionsUntilAnEditionHasItsOwn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/edit-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS);
        $browser->submit(self::withTypes($crawler->selectButton('Save Changes')->form(), ['competition', 'puzzle_swap']));
        self::assertResponseRedirects();
        self::assertSame(['competition', 'puzzle_swap'], $this->storedTypes('competition_series', OrganizationFixture::SERIES_LANTERN_NIGHTS));

        $crawler = $browser->request('GET', self::LANTERN_1_URL);
        self::assertSame('Competition · Puzzle swap', $crawler->filter('.ev-detail-byline [data-event-types]')->text());

        $crawler = $browser->request('GET', '/en/events');
        $tags = $crawler->filter('.ev-tag-event_type')->each(static fn (Crawler $tag): string => trim($tag->text()));
        self::assertContains('Puzzle swap', $tags);

        // The edition's form shows its series' types ticked - saved unchanged, it keeps following the series
        $crawler = $browser->request('GET', '/en/edit-event/' . OrganizationFixture::EDITION_LANTERN_1);
        self::assertSame(['competition', 'puzzle_swap'], $crawler->filter('input[name="competition_form[eventTypes][]"]:checked')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        ));
        $browser->submitForm('Save Changes');
        self::assertResponseRedirects();
        self::assertSame([], $this->storedTypes('competition', OrganizationFixture::EDITION_LANTERN_1));

        // Its own: a workshop that night
        $crawler = $browser->request('GET', '/en/edit-event/' . OrganizationFixture::EDITION_LANTERN_1);
        $browser->submit(self::withTypes($crawler->selectButton('Save Changes')->form(), ['workshop']));
        self::assertResponseRedirects();
        self::assertSame(['workshop'], $this->storedTypes('competition', OrganizationFixture::EDITION_LANTERN_1));

        $crawler = $browser->request('GET', self::LANTERN_1_URL);
        self::assertSame('Workshop', $crawler->filter('.ev-detail-byline [data-event-types]')->text());
    }

    public function testAPlainCompetitionIsKeptButShowsNoTag(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/add-event');
        $browser->submit(self::withTypes($crawler->selectButton('Submit for Approval')->form([
            'competition_form[name]' => 'Single Puzzle Night',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Brno',
            'competition_form[dateFrom]' => '15.06.2027',
            'competition_form[dateTo]' => '15.06.2027',
        ]), ['competition']));
        self::assertResponseRedirects();

        $id = $this->connection()->fetchOne('SELECT id FROM competition WHERE name = ?', ['Single Puzzle Night']);
        self::assertIsString($id);
        self::assertSame(['competition'], $this->storedTypes('competition', $id));

        $crawler = $browser->request('GET', '/en/edit-event/' . $id);
        self::assertSame('competition', $crawler->filter('input[name="competition_form[eventTypes][]"]:checked')->attr('value'));
    }

    public function testTheInternalApiTakesThemOnlyWhenSent(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, [
            'eventTypes' => ['puzzle_swap', 'casual_puzzling'],
        ]);
        self::assertResponseIsSuccessful();
        // In EventType's order
        self::assertSame(['casual_puzzling', 'puzzle_swap'], $answer['eventTypes']);

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, [
            'description' => 'A spring afternoon.',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['casual_puzzling', 'puzzle_swap'], $answer['eventTypes']);

        $answer = self::callInternalApi($browser, 'PATCH', '/internal-api/competitions/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, [
            'eventTypes' => ['party'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        self::assertArrayHasKey('eventTypes', $answer['errors']);

        // An edition created without them takes its series'
        $answer = self::callInternalApi($browser, 'POST', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/editions', [
            'name' => 'Lantern Night Workshop',
            'dateFrom' => '2027-01-07',
            'dateTo' => '2027-01-07',
            'eventTypes' => ['workshop'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['workshop'], $answer['eventTypes']);

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/editions', [
            'name' => 'Lantern Night Plain',
            'dateFrom' => '2027-02-04',
            'dateTo' => '2027-02-04',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $answer['eventTypes']);
    }

    /**
     * Ticks exactly these "What kind of event" boxes
     *
     * @param list<string> $types
     */
    private static function withTypes(Form $form, array $types): Form
    {
        $boxes = $form['competition_form[eventTypes]'];
        self::assertIsArray($boxes);

        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            // A ticked box holds its own value
            $box->tick();

            if (in_array($box->getValue(), $types, true) === false) {
                $box->untick();
            }
        }

        return $form;
    }

    /**
     * @return list<string>
     */
    private function storedTypes(string $table, string $id): array
    {
        $json = $this->connection()->fetchOne("SELECT event_types FROM {$table} WHERE id = ?", [$id]);
        self::assertIsString($json);
        $types = json_decode($json, true);
        self::assertIsArray($types);

        return array_values(array_map(static fn (mixed $type): string => is_string($type) ? $type : '', $types));
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
