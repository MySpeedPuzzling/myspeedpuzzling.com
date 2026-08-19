<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleAddControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="puzzle_add_form"]');
    }

    /**
     * @return array<string, array{null|string}>
     */
    public static function provideInvalidPuzzleValues(): array
    {
        return [
            // A disabled input on the client is excluded from the submit entirely
            'puzzle field missing from request' => [null],
            'puzzle field empty' => [''],
        ];
    }

    /**
     * Regression test: submitting without a puzzle must produce a validation
     * error, not a TypeError when constructing the AddPuzzleSolvingTime message.
     */
    #[DataProvider('provideInvalidPuzzleValues')]
    public function testSubmitWithoutPuzzleShowsValidationError(null|string $puzzle): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $formData = [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'firstAttempt' => '1',
            'collection' => '__system_collection__',
        ];

        if ($puzzle !== null) {
            $formData['puzzle'] = $puzzle;
        }

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $formData,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'This field is required!');
    }

    public function testCompetitionPickerOffersSeriesEditionsButNotUnapprovedEvents(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $crawler
            ->filter('#puzzle_add_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);

        self::assertStringContainsString(CompetitionSeriesFixture::EDITION_EJJ_68, $tomSelectOptions);
        self::assertStringContainsString(CompetitionSeriesFixture::SERIES_EJJ, $tomSelectOptions);
        self::assertStringContainsString(CompetitionFixture::COMPETITION_WJPC_2024, $tomSelectOptions);
        self::assertStringNotContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);
        self::assertStringNotContainsString(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $tomSelectOptions);
    }

    public function testSubmitWithNotSelectableCompetitionIsRejected(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', "This competition or event can't be selected");
        self::assertSame($timesBefore, $this->countPlayerTimes($database));
    }

    public function testSubmitLinksTheTimeToASeriesEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $database = self::getContainer()->get(Connection::class);
        $timesBefore = $this->countPlayerTimes($database);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $this->validSpeedPuzzlingSubmission($browser, CompetitionSeriesFixture::EDITION_EJJ_68),
        ]);

        $this->assertResponseRedirects();
        $location = $browser->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith('/en/time-added/', $location);

        self::assertSame($timesBefore + 1, $this->countPlayerTimes($database));

        $timeId = substr($location, strlen('/en/time-added/'));
        self::assertSame(
            CompetitionSeriesFixture::EDITION_EJJ_68,
            $database->fetchOne('SELECT competition_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function validSpeedPuzzlingSubmission(KernelBrowser $browser, string $competitionId): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'firstAttempt' => '1',
            'competition' => $competitionId,
            'collection' => '__system_collection__',
        ];
    }

    private function countPlayerTimes(Connection $database): int
    {
        /** @var int|string $count */
        $count = $database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR],
        );

        return (int) $count;
    }
}
