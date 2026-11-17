<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The event creation wizard's series path (W3, docs/features/competitions-management/toolkit/
 * W-creation-wizard-research.md §3.3, §3.7, §3.9): Basics of a series (a draft series), Dates + each date's round
 * (AddEventWizardEditions), Review (the series and its dates published), "Add the next dates" and the per-date quick
 * "Add puzzle" on the series page.
 */
final class EventWizardSeriesTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'EVENT_CREATION_WIZARD';
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;

    /** Every month on the third Saturday from 1 January 2027, 12 dates - a solo round of 60 minutes at 19:00 */
    private const array MONTHLY = [
        'how' => 'repeat',
        'rule' => 'nth_weekday',
        'nth' => '3',
        'weekday' => '6',
        'starting' => '01.01.2027',
        'count' => '12',
        'namePattern' => 'Night Cup {date}',
        'withRounds' => '1',
        'category' => 'solo',
        'minutesLimit' => '60',
        'startTime' => '19:00',
    ];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $this->browser = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testWithTheFlagOffAnOrganiserHasNoSeriesPathNorItsLinks(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);
        $this->addMonthlyDates($seriesId);

        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        foreach (['/en/new-event/series/basics', '/en/new-event/series/' . $seriesId . '/dates', '/en/new-event/series/' . $seriesId . '/next-dates', '/en/new-event/series/' . $seriesId . '/review', '/en/new-event/series/' . $seriesId] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        // The classic page stays as it was: "Add several dates", no wizard links
        $crawler = $this->browser->request('GET', '/en/manage-series/' . $seriesId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-add-editions-link]'));
        self::assertCount(0, $crawler->filter('[data-next-dates-link]'));
        self::assertCount(0, $crawler->filter('[data-quick-add-puzzle]'));
    }

    public function testAnAdminHasTheSeriesPathWithTheFlagOff(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/new-event/series/basics');
        self::assertResponseIsSuccessful();
    }

    public function testTheEntryLeadsASeriesToItsBasics(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', '/en/new-event?kind=series');
        self::assertResponseRedirects('/en/new-event/series/basics');
    }

    public function testSeriesPathEndToEnd(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/dates', 303);
        $series = $this->database()->fetchAssociative('SELECT * FROM competition_series WHERE id = :id', ['id' => $seriesId]);
        self::assertIsArray($series);
        self::assertTrue($series['is_draft']);
        self::assertSame('Night Cup', $series['name']);
        self::assertSame('cz', $series['location_country_code']);
        // A draft e-mails no admin
        self::assertQueuedEmailCount(0);

        // Back to Basics: the series is edited, nothing new
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/basics');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->filter('form[name="event_wizard_series_basics_form"]')->form(), [
            'event_wizard_series_basics_form[name]' => 'Night Cup Brno',
        ]);
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/dates', 303);
        self::assertSame('Night Cup Brno', $this->database()->fetchOne('SELECT name FROM competition_series WHERE id = :id', ['id' => $seriesId]));

        // Dates: the preview lists 12 third Saturdays with their names
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query(self::MONTHLY));
        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('#event-wizard-dates-preview input[name="selected[]"]'));
        self::assertSame('2027-01-16', $crawler->filter('#event-wizard-dates-preview label[data-date]')->first()->attr('data-date'));
        self::assertStringContainsString('Night Cup 16 January 2027', $crawler->filter('#event-wizard-dates-preview')->text());

        $form = $crawler->filter('#event-wizard-dates-preview form')->form();
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/review', 303);

        $rounds = $this->rounds($seriesId);
        self::assertCount(12, $rounds);

        foreach ($rounds as $round) {
            self::assertSame('Solo', $round['name']);
            self::assertSame('solo', $round['category']);
            self::assertSame(60, $round['minutes_limit']);
            self::assertSame('Europe/Prague', $round['timezone']);
            self::assertTrue($round['edition_draft'], 'A draft series gets draft dates');
            // 19:00 in Prague on the edition's own day - in winter (UTC+1) and in summer (UTC+2) alike
            $local = new DateTimeImmutable(self::string($round['starts_at']), new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('Europe/Prague'));
            self::assertSame('19:00', $local->format('H:i'));
            self::assertSame(substr(self::string($round['date_from']), 0, 10), $local->format('Y-m-d'));
        }

        // The same form sent again creates nothing twice
        $this->browser->submit($form);
        self::assertCount(12, $this->rounds($seriesId));
        self::assertSame(12, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id', $seriesId));

        // Review lists the dates with their round
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/review');
        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('[data-event-wizard-review-date]'));
        self::assertStringContainsString('60 min', $crawler->filter('[data-event-wizard-review-dates]')->text());

        // Continue setting up: every step but Review is done
        $this->browser->request('GET', '/en/new-event/series/' . $seriesId);
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/review');

        $this->browser->submit($crawler->filter('[data-event-wizard-publish]')->form());
        self::assertResponseRedirects('/en/manage-series/' . $seriesId, 303);
        self::assertFalse($this->database()->fetchOne('SELECT is_draft FROM competition_series WHERE id = :id', ['id' => $seriesId]));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id AND is_draft', $seriesId));

        // Published: the wizard's steps lead to the series page
        $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/review');
        self::assertResponseRedirects('/en/manage-series/' . $seriesId);
        $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates');
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/next-dates');
    }

    public function testTheSameBasicsPageSentTwiceCreatesOneSeries(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $before = $this->rowCount('SELECT COUNT(*) FROM competition_series');

        $crawler = $this->browser->request('GET', '/en/new-event/series/basics');
        $form = $crawler->filter('form[name="event_wizard_series_basics_form"]')->form([
            'event_wizard_series_basics_form[name]' => 'Twice Cup',
            'event_wizard_series_basics_form[isOnline]' => '0',
        ]);
        $this->browser->submit($form);
        $first = $this->browser->getResponse()->headers->get('Location');
        $this->browser->submit($form);

        self::assertSame($first, $this->browser->getResponse()->headers->get('Location'));
        self::assertSame($before + 1, $this->rowCount('SELECT COUNT(*) FROM competition_series'));
    }

    public function testBasicsRefusesAMissingNameWith422(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $before = $this->rowCount('SELECT COUNT(*) FROM competition_series');

        self::assertNull($this->startSeries(['name' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->rowCount('SELECT COUNT(*) FROM competition_series'));
    }

    public function testDatesRefuseWith422(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);
        $url = '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query(self::MONTHLY);

        // Nothing checked
        $crawler = $this->browser->request('GET', $url);
        $form = $crawler->filter('#event-wizard-dates-preview form')->form();
        $checkboxes = $form->get('selected');
        self::assertIsArray($checkboxes);
        foreach ($checkboxes as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->untick();
        }
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(422);

        // No time limit and no real start time: refused on the rule's page already (422, like any refused form)
        $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query([...self::MONTHLY, 'minutesLimit' => '', 'startTime' => '25:00']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(0, '#event-wizard-dates-preview');

        // A wall time skipped by the clock change in Prague (28 March 2027, 02:00 → 03:00): refused, nothing created
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query([...self::MONTHLY, 'how' => 'pick', 'dates' => '27.03.2027; 28.03.2027', 'startTime' => '02:30']));
        $this->browser->submit($crawler->filter('#event-wizard-dates-preview form')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('28.3.2027', (string) $this->browser->getResponse()->getContent());
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id', $seriesId));

        // A bad token
        $this->browser->request('POST', $url, ['_token' => 'nope', 'selected' => ['2027-01-16']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNoRoundsJustDates(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);

        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query([...self::MONTHLY, 'withRounds' => '0', 'startTime' => '', 'count' => '3']));
        $this->browser->submit($crawler->filter('#event-wizard-dates-preview form')->form());
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/review', 303);
        self::assertSame(3, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id', $seriesId));
        self::assertCount(0, $this->rounds($seriesId));
    }

    public function testAnotherPlayerCannotOpenTheSeriesSteps(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        foreach (['/basics', '/dates', '/review'] as $step) {
            $this->browser->request('GET', '/en/new-event/series/' . $seriesId . $step);
            self::assertResponseStatusCodeSame(403, $step);
        }
        $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/next-dates');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheNextDatesFollowTheLastOnesAndQuickAddPuzzleReturnsToTheSeries(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);
        $this->addMonthlyDates($seriesId);
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/review');
        $this->browser->submit($crawler->filter('[data-event-wizard-publish]')->form());

        // The series page: "Add the next dates" and a quick "Add puzzle" per date (one round, no puzzle)
        $crawler = $this->browser->request('GET', '/en/manage-series/' . $seriesId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-next-dates-link]'));
        self::assertCount(1, $crawler->filter('[data-add-editions-link]'));
        self::assertCount(12, $crawler->filter('[data-quick-add-puzzle]'));

        // Prefilled from the last dates: the third Saturday, after the last one, its round
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/next-dates');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-event-wizard-dates-form]')->form();
        self::assertSame('nth_weekday', self::value($form, 'rule'));
        self::assertSame('3', self::value($form, 'nth'));
        self::assertSame('6', self::value($form, 'weekday'));
        self::assertSame('19.12.2027', self::value($form, 'starting'));
        self::assertSame('19:00', self::value($form, 'startTime'));
        self::assertSame('60', self::value($form, 'minutesLimit'));
        self::assertSame('solo', self::value($form, 'category'));
        self::assertSame('1', self::value($form, 'withRounds'));

        $form['count'] = '2';
        $crawler = $this->browser->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame('2028-01-15', $crawler->filter('#event-wizard-dates-preview label[data-date]')->first()->attr('data-date'));
        $this->browser->submit($crawler->filter('#event-wizard-dates-preview form')->form());
        self::assertResponseRedirects('/en/manage-series/' . $seriesId, 303);
        self::assertSame(14, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id', $seriesId));
        // A published series gets published dates
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition WHERE series_id = :id AND is_draft', $seriesId));
        self::assertCount(14, $this->rounds($seriesId));

        // Quick "Add puzzle": the round's add form, then back to the series page
        $crawler = $this->browser->request('GET', '/en/manage-series/' . $seriesId);
        $link = (string) $crawler->filter('[data-quick-add-puzzle]')->first()->attr('href');
        self::assertStringContainsString('return=', $link);
        $crawler = $this->browser->request('GET', $link);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="round_puzzle_form"]')->form();
        $this->browser->submit($form, [
            'round_puzzle_form[brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'round_puzzle_form[puzzle]' => PuzzleFixture::PUZZLE_500_01,
        ]);
        self::assertResponseRedirects('/en/manage-series/' . $seriesId);
        $crawler = $this->browser->request('GET', '/en/manage-series/' . $seriesId);
        self::assertCount(13, $crawler->filter('[data-quick-add-puzzle]'));
    }

    public function testADraftSeriesOffersContinueSettingUpAndItsDatesDoNot(): void
    {
        // W6 / W2: "Continue setting up" of a draft series (event_wizard_series_continue) on its draft banner and in
        // "You organize"; its dates (editions) belong to the series' wizard - none of their own
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $seriesId = $this->startSeries();
        self::assertNotNull($seriesId);
        $continue = '/en/new-event/series/' . $seriesId;

        $crawler = $this->browser->request('GET', '/en/series/' . self::string($this->database()->fetchOne('SELECT slug FROM competition_series WHERE id = :id', ['id' => $seriesId])));
        self::assertResponseIsSuccessful();
        self::assertSame($continue, $crawler->filter('[data-draft-banner-own] [data-event-wizard-continue]')->attr('href'));

        $this->addMonthlyDates($seriesId);
        $crawler = $this->browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();
        self::assertSame($continue, $crawler->filter('[data-organized-id="' . $seriesId . '"] [data-event-wizard-continue]')->attr('href'));
        $editionIds = self::ids($this->database()->fetchFirstColumn('SELECT id FROM competition WHERE series_id = :id', ['id' => $seriesId]));
        self::assertNotSame([], $editionIds);
        foreach ($editionIds as $editionId) {
            self::assertCount(0, $crawler->filter('[data-organized-id="' . $editionId . '"] [data-event-wizard-continue]'));
        }

        // Without the wizard: no link
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', '/en/you-organize');
        self::assertSelectorNotExists('[data-organized-id="' . $seriesId . '"] [data-event-wizard-continue]');
    }

    /**
     * Basics of a new series, submitted - its id, null when nothing was created
     *
     * @param array<string, string> $overrides
     */
    private function startSeries(array $overrides = []): null|string
    {
        $before = self::ids($this->database()->fetchFirstColumn('SELECT id FROM competition_series'));
        $crawler = $this->browser->request('GET', '/en/new-event/series/basics');

        if ($this->browser->getResponse()->getStatusCode() !== 200) {
            return null;
        }

        $values = [];
        foreach (['name' => 'Night Cup', 'isOnline' => '0', 'location' => 'Club, Brno', ...$overrides] as $field => $value) {
            $values['event_wizard_series_basics_form[' . $field . ']'] = $value;
        }

        $this->browser->submit($crawler->filter('form[name="event_wizard_series_basics_form"]')->form(), $values);
        $created = array_values(array_diff(self::ids($this->database()->fetchFirstColumn('SELECT id FROM competition_series')), $before));

        return $created[0] ?? null;
    }

    private function addMonthlyDates(string $seriesId): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/series/' . $seriesId . '/dates?' . http_build_query(self::MONTHLY));
        $this->browser->submit($crawler->filter('#event-wizard-dates-preview form')->form());
        self::assertResponseRedirects('/en/new-event/series/' . $seriesId . '/review', 303);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rounds(string $seriesId): array
    {
        return ($this->database()->fetchAllAssociative(
            'SELECT cr.*, c.date_from, c.is_draft AS edition_draft FROM competition_round cr JOIN competition c ON c.id = cr.competition_id WHERE c.series_id = :id ORDER BY cr.starts_at',
            ['id' => $seriesId],
        ));
    }

    private function rowCount(string $sql, null|string $seriesId = null): int
    {
        $value = $this->database()->fetchOne($sql, $seriesId !== null ? ['id' => $seriesId] : []);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private static function value(Form $form, string $name): string
    {
        $field = $form->get($name);
        self::assertInstanceOf(FormField::class, $field);
        $value = $field->getValue();
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param list<mixed> $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_filter($ids, is_string(...)));
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
