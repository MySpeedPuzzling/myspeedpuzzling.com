<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The St. Paul Winter Carnival set-up (docs/features/competitions-management/shared-puzzles-and-divisions.md, S2): Teams
 * CHALLENGE, Teams OPEN #1 and Teams OPEN #2 all solve one puzzle - three team rounds of one event sharing it, set up
 * through the round pages and through the internal API.
 */
final class SharedPuzzleCarnivalSetUpTest extends WebTestCase
{
    use InternalApiRequests;

    private const array ROUNDS = [
        'Teams CHALLENGE' => '2030-02-02T15:00:00Z',
        'Teams OPEN #1' => '2030-02-02T20:00:00Z',
        'Teams OPEN #2' => '2030-02-03T16:00:00Z',
    ];

    public function testThreeTeamRoundsShareOnePuzzleThroughTheRoundPages(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $roundIds = [];
        foreach (self::ROUNDS as $name => $startsAt) {
            $roundId = Uuid::uuid7();
            $bus->dispatch(new AddCompetitionRound(
                roundId: $roundId,
                competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
                name: $name,
                minutesLimit: 120,
                startsAt: new \DateTimeImmutable($startsAt),
                timezone: 'America/Chicago',
                badgeBackgroundColor: null,
                badgeTextColor: null,
                category: RoundCategory::Team,
            ));
            $roundIds[$name] = $roundId->toString();
        }

        $seen = [];
        foreach ($roundIds as $name => $roundId) {
            $crawler = $browser->request('GET', '/en/add-puzzle-to-round/' . $roundId);
            $form = $crawler->filter('form')->last()->form();
            $prefix = (string) $crawler->filter('input[name$="[puzzle]"]')->attr('name');
            $prefix = substr($prefix, 0, (int) strpos($prefix, '['));

            $browser->submit($form, [
                $prefix . '[brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
                $prefix . '[puzzle]' => PuzzleFixture::PUZZLE_500_03,
            ]);

            self::assertResponseRedirects('/en/manage-round-puzzles/' . $roundId);
            $crawler = $browser->followRedirect();

            // From the second round on, the organiser is told the puzzle is shared and how times find their round
            if ($seen === []) {
                self::assertCount(0, $crawler->filter('[data-shared-round-puzzle]'));
            } else {
                self::assertSelectorTextContains('.alert-info', 'Also used in ' . implode(', ', $seen));
            }

            $seen[] = $name;
        }

        // Each round's puzzles page names the other two
        foreach ($roundIds as $name => $roundId) {
            $crawler = $browser->request('GET', '/en/manage-round-puzzles/' . $roundId);
            $others = array_values(array_diff(array_keys($roundIds), [$name]));
            self::assertStringContainsString('Same puzzle as ' . implode(', ', $others), $crawler->filter('[data-shared-round-puzzle]')->text());
        }

        self::assertSame(3, $this->roundsWithThePuzzle(array_values($roundIds)));
    }

    public function testThreeTeamRoundsShareOnePuzzleThroughTheInternalApi(): void
    {
        $browser = self::createClient();

        $answers = [];
        foreach (self::ROUNDS as $name => $startsAt) {
            $answers[$name] = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
                'name' => $name,
                'category' => 'team',
                'startsAt' => $startsAt,
                'minutesLimit' => 120,
                'puzzleIds' => [PuzzleFixture::PUZZLE_500_03],
            ]);
            self::assertResponseStatusCodeSame(201);
        }

        // Created one after another: each answer names the rounds that had the puzzle before it
        self::assertSame([], self::sharedWith($answers['Teams CHALLENGE']));
        self::assertSame(['Teams CHALLENGE'], self::sharedWith($answers['Teams OPEN #1']));
        self::assertSame(['Teams CHALLENGE', 'Teams OPEN #1'], self::sharedWith($answers['Teams OPEN #2']));

        // Read again, every round names the other two, by start
        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertIsArray($competition['rounds']);
        $found = 0;
        foreach ($competition['rounds'] as $round) {
            self::assertIsArray($round);
            if (!is_string($round['name'] ?? null) || !array_key_exists($round['name'], self::ROUNDS)) {
                continue;
            }

            $others = array_values(array_diff(array_keys(self::ROUNDS), [$round['name']]));
            self::assertSame($others, self::sharedWith($round), $round['name']);
            $found++;
        }
        self::assertSame(3, $found);

        // Setting the list again (PUT …/puzzles) keeps it shared - no 409
        $openTwo = $answers['Teams OPEN #2']['roundId'];
        self::assertIsString($openTwo);
        $again = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $openTwo . '/puzzles', ['puzzleIds' => [PuzzleFixture::PUZZLE_500_03]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Teams CHALLENGE', 'Teams OPEN #1'], self::sharedWith($again));
    }

    /**
     * @param array<mixed> $round
     * @return list<string>
     */
    private static function sharedWith(array $round): array
    {
        self::assertIsArray($round['puzzles']);
        self::assertCount(1, $round['puzzles']);
        $puzzle = $round['puzzles'][0];
        self::assertIsArray($puzzle);
        self::assertSame(PuzzleFixture::PUZZLE_500_03, $puzzle['puzzleId']);
        self::assertIsArray($puzzle['sharedWithRounds']);

        /** @var list<string> $names */
        $names = $puzzle['sharedWithRounds'];

        return $names;
    }

    /**
     * @param list<string> $roundIds
     */
    private function roundsWithThePuzzle(array $roundIds): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(DISTINCT round_id) FROM competition_round_puzzle WHERE puzzle_id = :puzzle AND round_id IN (:rounds)',
            ['puzzle' => PuzzleFixture::PUZZLE_500_03, 'rounds' => $roundIds],
            ['rounds' => ArrayParameterType::STRING],
        );

        self::assertIsNumeric($count);

        return (int) $count;
    }
}
