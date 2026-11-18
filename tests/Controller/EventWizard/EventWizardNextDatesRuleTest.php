<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\AddEditionsFormData;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The rule of "Add the next dates" (event_wizard_series_next_dates - the classic "Add several dates" page it shares the
 * rule, preview and names with was retired 2026-11-18, these are its tests moved here; docs/features/organizations/
 * README.md P15/P16): the rule or the picked days are previewed with a GET (no JavaScript needed), every proposed day
 * checked unless the series already has an edition that day, and one POST creates the checked ones - at most 24. Every
 * query says "No rounds - just dates" (`withRounds=0`): the rounds of the dates are EventWizardSeriesTest's.
 */
final class EventWizardNextDatesRuleTest extends WebTestCase
{
    private const string URL = '/en/new-event/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/next-dates';

    private const array NO_ROUNDS = ['withRounds' => '0'];

    public function testOnlyTheSeriesTeam(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::URL);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheFormStartsWithTheSeriesNameAndShowsNoDatesYet(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::SERIES_LANTERN_NIGHTS_NAME . ' {date}', $crawler->filter('input[name="namePattern"]')->attr('value'));
        self::assertSame('get', strtolower((string) $crawler->filter('form.ev-add-editions-rule')->attr('method')));
        self::assertCount(0, $crawler->filter('.ev-add-editions-preview'));
    }

    public function testARulePreviewsItsDatesWithTheirNames(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::URL);
        $crawler = $browser->submitForm('Show the dates', [
            'how' => 'repeat',
            'rule' => 'last_weekday',
            'weekday' => '2',
            'starting' => '01.10.2027',
            'count' => '6',
            'namePattern' => 'Lantern Night {date}',
            'withRounds' => '0',
        ], 'GET');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('rule=last_weekday', (string) $browser->getRequest()->getUri());
        self::assertSame('6 dates', $crawler->filter('#event-wizard-dates-title')->text());
        self::assertSame(
            ['2027-10-26', '2027-11-30', '2027-12-28', '2028-01-25', '2028-02-29', '2028-03-28'],
            $this->checkedDays($crawler),
        );
        self::assertSame('Lantern Night 26 October 2027', $crawler->filter('[data-date="2027-10-26"] .ev-add-editions-name')->text());
        self::assertStringContainsString('2027', $crawler->filter('[data-date="2027-10-26"] .ev-add-editions-day')->text());
    }

    public function testCreatingTheCheckedDates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query([
            'how' => 'repeat',
            'rule' => 'nth_weekday',
            'nth' => 1,
            'weekday' => 6,
            'starting' => '01.01.2028',
            'count' => 3,
            'namePattern' => 'First Saturday Night',
            ...self::NO_ROUNDS,
        ]));
        // Without {date} the date goes at the end - every edition gets a name of its own
        self::assertSame(['2028-01-01', '2028-02-05', '2028-03-04'], $this->checkedDays($crawler));
        self::assertSame('First Saturday Night 1 January 2028', $crawler->filter('[data-date="2028-01-01"] .ev-add-editions-name')->text());

        // February unchecked
        $this->post($browser, $crawler, ['2028-01-01', '2028-03-04']);

        self::assertResponseRedirects('/en/manage-series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS, 303);
        $rows = array_map(
            static fn (array $row): array => [$row['name'], $row['date_from'], $row['date_to'], $row['is_draft'], $row['slug']],
            $this->editionsNamed('First Saturday Night%'),
        );
        // The series is published - so are its new dates
        self::assertSame([
            ['First Saturday Night 1 January 2028', '2028-01-01 00:00:00', '2028-01-01 00:00:00', false, 'first-saturday-night-1-january-2028'],
            ['First Saturday Night 4 March 2028', '2028-03-04 00:00:00', '2028-03-04 00:00:00', false, 'first-saturday-night-4-march-2028'],
        ], $rows);
    }

    public function testADayWithAnEditionIsMarkedAndLeftUnchecked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $dateFrom = $this->connection()->fetchOne('SELECT date_from FROM competition WHERE id = ?', [OrganizationFixture::EDITION_LANTERN_1]);
        self::assertIsString($dateFrom);
        $takenDay = new DateTimeImmutable($dateFrom);
        $freeDay = $takenDay->modify('+3 days');

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query([
            'how' => 'pick',
            'dates' => $takenDay->format('d.m.Y') . ', ' . $freeDay->format('d.m.Y'),
            'namePattern' => 'Lantern {date}',
            ...self::NO_ROUNDS,
        ]));

        self::assertSame([$freeDay->format('Y-m-d')], $this->checkedDays($crawler));
        $taken = $crawler->filter('[data-date="' . $takenDay->format('Y-m-d') . '"]');
        self::assertStringContainsString('is-taken', (string) $taken->attr('class'));
        self::assertSame('already has an edition', $taken->filter('.ev-add-editions-taken')->text());
    }

    public function testNothingCheckedIsRefusedAndKeepsThePreview(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '05.03.2028', 'namePattern' => 'Lantern {date}', ...self::NO_ROUNDS]));
        $this->post($browser, $crawler, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.ev-add-editions-preview .alert-danger', 'Check at least one date.');
        self::assertSelectorExists('[data-date="2028-03-05"] input:not([checked])');
        self::assertSame([], $this->editionsNamed('Lantern 5 March 2028'));
    }

    public function testOnlyProposedDaysAreCreated(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '05.03.2028', 'namePattern' => 'Lantern {date}', ...self::NO_ROUNDS]));
        // A day the rule never proposed is ignored
        $this->post($browser, $crawler, ['2028-03-05', '2028-04-01']);

        self::assertResponseRedirects();
        self::assertCount(1, $this->editionsNamed('Lantern % 2028'));
    }

    public function testAFormSentTwiceCreatesNothingTwice(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '05.03.2028, 12.03.2028', 'namePattern' => 'Twice {date}', ...self::NO_ROUNDS]));
        $this->post($browser, $crawler, ['2028-03-05', '2028-03-12']);
        self::assertResponseRedirects();
        self::assertCount(2, $this->editionsNamed('Twice %'));

        // The same form again (a double click, the back button): the same ids - nothing new
        $this->post($browser, $crawler, ['2028-03-05', '2028-03-12']);
        self::assertResponseRedirects();
        self::assertCount(2, $this->editionsNamed('Twice %'));

        // A fresh preview of the same days (new ids): the days hold editions now - still nothing twice
        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '05.03.2028', 'namePattern' => 'Again {date}', ...self::NO_ROUNDS]));
        $browser->request('POST', (string) $crawler->filter('.ev-add-editions-preview form')->attr('action'), [
            '_token' => (string) $crawler->filter('.ev-add-editions-preview input[name="_token"]')->attr('value'),
            'selected' => ['2028-03-05'],
            'ids' => ['2028-03-05' => (string) Uuid::uuid7()],
        ]);
        self::assertResponseRedirects();
        self::assertSame([], $this->editionsNamed('Again %'));
    }

    public function testARefusedPostKeepsTheIdsOfTheDays(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '05.03.2028', 'namePattern' => 'Lantern {date}', ...self::NO_ROUNDS]));
        $id = (string) $crawler->filter('input[name="ids[2028-03-05]"]')->attr('value');
        self::assertTrue(Uuid::isValid($id));

        $this->post($browser, $crawler, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="ids[2028-03-05]"][value="' . $id . '"]');
    }

    public function testDaysTypedWithSpacesAndAUrlWithoutHowAreRead(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => '5. 3. 2028, 12. 3. 2028.', 'namePattern' => 'Spaced {date}', ...self::NO_ROUNDS]));
        self::assertResponseIsSuccessful();
        self::assertSame(['2028-03-05', '2028-03-12'], $this->checkedDays($crawler));

        // A hand-made URL without `how` repeats
        $browser->request('GET', self::URL . '?' . http_build_query(['rule' => 'weekly', 'weekday' => '1', 'starting' => '01.01.2028', 'count' => '2', 'namePattern' => 'X {date}', ...self::NO_ROUNDS]));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.ev-add-editions-preview input[name="selected[]"]');
    }

    public function testTheDefaultNameFitsALongSeriesName(): void
    {
        $pattern = AddEditionsFormData::defaultNamePattern(str_repeat('Lantern ', 40));

        self::assertLessThanOrEqual(AddEditionsFormData::NAME_PATTERN_MAX_LENGTH, mb_strlen($pattern));
        self::assertStringEndsWith(' {date}', $pattern);
        self::assertSame('Lantern Brewing Puzzle Night {date}', AddEditionsFormData::defaultNamePattern('Lantern Brewing Puzzle Night'));
    }

    public function testATokenIsRequired(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', self::URL . '?how=pick&dates=05.03.2028&namePattern=X&withRounds=0', ['_token' => 'nope', 'selected' => ['2028-03-05']]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function provideInvalidRules(): iterable
    {
        yield 'more than 24' => [['how' => 'repeat', 'rule' => 'weekly', 'weekday' => '1', 'starting' => '01.01.2028', 'count' => '25', 'namePattern' => 'X {date}', 'withRounds' => '0'], 'Choose 1 to 24 dates.'];
        yield 'no start' => [['how' => 'repeat', 'rule' => 'weekly', 'weekday' => '1', 'starting' => '', 'count' => '3', 'namePattern' => 'X {date}', 'withRounds' => '0'], 'Choose the day the dates start from.'];
        yield 'no picked day' => [['how' => 'pick', 'dates' => '', 'namePattern' => 'X {date}', 'withRounds' => '0'], 'Pick at least one date.'];
        yield 'an unreadable day' => [['how' => 'pick', 'dates' => '05.03.2028, next friday', 'namePattern' => 'X {date}', 'withRounds' => '0'], "Some of the dates can't be read"];
        yield 'no name' => [['how' => 'pick', 'dates' => '05.03.2028', 'namePattern' => '', 'withRounds' => '0'], 'This value should not be blank.'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('provideInvalidRules')]
    public function testAnInvalidRuleIsAFormErrorWithoutPreview(array $query, string $error): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query($query));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('.ev-add-editions-preview'));
        self::assertStringContainsString($error, $crawler->filter('form.ev-add-editions-rule')->text());
    }

    public function testTwentyFivePickedDaysAreTooMany(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $days = [];
        for ($day = 1; $day <= 25; $day++) {
            $days[] = sprintf('%02d.01.2028', $day);
        }

        $crawler = $browser->request('GET', self::URL . '?' . http_build_query(['how' => 'pick', 'dates' => implode(', ', $days), 'namePattern' => 'X {date}', ...self::NO_ROUNDS]));
        self::assertCount(0, $crawler->filter('.ev-add-editions-preview'));
        self::assertStringContainsString('At most 24 dates at once.', $crawler->filter('form.ev-add-editions-rule')->text());
    }

    /**
     * @param list<string> $days
     */
    private function post(KernelBrowser $browser, Crawler $crawler, array $days): void
    {
        $form = $crawler->filter('.ev-add-editions-preview form');
        self::assertCount(1, $form);

        $ids = [];

        foreach ($form->filter('input[name^="ids["]') as $input) {
            assert($input instanceof \DOMElement);
            $ids[substr($input->getAttribute('name'), 4, -1)] = $input->getAttribute('value');
        }

        $values = ['_token' => (string) $form->filter('input[name="_token"]')->attr('value'), 'selected' => $days, 'ids' => $ids];

        $browser->request('POST', (string) $form->attr('action'), $values);
    }

    /**
     * @return list<string>
     */
    private function checkedDays(Crawler $crawler): array
    {
        return $crawler->filter('.ev-add-editions-preview input[name="selected[]"][checked]')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        );
    }

    /**
     * @return list<array{name: string, date_from: string, date_to: string, is_draft: bool, eligibility: null|string, slug: string, location: null|string}>
     */
    private function editionsNamed(string $like): array
    {
        /** @var list<array{name: string, date_from: string, date_to: string, is_draft: bool, eligibility: null|string, slug: string, location: null|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT name, date_from, date_to, is_draft, eligibility, slug, location FROM competition WHERE series_id = ? AND name LIKE ? ORDER BY date_from',
            [OrganizationFixture::SERIES_LANTERN_NIGHTS, $like],
        );

        return $rows;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
