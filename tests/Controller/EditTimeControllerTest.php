<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class EditTimeControllerTest extends WebTestCase
{
    // TIME_06: PLAYER_REGULAR on PUZZLE_500_02, 36:40, no competition
    private const string TIME_ID = PuzzleSolvingTimeFixture::TIME_06;
    private const string EDIT_URL = '/en/edit-time/' . self::TIME_ID;

    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::EDIT_URL);

        $this->assertResponseRedirects();
    }

    public function testOtherPlayersTimeIsForbidden(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', self::EDIT_URL);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLinkedSeriesEditionIsOfferedAndSurvivesResave(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $this->linkTimeTo($database, CompetitionSeriesFixture::EDITION_EJJ_68);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $competitionInput = $crawler->filter('#edit_puzzle_solving_time_form_competition');
        self::assertSame(CompetitionSeriesFixture::EDITION_EJJ_68, $competitionInput->attr('value'));
        $tomSelectOptions = $competitionInput->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);
        self::assertStringContainsString(CompetitionSeriesFixture::EDITION_EJJ_68, $tomSelectOptions);

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionSeriesFixture::EDITION_EJJ_68),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(CompetitionSeriesFixture::EDITION_EJJ_68, $this->linkedCompetitionId($database));
    }

    public function testCurrentlyLinkedNotPubliclySelectableCompetitionIsKeptOnResave(): void
    {
        // The link predates an approval decision (or the event got rejected later) - the picker must
        // still offer it, otherwise the control renders empty and a plain re-save detaches the time
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $this->linkTimeTo($database, CompetitionFixture::COMPETITION_UNAPPROVED);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $crawler
            ->filter('#edit_puzzle_solving_time_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);
        self::assertStringContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);
        self::assertSame(1, substr_count($tomSelectOptions, CompetitionFixture::COMPETITION_UNAPPROVED));

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(CompetitionFixture::COMPETITION_UNAPPROVED, $this->linkedCompetitionId($database));
    }

    public function testSwitchingToANotSelectableCompetitionIsRejected(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $tomSelectOptions = $crawler
            ->filter('#edit_puzzle_solving_time_form_competition')
            ->attr('data-symfony--ux-autocomplete--autocomplete-tom-select-options-value');
        self::assertNotNull($tomSelectOptions);
        self::assertStringNotContainsString(CompetitionFixture::COMPETITION_UNAPPROVED, $tomSelectOptions);

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionFixture::COMPETITION_UNAPPROVED),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="edit_puzzle_solving_time_form"]', "This competition or event can't be selected");
        self::assertNull($this->linkedCompetitionId($database));
    }

    public function testSwitchingToASeriesEditionLinksTheTime(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);
        $this->assertResponseIsSuccessful();

        $browser->request('POST', self::EDIT_URL, [
            'edit_puzzle_solving_time_form' => $this->submission($crawler, CompetitionSeriesFixture::EDITION_OFFLINE_1),
        ]);

        $this->assertResponseRedirects();
        self::assertSame(CompetitionSeriesFixture::EDITION_OFFLINE_1, $this->linkedCompetitionId($database));
    }

    /**
     * @return array<string, string>
     */
    private function submission(Crawler $crawler, string $competitionId): array
    {
        $csrfToken = $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_02,
            'timeHours' => '0',
            'timeMinutes' => '36',
            'timeSeconds' => '40',
            'finishedAt' => '12.07.2026',
            'competition' => $competitionId,
        ];
    }

    private function linkTimeTo(Connection $database, string $competitionId): void
    {
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId WHERE id = :id',
            ['competitionId' => $competitionId, 'id' => self::TIME_ID],
        );
    }

    private function linkedCompetitionId(Connection $database): null|string
    {
        /** @var null|string|false $competitionId */
        $competitionId = $database->fetchOne(
            'SELECT competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => self::TIME_ID],
        );

        return $competitionId === false ? null : $competitionId;
    }
}
