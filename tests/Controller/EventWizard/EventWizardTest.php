<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The event creation wizard's one-round path (W1, docs/features/competitions-management/toolkit/
 * W-creation-wizard-research.md §3.1-§3.4, §3.9): the flag, the redirect from "Add event", the entry screen, Basics
 * (the draft + its single round), Puzzle & format (one transaction - SetUpEventWizardRound) and Review.
 */
final class EventWizardTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'EVENT_CREATION_WIZARD';
    private const string ORGANISER = PlayerFixture::PLAYER_REGULAR;

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

    public function testWithTheFlagOffAnOrganiserGetsTheClassicFormAndNoWizard(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', '/en/add-event');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="competition_form"]');

        foreach (['/en/new-event', '/en/new-event/basics', '/en/new-event?kind=one_round'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        $competitionId = $this->startDraft();
        self::assertNull($competitionId, 'Nothing is created without the feature');
    }

    public function testTheClassicFormIsTheSameWithTheFlagOffAsWithClassic(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $crawler = $this->browser->request('GET', '/en/add-event');
        $flagOff = self::withoutTokens($crawler->filter('form[name="competition_form"]')->outerHtml());

        $this->overrideFeatureFlagEnv(self::FLAG, true);
        self::ensureKernelShutdown();
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $crawler = $this->browser->request('GET', '/en/add-event?classic=1');
        self::assertResponseIsSuccessful();

        // The form exactly as it was (the page around it differs only by the address: language links, feedback link)
        self::assertSame($flagOff, self::withoutTokens($crawler->filter('form[name="competition_form"]')->outerHtml()));
    }

    public function testAnAdminHasTheWizardWithTheFlagOff(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->browser->request('GET', '/en/add-event');
        self::assertResponseRedirects('/en/new-event', 302);

        $this->browser->request('GET', '/en/new-event');
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/en/add-event?classic=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="competition_form"]');
    }

    public function testAddEventLeadsToTheEntryKeepingTheOrganization(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/add-event?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseRedirects('/en/new-event?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND, 302);

        $crawler = $this->browser->request('GET', '/en/new-event?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseIsSuccessful();
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $crawler->filter('select[name="organization"] option[selected]')->attr('value'));
        self::assertStringContainsString('classic=1', (string) $crawler->filter('[data-event-wizard-classic]')->attr('href'));
        self::assertStringContainsString('organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND, (string) $crawler->filter('[data-event-wizard-classic]')->attr('href'));

        // "Copy one of your events" - the organiser's events, each linking its Duplicate form (W4's GET)
        self::assertGreaterThan(0, $crawler->filter('[data-event-wizard-copy] a[href*="/en/duplicate-event/"]')->count());
        self::assertLessThanOrEqual(5, $crawler->filter('[data-event-wizard-copy] li')->count());

        // A POST of the classic form is never redirected (refused here for missing fields: 422, not a redirect)
        $this->browser->request('POST', '/en/add-event', ['competition_form' => ['name' => '']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAPlayerWithoutEventsHasNothingToCopyNorAnOrganizationPicker(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', '/en/new-event');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="organization"]');
    }

    public function testEveryEntryAnswerGoesToItsPlace(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $organization = '&organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND;

        $this->browser->request('GET', '/en/new-event?kind=one_round' . $organization);
        self::assertResponseRedirects('/en/new-event/basics?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        $this->browser->request('GET', '/en/new-event?kind=several_rounds' . $organization);
        self::assertResponseRedirects('/en/add-event?classic=1&organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        // The series path (W3)
        $this->browser->request('GET', '/en/new-event?kind=series' . $organization);
        self::assertResponseRedirects('/en/new-event/series/basics?organization=' . OrganizationFixture::ORGANIZATION_RIVERBEND);

        $this->browser->request('GET', '/en/new-event?kind=other');
        self::assertResponseRedirects('/en/add-event?classic=1');

        // "Recurring" still comes ticked on the classic form's ?recurring=1
        $crawler = $this->browser->request('GET', '/en/add-event?classic=1&recurring=1');
        self::assertSame('checked', $crawler->filter('input[name="competition_form[isRecurring]"]')->attr('checked'));
    }

    public function testBasicsCreatesTheDraftWithItsSingleRound(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $crawler = $this->browser->request('GET', '/en/new-event/basics');
        self::assertResponseIsSuccessful();
        // The player's country (cz) is pre-filled
        self::assertStringContainsString('Czech', $crawler->filter('select[name="event_wizard_basics_form[locationCountryCode]"] option[selected]')->text());

        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);
        // A draft e-mails no admin - they hear of it when it is published
        self::assertQueuedEmailCount(0);
        self::assertEmailCount(0);

        $competition = $this->database()->fetchAssociative('SELECT * FROM competition WHERE id = :id', ['id' => $competitionId]);
        self::assertIsArray($competition);
        self::assertTrue($competition['is_draft']);
        self::assertSame('Wizard Cup', $competition['name']);
        self::assertStringStartsWith('2027-06-15', self::text($competition['date_from']));
        self::assertStringStartsWith('2027-06-15', self::text($competition['date_to']));

        $rounds = $this->database()->fetchAllAssociative('SELECT * FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);
        self::assertCount(1, $rounds);
        self::assertSame('Solo', $rounds[0]['name']);
        self::assertSame(90, $rounds[0]['minutes_limit']);
        self::assertSame('solo', $rounds[0]['category']);
        self::assertSame('Europe/Prague', $rounds[0]['timezone']);
        // 10:00 in Prague in June = 08:00 UTC
        self::assertStringStartsWith('2027-06-15 08:00', self::text($rounds[0]['starts_at']));
        self::assertSame(10, $rounds[0]['reveal_delay_minutes']);
        self::assertNull($rounds[0]['badge_background_color']);
    }

    public function testASecondSubmitOfTheSamePageCreatesNoSecondDraft(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $before = $this->dbInt('SELECT COUNT(*) FROM competition');

        $crawler = $this->browser->request('GET', '/en/new-event/basics');
        $draftId = (string) $crawler->filter('input[name="event_wizard_basics_form[draftId]"]')->attr('value');
        self::assertTrue(Uuid::isValid($draftId));

        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        $values = [
            'event_wizard_basics_form[name]' => 'Double Click Cup',
            'event_wizard_basics_form[isOnline]' => '0',
            'event_wizard_basics_form[location]' => 'Library, Brno',
            'event_wizard_basics_form[dateFrom]' => '15.06.2027',
            'event_wizard_basics_form[startTime]' => '',
        ];

        // A refused submit keeps the page's id
        $crawler = $this->browser->submit($form, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($draftId, $crawler->filter('input[name="event_wizard_basics_form[draftId]"]')->attr('value'));

        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        $values['event_wizard_basics_form[startTime]'] = '10:00';
        $this->browser->submit($form, $values);
        self::assertResponseRedirects('/en/new-event/' . $draftId . '/puzzle', 303);

        // The same page sent again: the draft of the first submit, nothing new
        $this->browser->submit($form, $values);
        self::assertResponseRedirects('/en/new-event/' . $draftId . '/puzzle', 303);

        self::assertSame($before + 1, $this->dbInt('SELECT COUNT(*) FROM competition'));
        self::assertSame(1, $this->dbInt('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', ['id' => $draftId]));

        // Another player sending the same id gets a draft of their own
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        $this->browser->submit($form, $values);
        self::assertResponseStatusCodeSame(303);
        self::assertStringNotContainsString($draftId, (string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame($before + 2, $this->dbInt('SELECT COUNT(*) FROM competition'));
    }

    public function testADraftWithoutARoundGetsOneOnlyOnce(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);
        $this->database()->executeStatement('DELETE FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/basics');
        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        $form['event_wizard_basics_form[startTime]'] = '10:00';

        // The first save gives it the round...
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);
        // ...the same page sent again (it showed no round) adds none
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);

        self::assertSame(1, $this->dbInt('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]));
    }

    public function testSavingOtherFieldsLeavesARoundMovedOnTheRoundsPageWhereItIs(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        // Moved to the next day on the rounds page
        $this->database()->executeStatement("UPDATE competition_round SET starts_at = '2027-06-16 13:00:00' WHERE competition_id = :id", ['id' => $competitionId]);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/basics');
        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        $form['event_wizard_basics_form[name]'] = 'Wizard Cup Renamed';
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);

        self::assertSame('Wizard Cup Renamed', $this->database()->fetchOne('SELECT name FROM competition WHERE id = :id', ['id' => $competitionId]));
        self::assertStringStartsWith('2027-06-16 13:00', $this->dbString('SELECT starts_at FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]));
    }

    public function testBasicsRefusesAMissingStartTimeWith422(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $before = $this->dbInt('SELECT COUNT(*) FROM competition');
        $this->startDraft(['startTime' => '', 'location' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->dbInt('SELECT COUNT(*) FROM competition'));
    }

    public function testComingBackToBasicsMovesTheRoundsStart(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/basics');
        self::assertResponseIsSuccessful();
        self::assertSame('10:00', $crawler->filter('input[name="event_wizard_basics_form[startTime]"]')->attr('value'));

        $form = $crawler->filter('form[name="event_wizard_basics_form"]')->form();
        $form['event_wizard_basics_form[startTime]'] = '13:30';
        $form['event_wizard_basics_form[dateFrom]'] = '16.06.2027';
        $multiDay = $form['event_wizard_basics_form[multiDay]'];
        self::assertInstanceOf(ChoiceFormField::class, $multiDay);
        $multiDay->tick();
        $form['event_wizard_basics_form[dateTo]'] = '17.06.2027';
        $this->browser->submit($form);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);

        $round = $this->database()->fetchAssociative('SELECT * FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);
        self::assertIsArray($round);
        self::assertStringStartsWith('2027-06-16 11:30', self::text($round['starts_at']));
        self::assertSame('Solo', $round['name']);
        self::assertStringStartsWith('2027-06-17', $this->dbString('SELECT date_to FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    public function testPuzzleStepAddsASecretCatalogueQuizzleRevealedAtTheStopwatchStartForTheToolkit(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="event_wizard_puzzle_form[revealMode]"][value="stopwatch"]');
        // No format question before the round has a puzzle
        self::assertSelectorNotExists('[data-event-wizard-format]');

        $this->submitPuzzle($crawler, [
            'minutesLimit' => '60',
            'hideUntilRoundStarts' => true,
            'revealMode' => 'stopwatch',
        ]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $roundPuzzle = $this->database()->fetchAssociative(
            'SELECT crp.* FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        );
        self::assertIsArray($roundPuzzle);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $roundPuzzle['puzzle_id']);
        self::assertTrue($roundPuzzle['hide_until_round_starts']);
        self::assertSame('stopwatch', $roundPuzzle['reveal_mode']);
        self::assertNull($roundPuzzle['reveal_at']);
        self::assertSame(60, $this->dbInt('SELECT minutes_limit FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]));

        // Review names every inferred value, the secret's reveal included
        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-event-wizard-review-puzzles]', 'revealed when you start the stopwatch');
        self::assertSelectorTextContains('[data-event-wizard-review-round]', 'Solo');
        self::assertSelectorTextContains('[data-event-wizard-review-round]', 'Czechia Time');
    }

    public function testEveryOrganiserIsOfferedTheStopwatchReveal(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="event_wizard_puzzle_form[revealMode]"][value="stopwatch"]');
        self::assertSelectorExists('input[name="event_wizard_puzzle_form[revealMode]"][value="manual"]');

        // A secret puzzle with the automatic reveal - the round's own moment
        $this->submitPuzzle($crawler, ['hideUntilRoundStarts' => true]);
        self::assertResponseRedirects();
        self::assertSame('automatic', $this->database()->fetchOne(
            'SELECT crp.reveal_mode FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        ));
    }

    public function testAMovedAutomaticRevealRefusesTheSecretPuzzle(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        // The round moved after the page was loaded (another tab)
        $this->database()->executeStatement("UPDATE competition_round SET starts_at = starts_at + interval '1 hour' WHERE competition_id = :id", ['id' => $competitionId]);

        $this->submitPuzzle($crawler, ['hideUntilRoundStarts' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->roundPuzzlesCount($competitionId));
    }

    public function testDecideLaterSavesTheRoundWithoutAPuzzleAndResumesThere(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->submitPuzzle($crawler, ['brand' => '', 'puzzle' => '', 'category' => 'duo']);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);

        $round = $this->database()->fetchAssociative('SELECT * FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);
        self::assertIsArray($round);
        self::assertSame('duo', $round['category']);
        // The generated name follows the category
        self::assertSame('Pairs', $round['name']);
        self::assertSame(0, $this->roundPuzzlesCount($competitionId));

        // Resuming goes back to the puzzle, the step not done yet
        $this->browser->request('GET', '/en/new-event/' . $competitionId);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle');

        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorTextContains('[data-event-wizard-review-puzzles]', 'Decided later');
    }

    public function testARefusedPuzzleLeavesNoHalfWrittenRound(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->submitPuzzle($crawler, ['another' => true]);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/puzzle', 303);

        // The same puzzle again in the same category: refused with a form error - and the time limit typed with it is
        // not saved either (one transaction)
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        self::assertSelectorExists('[data-event-wizard-round-puzzles]');
        $this->submitPuzzle($crawler, ['minutesLimit' => '45']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'already');

        self::assertSame(1, $this->roundPuzzlesCount($competitionId));
        self::assertSame(90, $this->dbInt('SELECT minutes_limit FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]));
    }

    public function testPickKOfNWritesThePuzzlesFirstAndRefusesAWrongKAtomically(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->submitPuzzle($crawler, ['another' => true]);

        // K = 3 of 2 puzzles: refused, the second puzzle is not added
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        self::assertSelectorExists('[data-event-wizard-format]');
        $this->submitPuzzle($crawler, ['puzzle' => PuzzleFixture::PUZZLE_500_02, 'puzzleFormat' => 'pick', 'puzzlesToSolve' => '3']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->roundPuzzlesCount($competitionId));

        // K = 1 of 2: both written, then the format
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->submitPuzzle($crawler, ['puzzle' => PuzzleFixture::PUZZLE_500_02, 'puzzleFormat' => 'pick', 'puzzlesToSolve' => '1']);
        self::assertResponseRedirects('/en/new-event/' . $competitionId . '/review', 303);
        self::assertSame(2, $this->roundPuzzlesCount($competitionId));

        $round = $this->database()->fetchAssociative('SELECT puzzle_format, puzzles_to_solve FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);
        self::assertSame(['puzzle_format' => 'pick', 'puzzles_to_solve' => 1], $round);
    }

    public function testEveryOrganiserIsOfferedThePuzzleFormat(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->submitPuzzle($crawler, ['another' => true]);
        $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        self::assertSelectorExists('[data-event-wizard-format]');
    }

    public function testAnotherPlayerCannotOpenTheDraftsSteps(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_PRIVATE);
        foreach (['basics', 'puzzle', 'review'] as $step) {
            $this->browser->request('GET', '/en/new-event/' . $competitionId . '/' . $step);
            self::assertResponseStatusCodeSame(403, $step);
        }

        $this->browser->request('POST', '/en/new-event/' . $competitionId . '/review', ['action' => 'publish']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    public function testWithTheFlagTurnedOffTheDraftsStepsAre404(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $this->overrideFeatureFlagEnv(self::FLAG, false);
        self::ensureKernelShutdown();
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        foreach (['basics', 'puzzle', 'review'] as $step) {
            $this->browser->request('GET', '/en/new-event/' . $competitionId . '/' . $step);
            self::assertResponseStatusCodeSame(404, $step);
        }
    }

    public function testReviewPublishesAndGoesToTheEditPage(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $competitionId = $this->startDraft(organizationId: OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertNotNull($competitionId);

        // Under the approved organization: zone of its country (us), public at once
        self::assertSame('America/New_York', $this->database()->fetchOne('SELECT timezone FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]));
        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorTextContains('[data-event-wizard-approval]', 'public as soon as you publish');

        $this->browser->submit($crawler->selectButton('Publish')->form());
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);
        self::assertFalse($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));

        // A published event's wizard pages lead to its edit page
        foreach (['basics', 'puzzle', 'review'] as $step) {
            $this->browser->request('GET', '/en/new-event/' . $competitionId . '/' . $step);
            self::assertResponseRedirects('/en/edit-event/' . $competitionId, 302, $step);
        }
    }

    public function testPublishingAPendingEventTellsTheAdmins(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        $this->browser->submit($crawler->selectButton('Publish')->form());
        self::assertResponseRedirects('/en/edit-event/' . $competitionId, 303);
        self::assertQueuedEmailCount(1);
        self::assertFalse($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    public function testKeepAsDraftAndFinishLaterGoToTheDraftsPage(): void
    {
        $this->browser = $this->newClient();
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $competitionId = $this->startDraft();
        self::assertNotNull($competitionId);

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/review');
        self::assertSelectorTextContains('[data-event-wizard-approval]', 'An admin checks');
        $this->browser->submit($crawler->selectButton('Keep as draft')->form());
        self::assertResponseStatusCodeSame(303);
        $draftPage = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/en/events/', $draftPage);
        self::assertTrue($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));

        $crawler = $this->browser->request('GET', '/en/new-event/' . $competitionId . '/puzzle');
        $this->browser->submit($crawler->selectButton('Finish later')->form());
        self::assertResponseRedirects($draftPage, 303);

        // An expired page: refused, nothing published
        $this->browser->request('POST', '/en/new-event/' . $competitionId . '/review', ['action' => 'publish', '_token' => 'nope']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->database()->fetchOne('SELECT is_draft FROM competition WHERE id = :id', ['id' => $competitionId]));
    }

    /**
     * Basics of a new one-round event, submitted - the new event's id, null when nothing was created.
     *
     * @param array<string, string> $overrides
     */
    private function startDraft(array $overrides = [], null|string $organizationId = null): null|string
    {
        $before = $this->competitionIds();

        $crawler = $this->browser->request('GET', '/en/new-event/basics' . ($organizationId !== null ? '?organization=' . $organizationId : ''));

        if ($this->browser->getResponse()->getStatusCode() !== 200) {
            return null;
        }

        $values = [];
        foreach (
            [
            'name' => 'Wizard Cup',
            'isOnline' => '0',
            'location' => 'Library, Brno',
            'dateFrom' => '15.06.2027',
            'startTime' => '10:00',
            ...$overrides,
            ] as $field => $value
        ) {
            $values['event_wizard_basics_form[' . $field . ']'] = $value;
        }

        $this->browser->submit($crawler->filter('form[name="event_wizard_basics_form"]')->form(), $values);

        $created = array_values(array_diff($this->competitionIds(), $before));

        return $created[0] ?? null;
    }

    /**
     * @param array<string, string|bool> $overrides `another` => true presses "Save & add another puzzle"
     */
    private function submitPuzzle(Crawler $crawler, array $overrides = []): void
    {
        $another = ($overrides['another'] ?? false) === true;
        unset($overrides['another']);

        $form = $another
            ? $crawler->selectButton('Save & add another puzzle')->form()
            : $crawler->filter('form[name="event_wizard_puzzle_form"]')->form();

        foreach (
            [
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            ...$overrides,
            ] as $field => $value
        ) {
            $name = 'event_wizard_puzzle_form[' . $field . ']';

            if (is_bool($value)) {
                $field = $form[$name];
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();
            } else {
                $form[$name] = $value;
            }
        }

        $this->browser->submit($form);
    }

    private function roundPuzzlesCount(string $competitionId): int
    {
        return $this->dbInt(
            'SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        );
    }

    /**
     * A fresh kernel - after a flag changed (OverridesFeatureFlagEnv is read when the kernel boots)
     */
    private function newClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return self::createClient();
    }

    /**
     * @return list<string>
     */
    private function competitionIds(): array
    {
        return array_map(self::text(...), $this->database()->fetchFirstColumn('SELECT id FROM competition'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dbInt(string $query, array $parameters = []): int
    {
        $value = $this->database()->fetchOne($query, $parameters);

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dbString(string $query, array $parameters = []): string
    {
        return self::text($this->database()->fetchOne($query, $parameters));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function withoutTokens(string $html): string
    {
        return (string) preg_replace(['/value="[^"]{20,}"/', '/nonce="[^"]*"/', '/csrf[^"]*"[^"]*"/i'], '', $html);
    }
}
