<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Imagick;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Repository\SolvingTimeCustomValuesRepository;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The player's own fields in the add-result form (docs/features/custom-fields.md "The add form"). The form is the
 * most important one of the app, so these tests are about what the fields must never do: change the form of a
 * player without fields, refuse a result, or lose one.
 */
final class AddFormMyFieldsTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string PUZZLE = PuzzleFixture::PUZZLE_500_01;

    /** Nothing of these may be in the form of a player without fields */
    private const array MARKUP = ['my-fields-block', 'add-form-my-fields', 'my_fields[', 'data-custom-field', 'data-toggle-target="myFields"', 'My fields'];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheFormOfAPlayerWithoutFieldsIsTheFormAsItAlwaysWas(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $pages = [];

        foreach (['/en/puzzle-add', '/en/puzzle-add/' . self::PUZZLE, '/en/puzzle-add?mode=relax', '/en/puzzle-add?mode=collection'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful();
            $pages[$url] = (string) $browser->getResponse()->getContent();

            foreach (self::MARKUP as $markup) {
                self::assertStringNotContainsString($markup, $pages[$url], $url . ': ' . $markup);
            }
        }

        // With the feature switched off entirely the page is the same, byte for byte (the ids and tokens of the
        // request aside) - so for a player without fields the feature adds nothing to it
        $this->overrideFeatureFlagEnv('CUSTOM_FIELDS_IN_ADD_FORM', false);

        foreach ($pages as $url => $withTheFeature) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful();

            self::assertSame(self::withoutRequestIds((string) $browser->getResponse()->getContent()), self::withoutRequestIds($withTheFeature), $url);
        }
    }

    public function testAPlayerWithoutFieldsPaysOneStatementOncePerSessionAndNoneAfter(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle-add');
        self::assertCount(1, $this->fieldStatements($browser), 'The first form of the session asks once');

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle-add');
        self::assertSame([], $this->fieldStatements($browser), '...and the answer is remembered');

        // A save without any field in the request asks nothing either
        $form = $this->formValues($browser);
        $this->startCountingQueries($browser);
        $this->post($browser, $form, Uuid::uuid7()->toString());
        self::assertResponseRedirects();
        self::assertSame([], $this->fieldStatements($browser));
    }

    public function testAPlayerWithFieldsGetsTheBlockAfterTheEventSectionClosedUntilTapped(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $place = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        // Not for results, archived, somebody else's: none of them is in the form
        $scenario->field(self::PLAYER, 'Shelf', forResults: false, forCollectionItems: true);
        $scenario->field(self::PARTNER, 'Notes of the partner');

        $crawler = $browser->request('GET', '/en/puzzle-add');

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-testid="add-form-my-fields"]');
        self::assertCount(1, $block);
        // Inside the form, right after the event section and before the comment
        self::assertCount(1, $crawler->filter('form[name="puzzle_add_form"] [data-testid="add-form-my-fields"]'));
        self::assertNotNull($block->previousAll()->filter('[data-puzzle-add-form-target="competitionSection"]')->first()->attr('data-puzzle-add-form-target'));
        self::assertSame('commonSection', $block->nextAll()->first()->attr('data-puzzle-add-form-target'));
        // Closed: the button shows, the inputs wait behind it
        self::assertStringNotContainsString('hidden', (string) $block->filter('button[data-action="click->toggle#toggle"]')->attr('class'));
        self::assertStringContainsString('hidden', (string) $block->filter('[data-toggle-target="myFields"]')->attr('class'));
        self::assertCount(1, $block->filter('textarea[name="my_fields[' . $notes . ']"]'));
        self::assertCount(1, $block->filter('select[name="my_fields[' . $place . ']"]'));
        self::assertCount(2, $block->filter('[data-custom-field]'));
        self::assertStringNotContainsString('Notes of the partner', (string) $browser->getResponse()->getContent());
        // Plain inputs beside the Symfony form - never its children
        self::assertCount(0, $crawler->filter('[name^="puzzle_add_form[my"]'));
        self::assertCount(0, $crawler->filter('[name^="puzzle_add_form[custom"]'));
    }

    public function testTheValuesAreSavedWithANewResult(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $unused = $scenario->field(self::PLAYER, 'Unused');
        $timeId = Uuid::uuid7()->toString();

        $this->post($browser, $this->formValues($browser), $timeId, [$notes => ' edges last ', $breaks => '2', $unused => '']);

        // Exactly the answer of a save without fields: the recap of the result
        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame([$notes => 'edges last', $breaks => 2], $scenario->storedValues($timeId, self::PLAYER));
        $recap = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(0, $recap->filter('.alert-warning'), 'Nothing to tell when everything was saved');
    }

    public function testTheValuesAreSavedWithAResultWithoutATime(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();

        $this->post($browser, [...$this->formValues($browser), 'mode' => 'relax', 'timeHours' => '0', 'timeMinutes' => '0', 'timeSeconds' => '0'], $timeId, [$notes => 'a slow evening']);

        self::assertResponseRedirects('/en/tracking-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame([$notes => 'a slow evening'], $scenario->storedValues($timeId, self::PLAYER));
    }

    public function testOnAPairsResultTheValuesAreTheSaversAlone(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $scenario->field(self::PARTNER, 'Notes of the partner');
        $timeId = Uuid::uuid7()->toString();

        $this->post($browser, $this->formValues($browser), $timeId, [$notes => 'we argued about the sky'], extra: ['group_players' => ['#player3']]);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame('duo', self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_type FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame([$notes => 'we argued about the sky'], $scenario->storedValues($timeId, self::PLAYER));
        self::assertNull($scenario->storedValues($timeId, self::PARTNER));
        self::assertSame(1, $scenario->valueRows($timeId));
    }

    public function testTheSameFormSentAgainSavesOneResultWithItsValues(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();
        $form = $this->formValues($browser);

        $this->post($browser, $form, $timeId, [$notes => 'edges last']);
        self::assertResponseRedirects('/en/time-added/' . $timeId);

        // The answer got lost, the form arrives again
        $this->post($browser, $form, $timeId, [$notes => 'edges last']);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame(1, $scenario->valueRows($timeId));
        self::assertSame([$notes => 'edges last'], $scenario->storedValues($timeId, self::PLAYER));
    }

    public function testAResendStillStoresTheValuesWhenTheFirstSaveLostThem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();
        $form = $this->formValues($browser);

        // The result got saved without its fields (the step after the save failed, the connection dropped in between)
        $this->post($browser, $form, $timeId);
        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertNull($scenario->storedValues($timeId, self::PLAYER));

        $this->post($browser, $form, $timeId, [$notes => 'edges last']);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame([$notes => 'edges last'], $scenario->storedValues($timeId, self::PLAYER));
    }

    public function testAValueThatCanNotBeStoredNeverRefusesTheResult(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks & stops', CustomFieldType::Number);
        $day = $scenario->field(self::PLAYER, 'Started on', CustomFieldType::Date);
        $place = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $long = $scenario->field(self::PLAYER, 'Long');
        $timeId = Uuid::uuid7()->toString();

        $this->post($browser, $this->formValues($browser), $timeId, [
            $notes => 'edges last',
            $breaks => 'a few',
            $day => 'sometime in spring',
            $place => 'ceiling',
            $long => str_repeat('a', 501),
        ]);

        // No 422, ever: the result is saved and answered like any other
        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        // What could be stored is stored, the rest is left out...
        self::assertSame([$notes => 'edges last'], $scenario->storedValues($timeId, self::PLAYER));
        // ...and the player is told which fields, on the page they land on
        $recap = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        $warning = $recap->filter('.alert-warning')->text();
        self::assertStringContainsString('Your result is saved.', $warning);
        self::assertStringContainsString('Breaks & stops, Started on, Place, Long', $warning);
    }

    public function testAnythingSentInPlaceOfTheFieldsNeverRefusesTheResult(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $partnersField = $scenario->field(self::PARTNER, 'Notes of the partner');

        $shapes = [
            'a text instead of the fields' => 'not-an-array',
            'a list inside a field' => [$notes => ['a', 'b']],
            'unknown ids' => ['not-an-id' => 'x', Uuid::uuid7()->toString() => 'y', 7 => 'z'],
            'somebody else\'s field' => [$partnersField => 'x'],
        ];

        // Another time for each: the same time of the same day twice would be asked about - not this test's business
        $minutes = 10;

        foreach ($shapes as $shape => $myFields) {
            $timeId = Uuid::uuid7()->toString();
            $form = [...$this->formValues($browser), 'timeMinutes' => (string) $minutes++];

            $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $form, 'time_id' => $timeId, 'my_fields' => $myFields]);

            self::assertResponseRedirects('/en/time-added/' . $timeId, message: $shape);
            self::assertSame(1, $this->results($timeId), $shape);
            self::assertSame(0, $scenario->valueRows($timeId), $shape);
        }

        self::assertNull($scenario->storedValues($timeId, self::PARTNER));
    }

    public function testASaveRefusedForAnotherReasonKeepsWhatWasTypedIntoTheFields(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $breaks = $scenario->field(self::PLAYER, 'Breaks', CustomFieldType::Number);
        $timeId = Uuid::uuid7()->toString();

        // A competition that can not be picked refuses the save - nothing to do with the fields
        $crawler = $this->post($browser, [...$this->formValues($browser), 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED], $timeId, [$notes => 'edges last', $breaks => 'a few']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->results($timeId));
        self::assertSame(0, $scenario->valueRows());
        $block = $crawler->filter('[data-testid="add-form-my-fields"]');
        // Open, with everything typed - also what could not be stored, to be corrected or not
        self::assertStringNotContainsString('hidden', (string) $block->filter('[data-toggle-target="myFields"]')->attr('class'));
        self::assertSame('edges last', $block->filter('textarea[name="my_fields[' . $notes . ']"]')->text());
        self::assertSame('a few', $block->filter('input[name="my_fields[' . $breaks . ']"]')->attr('value'));
        // A field never shows an error here: it can not be the reason
        self::assertCount(0, $block->filter('.invalid-feedback, .is-invalid'));
    }

    public function testTheResultIsSavedAndAnsweredAsAlwaysWhenSavingTheValuesFails(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();
        $form = $this->formValues($browser);

        // The writer of the values breaks: its repository answers every call with an exception
        $broken = $this->createStub(EntityManagerInterface::class);
        $broken->method('getRepository')->willThrowException(new \RuntimeException('The values table is gone.'));
        self::getContainer()->set(SolvingTimeCustomValuesRepository::class, new SolvingTimeCustomValuesRepository($broken));
        $logs = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($logs);

        $this->post($browser, $form, $timeId, [$notes => 'edges last']);

        // The normal answer of a saved result
        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame(0, $scenario->valueRows($timeId));

        $warnings = array_values(array_filter(
            $logs->getRecords(),
            static fn (LogRecord $record): bool => $record->level === Level::Warning && $record->message === 'Custom fields of a saved result could not be saved',
        ));
        self::assertCount(1, $warnings);
        self::assertSame($timeId, $warnings[0]->context['solvingTimeId']);
        self::assertInstanceOf(\Throwable::class, $warnings[0]->context['exception']);

        // The player lands on the recap as always, told where to fill the fields in
        $recap = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Your own fields could not be saved - you can fill them in on the result.', $recap->filter('.alert-warning')->text());
    }

    public function testTheFormOpensAndSavesWhenTheFieldsCanNotBeLoaded(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();

        $broken = $this->createStub(Connection::class);
        $broken->method('executeQuery')->willThrowException(new \RuntimeException('The fields table is gone.'));
        self::getContainer()->set(GetCustomFields::class, new GetCustomFields($broken));
        $logs = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($logs);

        // The form is the form of a player without fields
        $crawler = $browser->request('GET', '/en/puzzle-add');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach (self::MARKUP as $markup) {
            self::assertStringNotContainsString($markup, $content, $markup);
        }

        self::assertTrue($logs->hasWarningThatContains('Custom fields could not be loaded for the add form'));

        // ...and it saves - also with fields in the request (a form opened before the trouble began)
        $token = (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        $this->post($browser, [...$this->formValues($browser, fetch: false), '_token' => $token], $timeId, [$notes => 'edges last']);

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame(0, $scenario->valueRows($timeId));
        self::assertTrue($logs->hasWarningThatContains('Custom fields of a saved result could not be saved'));
    }

    public function testAResultWithAPhotoAndFieldsIsSavedWithBoth(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();

        $browser->request(
            'POST',
            '/en/puzzle-add',
            ['puzzle_add_form' => $this->formValues($browser), 'time_id' => $timeId, 'my_fields' => [$notes => 'edges last']],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->photo()]],
        );

        self::assertResponseRedirects('/en/time-added/' . $timeId);
        $photo = self::getContainer()->get(Connection::class)->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($photo);
        self::assertStringContainsString($timeId, $photo);
        self::assertSame([$notes => 'edges last'], $scenario->storedValues($timeId, self::PLAYER));
    }

    public function testARefusedSaveWithAPhotoKeepsThePhotoAndTheFields(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');

        $crawler = $browser->request(
            'POST',
            '/en/puzzle-add',
            [
                'puzzle_add_form' => [...$this->formValues($browser), 'competition' => CompetitionFixture::COMPETITION_UNAPPROVED],
                'time_id' => Uuid::uuid7()->toString(),
                'my_fields' => [$notes => 'edges last'],
            ],
            ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->photo()]],
        );

        self::assertResponseStatusCodeSame(422);
        // The photo flow is untouched: the kept photo's token is in the form, next to the typed field
        self::assertNotSame('', (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value'));
        self::assertSame('edges last', $crawler->filter('textarea[name="my_fields[' . $notes . ']"]')->text());
    }

    public function testInCollectionModeTheFieldsAreNotForTheSave(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');

        $this->post($browser, [...$this->formValues($browser), 'mode' => 'collection', 'puzzle' => PuzzleFixture::PUZZLE_1000_05], Uuid::uuid7()->toString(), [$notes => 'left in the hidden block']);

        self::assertResponseRedirects();
        self::assertSame(0, $scenario->valueRows());
    }

    public function testSwitchedOffTheFormNeitherShowsNorSavesTheFieldsAndTheRestKeepsWorking(): void
    {
        $this->overrideFeatureFlagEnv('CUSTOM_FIELDS_IN_ADD_FORM', false);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::PLAYER, 'Notes');
        $timeId = Uuid::uuid7()->toString();

        $browser->request('GET', '/en/puzzle-add');
        self::assertResponseIsSuccessful();

        foreach (self::MARKUP as $markup) {
            self::assertStringNotContainsString($markup, (string) $browser->getResponse()->getContent(), $markup);
        }

        // A form opened before the switch was flipped still saves its result - without the fields
        $this->post($browser, $this->formValues($browser), $timeId, [$notes => 'edges last']);
        self::assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->results($timeId));
        self::assertSame(0, $scenario->valueRows($timeId));

        // The custom fields page and the form on the result are not the add form's business
        $browser->request('GET', '/en/custom-fields');
        self::assertResponseIsSuccessful();
        $page = $browser->request('GET', '/en/custom-fields/result/' . $timeId);
        self::assertResponseIsSuccessful();
        $browser->request('POST', '/en/custom-fields/result/' . $timeId, [
            '_token' => $page->filter('[data-testid="custom-values-form"] input[name="_token"]')->attr('value'),
            'my_fields' => [$notes => 'filled in on the result'],
        ]);
        self::assertResponseRedirects('/en/result/' . $timeId);
        self::assertSame([$notes => 'filled in on the result'], $scenario->storedValues($timeId, self::PLAYER));
    }

    /**
     * The values of a valid speed-puzzling submission, with the form's token.
     *
     * @return array<string, string>
     */
    private function formValues(KernelBrowser $browser, bool $fetch = true): array
    {
        $token = '';

        if ($fetch) {
            $crawler = $browser->request('GET', '/en/puzzle-add');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        }

        return [
            '_token' => $token,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => self::PUZZLE,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'competition' => '',
            'collection' => '__system_collection__',
        ];
    }

    /**
     * @param array<string, string> $form
     * @param null|array<string, string> $myFields null = the request has no fields at all
     * @param array<string, mixed> $extra
     */
    private function post(KernelBrowser $browser, array $form, string $timeId, null|array $myFields = null, array $extra = []): Crawler
    {
        $parameters = ['puzzle_add_form' => $form, 'time_id' => $timeId, ...$extra];

        if ($myFields !== null) {
            $parameters['my_fields'] = $myFields;
        }

        return $browser->request('POST', '/en/puzzle-add', $parameters);
    }

    private function results(string $timeId): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @return list<string> the statements of the profiled request that read the player's fields
     */
    private function fieldStatements(KernelBrowser $browser): array
    {
        return array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'player_custom_field'),
        ));
    }

    /**
     * The page without what differs from request to request: the form's ids and every token.
     */
    private static function withoutRequestIds(string $html): string
    {
        $html = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid7>', $html);
        // The moment the page was rendered (the stale-document check of base.html.twig)
        $html = (string) preg_replace('/(<meta name="msp-rendered-at" content=")\d+"/', '$1<now>"', $html);

        return (string) preg_replace('/(name="[^"]*_token[^"]*"[^>]*value=")[^"]*"/', '$1<token>"', $html);
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'my-fields-photo-');
        assert(is_string($path));

        $image = new Imagick();
        $image->newImage(64, 48, 'orange');
        $image->setImageFormat('jpeg');
        $image->writeImage($path);
        $image->destroy();

        return new UploadedFile($path, 'finished.jpg', 'image/jpeg', null, true);
    }
}
