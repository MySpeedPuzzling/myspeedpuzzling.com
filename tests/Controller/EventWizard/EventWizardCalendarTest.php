<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The wizard's calendar path - "Something else" (a meetup, a puzzle swap, a workshop - no rounds), which led to the
 * classic form until it was retired on 2026-11-18 (creation-wizard.md "Classic create retired"): Basics without a start
 * time, "What kind of event" required and never "Competition" (it tells the draft from a several-rounds draft without
 * rounds - EventWizardPath::of()), an online event may stay undated; then Review.
 */
final class EventWizardCalendarTest extends WebTestCase
{
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
    }

    public function testBasicsAskWhatKindOfEventAndNoStartTime(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=calendar');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        self::assertFalse($form->has('event_wizard_basics_form[startTime]'));
        // Asked up front, never "Competition"
        self::assertCount(4, $crawler->filter('[data-event-wizard-calendar-types] input[name="event_wizard_basics_form[eventTypes][]"]'));
        self::assertSame(['marathon', 'workshop', 'casual_puzzling', 'puzzle_swap'], $crawler->filter('input[name="event_wizard_basics_form[eventTypes][]"]')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        ));
        // Two steps on the rail: Basics, Review
        self::assertSame('Step 1 of 2', trim($crawler->filter('[data-event-wizard-bar] span')->first()->text()));
    }

    public function testNoKindOfEventIsRefusedAndCreatesNothing(): void
    {
        $before = $this->eventsCount();

        $this->submitBasics(['name' => 'Kindless Meetup'], []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-event-wizard-calendar-types]', 'Pick at least one kind of event.');
        self::assertSame($before, $this->eventsCount());
    }

    public function testAWorkshopIsADraftWithoutRoundsThenReviewAndPublish(): void
    {
        $competitionId = $this->submitBasics(['name' => 'Spring Puzzle Workshop'], ['workshop']);
        self::assertNotNull($competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        /** @var array{is_draft: bool, event_types: string, date_from: null|string, rounds: int|string} $row */
        $row = $this->database()->fetchAssociative(
            'SELECT is_draft, event_types, date_from, (SELECT COUNT(*) FROM competition_round cr WHERE cr.competition_id = c.id) AS rounds FROM competition c WHERE id = :id',
            ['id' => $competitionId],
        );
        self::assertTrue($row['is_draft']);
        self::assertSame(['workshop'], json_decode($row['event_types'], true));
        self::assertSame('2027-06-15 00:00:00', $row['date_from']);
        self::assertSame(0, (int) $row['rounds']);

        // Review shows its kind; resuming the draft lands there; Basics again keeps the calendar path
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-event-wizard-review-types]', 'Workshop');
        self::assertCount(0, $crawler->filter('[data-event-wizard-review-round]'));

        $this->browser->request('GET', '/en/new-event/' . $competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review');

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/basics');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-calendar-types]');

        // Several rounds' steps are not this path's
        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/structure');
        self::assertResponseRedirects('/en/edit-event/' . $competitionId);

        $this->browser->submit($crawler->selectButton('Publish')->form());
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);
        self::assertFalse($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    public function testAnOnlineEventMayStayUndatedAnInPersonOneMayNot(): void
    {
        // Always on, online: no date
        $competitionId = $this->submitBasics(['name' => 'Ongoing Online Swap', 'isOnline' => '1', 'location' => '', 'dateFrom' => ''], ['puzzle_swap']);
        self::assertNotNull($competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertNull($this->database()->fetchOne('SELECT date_from FROM competition WHERE id = :id', ['id' => $competitionId]));

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertResponseIsSuccessful();

        // In person: the day is needed
        self::assertNull($this->submitBasics(['name' => 'Undated Meetup', 'dateFrom' => ''], ['casual_puzzling']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testASeveralRoundsDraftThatIsNoCompetitionGoesOnAsACalendarEvent(): void
    {
        // Several rounds' Basics with only "Puzzle marathon" ticked under "More details": no round, not a competition -
        // the saved draft is on the calendar path, so Review follows (never a Structure page that would send it away)
        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=several_rounds&structure=qualification');
        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form([
            'event_wizard_basics_form[name]' => 'Weekend Marathon',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Library, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
        ]);
        self::tickTypes($form, ['marathon']);
        $before = $this->competitionIds();
        $this->browser->submit($form);

        $created = array_values(array_diff($this->competitionIds(), $before));
        self::assertCount(1, $created);
        self::assertResponseRedirects('/en/new-event/' . $created[0] . '/review', 303);

        // Without a kind it stays several rounds: Structure with the entry's template
        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=several_rounds&structure=qualification');
        $before = $this->competitionIds();
        $this->browser->submit($crawler->filter('form[name="event_wizard_basics_form"]')->form([
            'event_wizard_basics_form[name]' => 'Qualifier Cup',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Library, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
        ]));
        $created = array_values(array_diff($this->competitionIds(), $before));
        self::assertCount(1, $created);
        self::assertResponseRedirects('/en/new-event/' . $created[0] . '/structure?structure=qualification', 303);
    }

    public function testALinkToAnotherOrganizationPicksNone(): void
    {
        // Not on Riverbend's team: the event is nobody's organization's (it waits for an admin)
        $competitionId = $this->submitBasics(['name' => 'Lone Swap Evening'], ['puzzle_swap'], '&organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertNotNull($competitionId);
        self::assertNull($this->database()->fetchOne('SELECT organization_id FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    /**
     * Basics of the calendar path - the id of the event it created, null when none
     *
     * @param array<string, string> $overrides
     * @param list<string> $types
     */
    private function submitBasics(array $overrides, array $types, string $query = ''): null|string
    {
        $crawler = $this->browser->request('GET', '/en/new-event/basics?path=calendar' . $query);
        self::assertResponseIsSuccessful();

        $values = [];
        foreach (['isOnline' => '0', 'location' => 'Library, Brno', 'dateFrom' => '15.06.2027', ...$overrides] as $field => $value) {
            $values['event_wizard_basics_form[' . $field . ']'] = $value;
        }

        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form($values);
        self::tickTypes($form, $types);

        $before = $this->competitionIds();
        $this->browser->submit($form);
        $created = array_values(array_diff($this->competitionIds(), $before));

        return $created[0] ?? null;
    }

    /**
     * @param list<string> $types
     */
    private static function tickTypes(Form $form, array $types): void
    {
        $boxes = $form['event_wizard_basics_form[eventTypes]'];
        self::assertIsArray($boxes);

        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            // A ticked box holds its own value
            $box->tick();

            if (in_array($box->getValue(), $types, true) === false) {
                $box->untick();
            }
        }
    }

    private function eventsCount(): int
    {
        $count = $this->database()->fetchOne('SELECT COUNT(*) FROM competition');
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function competitionIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database()->fetchFirstColumn('SELECT id::text FROM competition');

        return $ids;
    }

    private function database(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
