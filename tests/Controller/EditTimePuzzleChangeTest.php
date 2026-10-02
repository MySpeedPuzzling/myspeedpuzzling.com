<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The edit form can move a result to another puzzle (docs/features/duplicate-results.md, Layer 4): the tracker
 * gets "Change puzzle", the other members of a pair/team do not, and every check looks at the picked puzzle.
 */
final class EditTimePuzzleChangeTest extends WebTestCase
{
    // TIME_06: PLAYER_REGULAR, solo on PUZZLE_500_02, 36:40
    private const string EDIT_URL = '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_06;

    public function testTheTrackerIsOfferedToChangeThePuzzle(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::EDIT_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-toggle-target-param="puzzlePicker"]'));
        self::assertStringContainsString('Change puzzle', $crawler->filter('[data-toggle-target="chosenPuzzle"]')->text());
        // The picker waits closed, holding the result's own puzzle, without "add new"
        $picker = $crawler->filter('[data-toggle-target="puzzlePicker"]');
        self::assertStringContainsString('hidden', (string) $picker->attr('class'));
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $picker->filter('#edit_puzzle_solving_time_form_puzzle')->attr('value'));
        self::assertNull($picker->filter('#edit_puzzle_solving_time_form_puzzle')->attr('disabled'));
        self::assertCount(0, $crawler->filter('[data-time-form-autocomplete-target="newPuzzle"]'));
        self::assertSame('false', $crawler->filter('[data-controller="time-form-autocomplete"]')->attr('data-time-form-autocomplete-allow-new-value'));
        // The live check reads the picked puzzle, not a fixed one
        self::assertSame('', $crawler->filter('form[name="edit_puzzle_solving_time_form"]')->attr('data-first-try-check-puzzle-value'));
    }

    public function testAnotherMemberOfThePairSeesThePuzzleOnly(): void
    {
        // TIME_12: a pair tracked by PLAYER_REGULAR
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-toggle-target-param="puzzlePicker"]'));
        self::assertStringContainsString('Only the puzzler who saved this result can change its puzzle.', $crawler->text());
        self::assertSame('disabled', $crawler->filter('#edit_puzzle_solving_time_form_puzzle')->attr('disabled'));
    }

    public function testTheTrackerMovesTheResult(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->submit($browser, self::EDIT_URL, PuzzleFixture::PUZZLE_500_04, ['timeMinutes' => '36', 'timeSeconds' => '40']);

        $this->assertResponseRedirects();
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $this->puzzleOf($browser, PuzzleSolvingTimeFixture::TIME_06));
    }

    public function testOnlyAnExistingPuzzleCanBePicked(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach ([Uuid::uuid7()->toString(), 'My new puzzle'] as $puzzle) {
            $crawler = $this->submit($browser, self::EDIT_URL, $puzzle, ['timeMinutes' => '36', 'timeSeconds' => '40']);

            $this->assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Choose a puzzle from the list.', $crawler->filter('[data-toggle-target="puzzlePicker"]')->text());
            // Refused on the picker: it comes back open
            self::assertStringNotContainsString('hidden', (string) $crawler->filter('[data-toggle-target="puzzlePicker"]')->attr('class'));
            self::assertSame(PuzzleFixture::PUZZLE_500_02, $this->puzzleOf($browser, PuzzleSolvingTimeFixture::TIME_06));
        }
    }

    public function testAnotherMemberCanNotMoveThePairResult(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // The field is disabled for them - whatever is sent, the result stays where it is
        $this->submit($browser, '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12, PuzzleFixture::PUZZLE_1000_04, ['timeHours' => '1', 'comment' => 'Edited by the partner'], ['#player2']);

        $this->assertResponseRedirects();
        self::assertSame(PuzzleFixture::PUZZLE_1000_01, $this->puzzleOf($browser, PuzzleSolvingTimeFixture::TIME_12));
    }

    public function testTheSameTimeOnThePickedPuzzleIsAskedAbout(): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario($browser->getContainer());
        $twin = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'right puzzle');
        $wrong = $this->addOnPuzzle4000($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/edit-time/' . $wrong;
        $fields = ['timeHours' => '5', 'timeMinutes' => '0', 'timeSeconds' => '0'];

        // Time, day and people did not change - on its own puzzle that would never be asked, on another one it is
        $crawler = $this->submit($browser, $url, FirstTryScenario::PUZZLE, $fields);

        $this->assertResponseStatusCodeSame(422);
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertStringContainsString('This exact time is already saved', $notice->text());
        self::assertCount(1, $notice->filter('a[href="/en/result/' . $twin . '"]'));
        self::assertSame(PuzzleFixture::PUZZLE_4000, $this->puzzleOf($browser, $wrong));
        // The card shows the puzzle the player picked
        self::assertStringContainsString('3000', $crawler->filter('[data-toggle-target="chosenPuzzle"]')->text());

        // The live check says the same for the picked puzzle
        $browser->request('GET', '/en/first-try-check?' . http_build_query([
            'time' => $wrong,
            'puzzle' => FirstTryScenario::PUZZLE,
            'date' => $this->today(),
            'first_attempt' => '0',
            'seconds' => 5 * 3600,
        ]));
        self::assertStringContainsString('This exact time is already saved', (string) $browser->getResponse()->getContent());

        // ...and nothing for its own puzzle, where the result is alone
        $browser->request('GET', '/en/first-try-check?' . http_build_query([
            'time' => $wrong,
            'puzzle' => PuzzleFixture::PUZZLE_4000,
            'date' => $this->today(),
            'first_attempt' => '0',
            'seconds' => 5 * 3600,
        ]));
        self::assertSame('', $browser->getResponse()->getContent());

        $this->submit($browser, $url, FirstTryScenario::PUZZLE, $fields, duplicateConfirmed: true);

        $this->assertResponseRedirects();
        self::assertSame(FirstTryScenario::PUZZLE, $this->puzzleOf($browser, $wrong));
    }

    public function testTheLiveCheckIgnoresAPuzzleFromAnotherMember(): void
    {
        $browser = $this->browser();
        $check = function (string $puzzleId) use ($browser): string {
            $browser->request('GET', '/en/first-try-check?' . http_build_query([
                'time' => PuzzleSolvingTimeFixture::TIME_12,
                'puzzle' => $puzzleId,
                'first_attempt' => '1',
                'seconds' => 1800,
            ]));
            $this->assertResponseIsSuccessful();

            return (string) $browser->getResponse()->getContent();
        };

        // TIME_12 is a pair result on PUZZLE_1000_01. For its tracker the picked puzzle counts (on PUZZLE_500_01 they
        // have 30:00, TIME_01)...
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertNotSame($check(PuzzleFixture::PUZZLE_1000_01), $check(PuzzleFixture::PUZZLE_500_01));

        // ...for the partner it changes nothing - only the tracker may move the result
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertSame($check(PuzzleFixture::PUZZLE_1000_01), $check(PuzzleFixture::PUZZLE_500_01));
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    private function today(): string
    {
        return new DateTimeImmutable()->format('d.m.Y');
    }

    private function addOnPuzzle4000(KernelBrowser $browser): string
    {
        $timeId = Uuid::uuid7();

        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_4000,
            competitionId: null,
            time: '05:00:00',
            comment: 'wrong puzzle',
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable(),
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId->toString();
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $groupPlayers
     */
    private function submit(
        KernelBrowser $browser,
        string $url,
        string $puzzleId,
        array $fields,
        array $groupPlayers = [],
        bool $duplicateConfirmed = false,
    ): Crawler {
        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();

        $parameters = [
            'edit_puzzle_solving_time_form' => [
                '_token' => (string) $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                'puzzle' => $puzzleId,
                'timeHours' => '0',
                'timeMinutes' => '0',
                'timeSeconds' => '0',
                'finishedAt' => $this->today(),
                ...$fields,
            ],
            'duplicate_confirmed' => $duplicateConfirmed ? '1' : '',
        ];

        if ($groupPlayers !== []) {
            $parameters['group_players'] = $groupPlayers;
        }

        return $browser->request('POST', $url, $parameters);
    }

    private function puzzleOf(KernelBrowser $browser, string $timeId): string
    {
        $puzzleId = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsString($puzzleId);

        return $puzzleId;
    }
}
