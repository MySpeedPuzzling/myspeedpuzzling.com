<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The round format on the organiser tools (competition toolkit A2): the round state carries `puzzleFormat` only on a
 * `pick` round (R §1.8 - RoundResultsStateGoldenTest pins the untouched rounds), its hidden puzzles by label only for
 * the organiser and the referee alike (R §1.5), and the settings show only behind COMPETITION_TOOLKIT_MANAGE.
 */
final class RoundPuzzleFormatPagesTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use RoundPuzzleFormatSetup;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string FLAG = 'COMPETITION_TOOLKIT_FOR_ORGANISERS';

    private KernelBrowser $browser;
    private Connection $database;
    private string $fixtureRow;

    protected function setUp(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->fixtureRow = self::addRoundPuzzles($this->database);
        $this->database->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'player_id' => self::REFEREE,
            'added_by_id' => self::ORGANISER,
            'added_at' => '2026-10-01 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testATotalRoundHasNoPuzzleFormat(): void
    {
        $state = $this->state(self::ORGANISER, self::ROUND);

        self::assertArrayNotHasKey('puzzleFormat', $state['round']);
        foreach ($state['rounds'] as $round) {
            self::assertArrayNotHasKey('puzzleFormat', $round);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        yield 'organiser' => [self::ORGANISER];
        yield 'referee' => [self::REFEREE];
    }

    #[DataProvider('viewers')]
    public function testAPickRoundCarriesItsPuzzlesHiddenOnesByLabelOnly(string $viewer): void
    {
        self::makePick($this->database, 2);

        $state = $this->state($viewer, self::ROUND);
        $body = (string) $this->browser->getResponse()->getContent();

        self::assertIsArray($state['round']['puzzleFormat'] ?? null);
        /** @var array{mode: string, toSolve: int, puzzles: list<array<string, mixed>>} $format */
        $format = $state['round']['puzzleFormat'];
        self::assertSame('pick', $format['mode']);
        self::assertSame(2, $format['toSolve']);
        self::assertSame([self::SECRET, self::PUBLIC, $this->fixtureRow], array_column($format['puzzles'], 'roundPuzzleId'));
        self::assertSame([1, 2, 3], array_column($format['puzzles'], 'position'));
        self::assertSame(['A', 'B', 'C'], array_column($format['puzzles'], 'label'));

        // The secret puzzle: its label, place and piece count (organisers and referees) - never what it is
        self::assertSame([
            'roundPuzzleId' => self::SECRET,
            'position' => 1,
            'label' => 'A',
            'hidden' => true,
            'revealsAt' => null,
            'puzzleId' => null,
            'name' => null,
            'brand' => null,
            'imageUrl' => null,
            'piecesCount' => 1500,
        ], $format['puzzles'][0]);
        self::assertStringNotContainsString('"' . self::SECRET_NAME . '"', $body);
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_1500_01, $body);

        self::assertFalse($format['puzzles'][1]['hidden']);
        self::assertSame(self::PUBLIC_NAME, $format['puzzles'][1]['name']);
        self::assertSame(PuzzleFixture::PUZZLE_2000, $format['puzzles'][1]['puzzleId']);
        self::assertSame(2000, $format['puzzles'][1]['piecesCount']);

        // The same in the event's round list; the other rounds stay as they were
        foreach ($state['rounds'] as $round) {
            if ($round['id'] === self::ROUND) {
                self::assertEquals($format, $round['puzzleFormat']);
            } else {
                self::assertArrayNotHasKey('puzzleFormat', $round);
            }
        }
    }

    public function testAnotherRoundsStateCarriesThePickRoundInItsRoundList(): void
    {
        self::makePick($this->database, 3);

        $state = $this->state(self::ORGANISER, OfficialResultsFixture::ROUND_GROUP_A);

        self::assertArrayNotHasKey('puzzleFormat', $state['round']);
        $groupB = array_values(array_filter($state['rounds'], static fn (array $round): bool => $round['id'] === self::ROUND))[0];
        /** @var array{toSolve: int} $groupBFormat */
        $groupBFormat = $groupB['puzzleFormat'];
        self::assertSame(3, $groupBFormat['toSolve']);
    }

    public function testTheFormatFieldIsAToolkitSetting(): void
    {
        $url = '/en/edit-event-round/' . self::ROUND;

        // An organiser without the flag: the form as it always was
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-round-puzzle-format]');

        // An admin: the toolkit's field
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $this->browser->request('GET', $url);
        self::assertSelectorExists('[data-round-puzzle-format] input[name="competition_round_form[puzzleFormat]"][value="pick"]');

        // A round using `pick` keeps the field for every organiser, so it can be switched back
        self::makePick($this->database, 2);
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', $url);
        self::assertSelectorExists('[data-round-puzzle-format] input[name="competition_round_form[puzzlesToSolve]"][value="2"]');
    }

    public function testTheRoundFormSavesTheFormatAndRefusesMoreThanTheRoundHas(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/en/edit-event-round/' . self::ROUND);
        $form = $crawler->filter('form[name="competition_round_form"]')->form();

        $form->setValues(['competition_round_form[puzzleFormat]' => 'pick', 'competition_round_form[puzzlesToSolve]' => '4']);
        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('total', $this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        $form->setValues(['competition_round_form[puzzlesToSolve]' => '3']);
        $this->browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame(['pick', 3], [
            $this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => self::ROUND]),
            $this->database->fetchOne('SELECT puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND]),
        ]);
    }

    public function testTheHubCardSaysHowEachRoundIsPlayed(): void
    {
        self::makePick($this->database, 2);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-toolkit/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-toolkit-round="' . self::ROUND . '"] [data-toolkit-round-format]');
        self::assertSame('pick', $line->attr('data-toolkit-round-format'));
        self::assertStringContainsString('Pick 2 of 3', $line->text());
        self::assertStringContainsString('Revealed with “Reveal now”', $line->text());

        $groupA = $crawler->filter('[data-toolkit-round="' . OfficialResultsFixture::ROUND_GROUP_A . '"] [data-toolkit-round-format]');
        self::assertStringContainsString('One result for the round', $groupA->text());
        self::assertStringContainsString('No secret puzzles', $groupA->text());
    }

    public function testOrderAndLabelsAreForTheToolkitOnly(): void
    {
        $page = '/en/manage-round-puzzles/' . self::ROUND;
        $arrange = '/en/arrange-round-puzzles/' . self::ROUND;

        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $this->browser->request('GET', $page);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-arrange-round-puzzles]');
        self::assertSelectorNotExists('[data-round-puzzle-format]');

        $this->browser->request('POST', $arrange, ['label' => [self::PUBLIC => 'Blue box']]);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', $page);
        self::assertSelectorExists('[data-round-puzzle-format="total"]');
        $form = $crawler->filter('[data-arrange-round-puzzles] form')->form();
        $form->setValues([
            'position[' . self::PUBLIC . ']' => '1',
            'position[' . self::SECRET . ']' => '2',
            'label[' . self::PUBLIC . ']' => 'Blue box',
        ]);
        $this->browser->submit($form);

        self::assertResponseRedirects('/en/manage-round-puzzles/' . self::ROUND, 303);
        self::assertEquals(
            [self::PUBLIC => ['position' => 1, 'label' => 'Blue box'], self::SECRET => ['position' => 2, 'label' => null]],
            array_intersect_key($this->database->fetchAllAssociativeIndexed('SELECT id, position, label FROM competition_round_puzzle WHERE round_id = :id', ['id' => self::ROUND]), [self::PUBLIC => true, self::SECRET => true]),
        );
    }

    /**
     * @return array{round: array<string, mixed>, rounds: list<array<string, mixed>>}
     */
    private function state(string $viewer, string $roundId): array
    {
        TestingLogin::asPlayer($this->browser, $viewer);
        $this->browser->request('GET', '/en/official-results/rounds/' . $roundId);
        self::assertResponseIsSuccessful();

        /** @var array{round: array<string, mixed>, rounds: list<array<string, mixed>>} $state */
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }
}
