<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The creation wizard's backfill path (docs/features/competitions-management/creation-wizard.md "Backfill"): a player
 * who did not organise an event adds it to link their results - "Is it one of these?" before anything is created, the
 * event submitted for approval (never a draft), rounds made from the puzzle rows, "Link my results", Done.
 */
final class EventWizardBackfillTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::PLAYER);
    }

    public function testTheEntryOffersAnEventThatAlreadyHappened(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What do you want to add?');
        self::assertSame('past', $crawler->filter('[data-event-wizard-past-choice] input')->attr('value'));
        // The organiser's answer stays the preselected one
        self::assertNotNull($crawler->filter('#event-wizard-kind-one_round')->attr('checked'));

        $this->browser->request('GET', '/en/new-event?kind=past');
        self::assertResponseRedirects('/en/new-event/past');
    }

    public function testNobodySignedInGetsNothing(): void
    {
        self::ensureKernelShutdown();
        $guest = self::createClient();
        $guest->request('GET', '/en/new-event/past');
        self::assertNotSame(200, $guest->getResponse()->getStatusCode());
    }

    public function testAnIncompleteFormIs422(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), ['past_event_form[name]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows('competition', "name = ''"));
    }

    public function testANewEventIsSubmittedForApprovalNeverADraft(): void
    {
        $id = $this->createEvent(['name' => 'Backfill Mountain Classic', 'date' => '03.05.2026', 'location' => 'Hut, Krkonose']);

        $row = $this->database()->fetchAssociative('SELECT is_draft, approved_at, submitted_at, added_by_player_id, date_from, location_country_code FROM competition WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);
        self::assertFalse($row['is_draft']);
        self::assertNull($row['approved_at']);
        self::assertNotNull($row['submitted_at']);
        self::assertSame(self::PLAYER, $row['added_by_player_id']);
        self::assertStringStartsWith('2026-05-03', self::text($row['date_from']));
        self::assertSame('cz', $row['location_country_code']);
        // The admins hear of it as of every new event
        self::assertQueuedEmailCount(1);
    }

    public function testIsItOneOfTheseShowsTheExistingEventFirstAndCreatesNothing(): void
    {
        $before = $this->rows('competition');
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => 'czech national championship', 'date' => '01.01.2025']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->rows('competition'), 'Nothing is created before the player decides');
        $card = $this->browser->getCrawler()->filter('[data-event-wizard-past-match="event"][data-match-id="' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '"]');
        self::assertCount(1, $card);
        // Not the player's event: straight to "Link my results"
        self::assertStringContainsString('/en/new-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/link-results', (string) $card->filter('[data-match-action="link"]')->attr('href'));

        // "None of these - add it" creates it (the form kept every value)
        $this->browser->submit($this->browser->getCrawler()->selectButton('None of these - add it')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertSame($before + 1, $this->rows('competition'));
    }

    public function testTheOwnPendingEventIsFoundAndLeadsToItsPuzzles(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => 'Unapproved Puzzle Event', 'date' => '01.01.2025']));

        self::assertResponseStatusCodeSame(422);
        $card = $this->browser->getCrawler()->filter('[data-match-id="' . CompetitionFixture::COMPETITION_UNAPPROVED . '"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Not public yet', $card->text());
        self::assertStringContainsString('/past/puzzles', (string) $card->filter('[data-match-action="puzzles"]')->attr('href'));
    }

    public function testSomebodyElsesDraftIsNeverAMatch(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME]));

        self::assertSelectorNotExists('[data-match-id="' . OrganizationFixture::COMPETITION_DRAFT_NIGHT . '"]');
    }

    public function testASeriesIsCreatedInlineWithTheEventAsItsDate(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values([
            'name' => 'Quarry Cup 3',
            'partOf' => '1',
            'series' => 'new',
            'newSeriesName' => 'Quarry Cup Zebrafish',
        ]));
        self::assertResponseStatusCodeSame(303);

        $series = $this->database()->fetchAssociative("SELECT id, approved_at, is_draft FROM competition_series WHERE name = 'Quarry Cup Zebrafish'");
        self::assertIsArray($series);
        self::assertNull($series['approved_at']);
        self::assertFalse($series['is_draft']);
        self::assertSame(1, $this->rows('competition', "series_id = '" . self::text($series['id']) . "' AND name = 'Quarry Cup 3'"));
    }

    public function testANewSeriesNeedsItsName(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => 'Quarry Cup 4', 'partOf' => '1', 'series' => 'new', 'newSeriesName' => '']));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAPublicSeriesTheOrganiserMayEditGetsTheDateInOneClick(): void
    {
        // PLAYER_WITH_STRIPE runs Riverbend's Lantern nights
        $this->browser = $this->newClient(PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => 'Lantern Brewing Night', 'location' => 'Riverbend']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-event-wizard-past-match="series"][data-match-id="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"] [data-match-action="add_date"]');

        $editions = $this->rows('competition', "series_id = '" . OrganizationFixture::SERIES_LANTERN_NIGHTS . "'");
        $form = $this->browser->getCrawler()->filter('[data-match-id="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"] [data-match-action="add_date"]')->form();
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($editions + 1, $this->rows('competition', "series_id = '" . OrganizationFixture::SERIES_LANTERN_NIGHTS . "'"));
    }

    public function testASeriesThePlayerMayNotEditLinksTheResultsToTheSeries(): void
    {
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $this->browser->submit($crawler->filter('form[name="past_event_form"]')->form(), $this->values(['name' => 'Lantern Brewing Night', 'date' => '20.10.2026']));
        self::assertResponseStatusCodeSame(422);

        $link = $this->browser->getCrawler()->filter('[data-match-id="' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '"] [data-match-action="link_series"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('/en/new-event/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS . '/link-results?date=2026-10-20', (string) $link->attr('href'));

        $this->browser->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-event-wizard-rail]');
    }

    public function testThePuzzleRowsBecomeRoundsOnceEvenWhenSentTwice(): void
    {
        $id = $this->createEvent(['name' => 'Backfill Rounds Open', 'date' => '10.04.2026']);
        self::assertResponseRedirects('/en/new-event/' . $id . '/past/puzzles', 303);

        $values = [
            'past_puzzles_form[rows][0][category]' => 'solo',
            'past_puzzles_form[rows][0][slot][brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'past_puzzles_form[rows][0][slot][puzzle]' => PuzzleFixture::PUZZLE_500_02,
        ];

        foreach ([1, 2] as $attempt) {
            $crawler = $this->browser->request('GET', '/en/new-event/' . $id . '/past/puzzles');
            self::assertResponseIsSuccessful();
            $this->browser->submit($crawler->filter('form[name="past_puzzles_form"]')->form(), $values);
            self::assertResponseRedirects('/en/new-event/' . $id . '/link-results', 303, 'Attempt ' . $attempt);
        }

        $rounds = $this->database()->fetchAllAssociative('SELECT name, minutes_limit, start_time_known, category FROM competition_round WHERE competition_id = :id', ['id' => $id]);
        self::assertCount(1, $rounds);
        self::assertSame('Solo', $rounds[0]['name']);
        self::assertNull($rounds[0]['minutes_limit']);
        self::assertFalse($rounds[0]['start_time_known']);
        self::assertSame(1, $this->rows('competition_round_puzzle crp JOIN competition_round r ON r.id = crp.round_id', "r.competition_id = '" . $id . "'"));
    }

    public function testSomebodyElsesEventsPuzzlesStepLeadsToLinkingOnly(): void
    {
        $this->browser->request('GET', '/en/new-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/past/puzzles');
        self::assertResponseRedirects('/en/new-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/link-results');
    }

    public function testLinkingToASomebodyElsesPublicEventLinksAtOnce(): void
    {
        // TIME_01 (PUZZLE_500_01, the Czech final's puzzle) is listed - of the event's puzzle, though another day
        $crawler = $this->browser->request('GET', '/en/new-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/link-results');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-time-id="' . PuzzleSolvingTimeFixture::TIME_01 . '"]');
        // Somebody else's result is never listed
        self::assertSelectorNotExists('[data-time-id="' . PuzzleSolvingTimeFixture::TIME_02 . '"]');

        $form = $crawler->filter('form[name="link_results"]')->form();
        $boxes = $form['link_results[times]'];
        self::assertIsArray($boxes);

        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);

            if (in_array(PuzzleSolvingTimeFixture::TIME_01, $box->availableOptionValues(), true)) {
                $box->tick();
            } else {
                $box->untick();
            }
        }

        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $this->database()->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_01]));
    }

    public function testSomebodyElsesDraftIsA404(): void
    {
        $this->browser->request('GET', '/en/new-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT . '/link-results');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheWholeBackfillOfAPendingEventWaitsAndTheDonePageSaysSo(): void
    {
        // Dated like TIME_08 (PLAYER_REGULAR's PUZZLE_500_02, 10 days ago) so it is pre-ticked
        $day = self::text($this->database()->fetchOne('SELECT to_char(COALESCE(finished_at, tracked_at), \'DD.MM.YYYY\') FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_08]));
        $id = $this->createEvent(['name' => 'Backfill Waiting Meetup', 'date' => $day]);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $id . '/past/puzzles');
        $this->browser->submit($crawler->filter('form[name="past_puzzles_form"]')->form(), [
            'past_puzzles_form[rows][0][slot][brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'past_puzzles_form[rows][0][slot][puzzle]' => PuzzleFixture::PUZZLE_500_02,
        ]);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $id . '/link-results');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-link-waits]');
        self::assertSelectorExists('[data-event-wizard-rail]');
        $box = $crawler->filter('[data-time-id="' . PuzzleSolvingTimeFixture::TIME_08 . '"] input[type="checkbox"]');
        self::assertSame('checked', $box->attr('checked'), 'A result of the event\'s day and puzzle, linked to nothing, is pre-ticked');

        $this->browser->submit($crawler->filter('form[name="link_results"]')->form());
        self::assertResponseRedirects('/en/new-event/' . $id . '/past/done', 303);
        // Never linked to an event nobody may see
        self::assertNull($this->database()->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_08]));
        self::assertSame(1, $this->rows('waiting_event_link', "competition_id = '" . $id . "'"));

        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-past-pending]');
        self::assertSelectorTextContains('[data-event-wizard-past-waiting]', 'linked once an admin approves the event');
        self::assertSelectorTextContains('[data-event-wizard-past-rounds]', 'Solo');

        // The time's edit form still works while its link waits
        $this->browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_08);
        self::assertResponseIsSuccessful();
    }

    public function testTheDonePageIsTheOrganisersOnly(): void
    {
        $this->browser->request('GET', '/en/new-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/past/done');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function createEvent(array $overrides): string
    {
        $before = $this->ids();
        $crawler = $this->browser->request('GET', '/en/new-event/past');
        $form = $crawler->filter('form[name="past_event_form"]')->form();
        $this->browser->submit($form, $this->values($overrides));

        if ($this->browser->getResponse()->getStatusCode() === 422) {
            $this->browser->submit($this->browser->getCrawler()->selectButton('None of these - add it')->form());
        }

        self::assertResponseStatusCodeSame(303);
        $new = array_values(array_diff($this->ids(), $before));
        self::assertCount(1, $new);

        return $new[0];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function values(array $overrides): array
    {
        $values = [];

        foreach (['name' => 'Backfill Cup', 'date' => '12.09.2026', 'isOnline' => '0', 'location' => 'Club house, Brno', ...$overrides] as $field => $value) {
            $values['past_event_form[' . $field . ']'] = $value;
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        return array_map(self::text(...), $this->database()->fetchFirstColumn('SELECT id FROM competition'));
    }

    private function rows(string $table, string $where = 'true'): int
    {
        return (int) self::text($this->database()->fetchOne("SELECT COUNT(*) FROM {$table} WHERE {$where}"));
    }

    private function newClient(string $player): KernelBrowser
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
