<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The event creation wizard's "Extras" step (W6, docs/features/competitions-management/toolkit/
 * W-creation-wizard-research.md §3.8-§3.10): each switch writes its existing message, coming back shows the event as it
 * is, toolkit rows only for whom COMPETITION_TOOLKIT_MANAGE grants, 422s, skipping with nothing on, Review's list and
 * the checklist rows of what was switched on.
 */
final class EventWizardExtrasTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'EVENT_CREATION_WIZARD';
    private const string TOOLKIT_FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;
    private const string FORM = 'event_wizard_extras_form';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $this->overrideFeatureFlagEnv(self::TOOLKIT_FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testContinueWithNothingOnWritesNothingAndGoesToReview(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        $before = $this->snapshot($competitionId);

        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertResponseIsSuccessful();
        // Every switch off for a new event - the toolkit rows too (every organiser has them since the toolkit flag went)
        foreach (['registration', 'elsewhere', 'judges', 'qrTags', 'timerScreen'] as $row) {
            self::assertNull($crawler->filter('input[name="' . self::FORM . '[' . $row . ']"]')->attr('checked'), $row);
        }
        self::assertSelectorExists('[data-event-wizard-toolkit-rows]');

        $this->browser->submit($crawler->filter('form[name="' . self::FORM . '"]')->form());
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertSame($before, $this->snapshot($competitionId));

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorExists('[data-event-wizard-review-extra="none"]');
        self::assertStringEndsWith('/extras', (string) $this->browser->getCrawler()->filter('[data-event-wizard-review-extras] a')->attr('href'));
    }

    public function testRegistrationOnSavesItsSettingsAndOffKeepsThem(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();

        $this->submit($competitionId, [
            'registration' => true,
            'capacity' => '80',
            'opensAt' => '01.05.2027 10:00',
            'closesAt' => '10.06.2027 20:00',
            'entryFeeText' => ' 10 EUR ',
        ]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $row = $this->competitionRow($competitionId);
        self::assertTrue($row['registration_managed']);
        self::assertSame(80, $row['capacity']);
        self::assertSame('10 EUR', $row['entry_fee_text']);
        // Typed in the zone of the event's round (not asked)
        self::assertSame($this->database()->fetchOne('SELECT timezone FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]), $row['registration_timezone']);

        // Coming back shows it on, with its values; Review lists it
        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertSame('checked', $crawler->filter('input[name="' . self::FORM . '[registration]"]')->attr('checked'));
        self::assertSame('80', $crawler->filter('input[name="' . self::FORM . '[capacity]"]')->attr('value'));
        self::assertSame('01.05.2027 10:00', $crawler->filter('input[name="' . self::FORM . '[opensAt]"]')->attr('value'));
        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorTextContains('[data-event-wizard-review-extra="registration"]', '80 places');

        // The step is done (the rail ticks it); resuming still goes to the puzzle decided later
        $this->browser->request('GET', '/en/new-event/' . $competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle');

        // Switched off: management off, the settings kept (the registration page's unticking)
        $this->submit($competitionId, ['registration' => false]);
        $row = $this->competitionRow($competitionId);
        self::assertFalse($row['registration_managed']);
        self::assertSame(80, $row['capacity']);
    }

    public function testARefusedRowWritesNothingAndAnswers422(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        $before = $this->snapshot($competitionId);

        // Closing before opening + a link that is no link + judges on without anybody
        $this->submit($competitionId, [
            'registration' => true,
            'opensAt' => '10.06.2027 10:00',
            'closesAt' => '01.06.2027 10:00',
            'elsewhere' => true,
            'registrationLink' => 'not a link',
            'judges' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-event-wizard-extra="registration"]', 'Registration cannot close before it opens.');
        self::assertSelectorTextContains('[data-event-wizard-extra="judges"]', 'Choose at least one judge');
        self::assertSame($before, $this->snapshot($competitionId));

        // Switched on without any link
        $this->submit($competitionId, ['elsewhere' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-event-wizard-extra="elsewhere"]', 'Add at least one link');

        // The fields of a row switched off are ignored, also by the validation
        $this->submit($competitionId, ['elsewhere' => false, 'registrationLink' => 'not a link', 'capacity' => '-5']);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertSame($before, $this->snapshot($competitionId));
    }

    public function testLinksElsewhereKeepEveryOtherFieldOfTheEvent(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        $before = $this->competitionRow($competitionId);

        $this->submit($competitionId, ['elsewhere' => true, 'resultsLink' => 'https://example.com/results']);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $after = $this->competitionRow($competitionId);
        self::assertNull($after['registration_link']);
        self::assertSame('https://example.com/results', $after['results_link']);
        foreach (['name', 'slug', 'location', 'location_country_code', 'date_from', 'date_to', 'is_online', 'is_draft', 'description', 'link'] as $kept) {
            self::assertSame($before[$kept], $after[$kept], $kept);
        }

        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertSame('checked', $crawler->filter('input[name="' . self::FORM . '[elsewhere]"]')->attr('checked'));

        $this->submit($competitionId, ['elsewhere' => false]);
        self::assertNull($this->competitionRow($competitionId)['results_link']);
    }

    public function testJudgesAreAddedListedAndRemovedWhenSwitchedOff(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();

        $this->submit($competitionId, [
            'judges' => true,
            'judgePlayers' => PlayerFixture::PLAYER_PRIVATE . ',' . PlayerFixture::PLAYER_WITH_FAVORITES . ',' . self::ORGANISER,
        ]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertSame(2, $this->referees($competitionId));

        // The organiser is not added - said on the next page
        $this->browser->followRedirect();
        self::assertStringContainsString('Organises this event', (string) $this->browser->getResponse()->getContent());
        $this->browser->request('GET', $this->url($competitionId));
        self::assertCount(2, $this->browser->getCrawler()->filter('[data-event-wizard-judges] li'));
        self::assertSame('checked', $this->browser->getCrawler()->filter('input[name="' . self::FORM . '[judges]"]')->attr('checked'));
        self::assertSelectorExists('[data-event-wizard-judges-removed]');

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorTextContains('[data-event-wizard-review-extra="judges"]', '2 judges');

        // Continuing with the switch on and nobody new keeps them
        $this->submit($competitionId, []);
        self::assertSame(2, $this->referees($competitionId));

        $this->submit($competitionId, ['judges' => false]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertSame(0, $this->referees($competitionId));
    }

    public function testToolkitRowsWriteTheirSettingsAndDisplays(): void
    {
        $this->overrideFeatureFlagEnv(self::TOOLKIT_FLAG, true);
        self::ensureKernelShutdown();
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();

        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertSelectorExists('[data-event-wizard-toolkit-rows]');
        // One round: no overall standings to add up
        self::assertSelectorNotExists('[data-event-wizard-extra="standings"]');
        self::assertCount(3, $crawler->filter('input[name="' . self::FORM . '[screenPreset]"]'));

        $this->submit($competitionId, [
            'qrTags' => true,
            'qrTagMode' => 'table',
            'competitorPages' => true,
            'timeProposals' => true,
            'timerScreen' => true,
            'screenPreset' => 'timer_sponsor_bar',
            'overlay' => true,
            'overlayPreset' => 'obs_corner_timer',
        ]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $row = $this->competitionRow($competitionId);
        self::assertSame('table', $row['qr_tag_mode']);
        self::assertTrue($row['competitor_pages']);
        self::assertTrue($row['time_proposals']);
        self::assertFalse($row['competitor_pages_live_results']);
        self::assertSame(
            [['kind' => 'overlay', 'name' => 'Stream overlay', 'locale' => 'en'], ['kind' => 'screen', 'name' => 'Timer screen', 'locale' => 'en']],
            $this->database()->fetchAllAssociative('SELECT kind, name, locale FROM competition_display WHERE competition_id = :id ORDER BY kind', ['id' => $competitionId]),
        );

        // Coming back: the displays are managed on their page, not switched off here
        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertSelectorNotExists('input[name="' . self::FORM . '[timerScreen]"]');
        self::assertSelectorExists('[data-event-wizard-extra="timer_screen"][data-event-wizard-set-up]');
        self::assertSelectorExists('[data-event-wizard-extra="overlay"][data-event-wizard-set-up]');
        self::assertSame('checked', $crawler->filter('input[name="' . self::FORM . '[qrTags]"]')->attr('checked'));
        self::assertSame('checked', $crawler->filter('input[name="' . self::FORM . '[timeProposals]"]')->attr('checked'));

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        foreach (['qr_tags', 'competitor_pages', 'timer_screen', 'overlay'] as $listed) {
            self::assertSelectorExists('[data-event-wizard-review-extra="' . $listed . '"]', $listed);
        }

        // Competitor pages off takes the time proposals with them
        $this->submit($competitionId, ['qrTags' => false, 'competitorPages' => false]);
        $row = $this->competitionRow($competitionId);
        self::assertNull($row['qr_tag_mode']);
        self::assertFalse($row['competitor_pages']);
        self::assertFalse($row['time_proposals']);
    }

    public function testThePublishedEventsChecklistHasTheRowsOfWhatWasSwitchedOn(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft();

        $this->submit($competitionId, [
            'registration' => true,
            'capacity' => '80',
            'judges' => true,
            'judgePlayers' => PlayerFixture::PLAYER_PRIVATE,
            'qrTags' => true,
            'qrTagMode' => 'entrant',
            'timerScreen' => true,
            'overlay' => true,
        ]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        $this->browser->submit($crawler->selectButton('Publish')->form());
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);
        $this->browser->followRedirect();

        self::assertSelectorTextContains('[data-get-ready-row="registration"]', '0 of 80 places taken');
        self::assertSelectorTextContains('[data-get-ready-row="judges"]', 'Judge added (1)');
        self::assertSelectorTextContains('[data-get-ready-row="name_tags"]', 'Print name tags with QR codes');
        self::assertSelectorExists('[data-get-ready-row="timer_screen"]');
        self::assertSelectorExists('[data-get-ready-row="overlay"]');
    }

    public function testAnOnlineEventHasNoRowsForTheHall(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft();
        $this->database()->executeStatement('UPDATE competition SET is_online = true, location = NULL WHERE id = :id', ['id' => $competitionId]);

        $this->browser->request('GET', $this->url($competitionId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-event-wizard-toolkit-rows]');
        self::assertSelectorNotExists('[data-event-wizard-extra="qr_tags"]');
        self::assertSelectorNotExists('[data-event-wizard-extra="timer_screen"]');
        self::assertSelectorExists('input[name="' . self::FORM . '[overlay]"]');
    }

    public function testAPublishedEventsExtrasLeadToItsEditPage(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        $this->database()->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => $competitionId]);

        $this->browser->request('GET', $this->url($competitionId));
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 302);
    }

    /**
     * Basics of a new one-round event - its id
     */
    private function startDraft(): string
    {
        $before = $this->competitionIds();
        $crawler = $this->browser->request('GET', '/en/new-event/basics');
        self::assertResponseIsSuccessful();

        $this->browser->submit($crawler->filter('form[name="event_wizard_basics_form"]')->form(), [
            'event_wizard_basics_form[name]' => 'Extras Cup',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Library, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
            'event_wizard_basics_form[startTime]' => '10:00',
        ]);

        $created = array_values(array_diff($this->competitionIds(), $before));
        self::assertCount(1, $created);

        return $created[0];
    }

    /**
     * Submits the step from the page as it is, changed by $values (bool = tick/untick a switch)
     *
     * @param array<string, string|bool> $values
     */
    private function submit(string $competitionId, array $values): Crawler
    {
        $crawler = $this->browser->request('GET', $this->url($competitionId));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="' . self::FORM . '"]')->form();

        foreach ($values as $field => $value) {
            $name = self::FORM . '[' . $field . ']';

            if (is_bool($value)) {
                $input = $form[$name];
                self::assertInstanceOf(ChoiceFormField::class, $input);
                $value ? $input->tick() : $input->untick();
            } else {
                $form[$name] = $value;
            }
        }

        return $this->browser->submit($form);
    }

    private function url(string $competitionId): string
    {
        return '/en/new-event/' . $competitionId . '/extras';
    }

    /**
     * @return array<string, mixed>
     */
    private function competitionRow(string $competitionId): array
    {
        $row = $this->database()->fetchAssociative('SELECT * FROM competition WHERE id = :id', ['id' => $competitionId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * Everything the step may write
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $competitionId): array
    {
        return [
            'competition' => $this->competitionRow($competitionId),
            'referees' => $this->referees($competitionId),
            'displays' => $this->database()->fetchOne('SELECT COUNT(*) FROM competition_display WHERE competition_id = :id', ['id' => $competitionId]),
        ];
    }

    private function referees(string $competitionId): int
    {
        $count = $this->database()->fetchOne('SELECT COUNT(*) FROM competition_referee WHERE competition_id = :id', ['id' => $competitionId]);

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<string>
     */
    private function competitionIds(): array
    {
        return array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $this->database()->fetchFirstColumn('SELECT id FROM competition'));
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
