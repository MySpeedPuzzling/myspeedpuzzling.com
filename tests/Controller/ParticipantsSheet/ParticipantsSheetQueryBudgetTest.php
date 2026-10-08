<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The participants spreadsheet reads an event in a constant number of statements, whatever its size (contract §4.2,
 * participants-spreadsheet.md §9): a WJPC-sized event - 400 people, 15 rounds (4 solo groups, semifinals, a final,
 * pairs and team rounds with ~100 pairs and ~50 teams) - costs exactly what the small Results Cup costs.
 */
final class ParticipantsSheetQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    // Signed in (the account, the profile), the event exists, the organiser's permissions - 4 - and then: the page and
    // the state = the version, the event, its rounds, its people, their places, the pairs/teams, the linked players'
    // own times (7); the version = the version (1). Measured 2026-10-08.
    private const int PAGE_BUDGET = 11;
    private const int STATE_BUDGET = 11;
    private const int VERSION_BUDGET = 5;

    public function testABigEventCostsWhatASmallOneCosts(): void
    {
        $browser = self::createClient();
        $big = $this->seedWjpcSizedEvent();
        TestingLogin::asPlayer($browser, self::ORGANISER);

        foreach (['page' => '/en/participants-sheet/%s', 'state' => '/en/participants-sheet-api/%s/state', 'version' => '/en/participants-sheet-api/%s/version'] as $what => $url) {
            $small = $this->statementsOf($browser, sprintf($url, OfficialResultsFixture::COMPETITION_RESULTS_CUP));
            $large = $this->statementsOf($browser, sprintf($url, $big));

            self::assertSame($small, $large, sprintf('The %s of a 400 people event must cost what a 9 people event costs: %d vs %d statements.', $what, $large, $small));

            $budget = match ($what) {
                'page' => self::PAGE_BUDGET,
                'state' => self::STATE_BUDGET,
                default => self::VERSION_BUDGET,
            };
            self::assertLessThanOrEqual($budget, $large, sprintf('The %s ran %d statements: %s', $what, $large, implode("\n", $this->executedSql($browser))));
        }

        // The state really is the big one
        $browser->request('GET', sprintf('/en/participants-sheet-api/%s/state', $big));
        $state = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['people']);
        self::assertIsArray($state['rounds']);
        self::assertIsArray($state['teams']);
        self::assertCount(400, $state['people']);
        self::assertCount(15, $state['rounds']);
        self::assertCount(100 + 10 + 50 + 5 + 10, $state['teams']);
    }

    private function statementsOf(KernelBrowser $browser, string $url): int
    {
        // Warm-up: whatever the page caches, the counted request finds it cached
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    /**
     * Plain inserts, invented names: 400 people (a few linked to players, a few removed), 15 rounds.
     */
    private function seedWjpcSizedEvent(): string
    {
        $database = self::getContainer()->get(Connection::class);
        $competitionId = Uuid::uuid7()->toString();

        $database->insert('competition', [
            'id' => $competitionId,
            'name' => 'Invented Jigsaw Championship',
            'slug' => 'invented-jigsaw-championship',
            'is_online' => 'false',
            'location' => 'Somewhere',
            'location_country_code' => 'cz',
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-22',
            'approved_at' => '2026-09-01 10:00:00',
            'added_by_player_id' => self::ORGANISER,
        ]);

        $rounds = [];
        $round = static function (string $name, string $category, int $hour) use (&$rounds, $competitionId): string {
            $id = Uuid::uuid7()->toString();
            $rounds[] = [$id, $competitionId, $name, 90, sprintf('2026-10-20 %02d:00:00', $hour), $category, $category === 'team' ? 4 : null];

            return $id;
        };

        $groups = [$round('Group A', 'solo', 8), $round('Group B', 'solo', 9), $round('Group C', 'solo', 10), $round('Group D', 'solo', 11)];
        $semis = [$round('Semifinal 1', 'solo', 12), $round('Semifinal 2', 'solo', 13)];
        $final = $round('Final', 'solo', 14);
        $pairs = [$round('Pairs 1', 'duo', 15), $round('Pairs 2', 'duo', 16)];
        $pairsFinal = $round('Pairs Final', 'duo', 17);
        $teams = [$round('Teams 1', 'team', 18), $round('Teams 2', 'team', 19)];
        $teamFinal = $round('Team Final', 'team', 20);
        $kids = $round('Kids', 'solo', 21);
        $relay = $round('Relay', 'team', 22);

        $this->insertRows($database, 'competition_round', ['id', 'competition_id', 'name', 'minutes_limit', 'starts_at', 'category', 'team_size'], $rounds);

        $linkedPlayers = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES];
        $people = [];
        $participantRows = [];
        for ($i = 0; $i < 400; $i++) {
            $id = Uuid::uuid7()->toString();
            $people[] = $id;
            $participantRows[] = [
                $id,
                $competitionId,
                sprintf('Invented Person %03d', $i),
                ['cz', 'de', 'us', 'pl', null][$i % 5],
                $i % 3 === 0 ? 'self_joined' : 'imported',
                $linkedPlayers[$i] ?? null,
                $i >= 390 ? '2026-10-01 10:00:00' : null,
            ];
        }
        $this->insertRows($database, 'competition_participant', ['id', 'competition_id', 'name', 'country', 'source', 'player_id', 'deleted_at'], $participantRows);

        $entries = [];
        $teamRows = [];
        $solo = static function (string $roundId, array $members, bool $withResults) use (&$entries): void {
            /** @var list<string> $memberIds */
            $memberIds = $members;

            foreach ($memberIds as $position => $participantId) {
                $entries[] = [Uuid::uuid7()->toString(), $participantId, $roundId, null, $position + 1, $withResults ? 3000 + $position * 7 : null];
            }
        };
        $grouped = static function (string $roundId, array $members, int $size) use (&$entries, &$teamRows): void {
            /** @var list<string> $memberIds */
            $memberIds = $members;

            foreach (array_chunk($memberIds, max(1, $size)) as $number => $chunk) {
                $teamId = Uuid::uuid7()->toString();
                $teamRows[] = [$teamId, $roundId, sprintf('Invented Team %d', $number + 1), $number + 1];

                foreach ($chunk as $participantId) {
                    $entries[] = [Uuid::uuid7()->toString(), $participantId, $roundId, $teamId, null, null];
                }
            }
        };

        foreach ($groups as $index => $groupId) {
            $solo($groupId, array_slice($people, $index * 100, 100), true);
        }
        $solo($semis[0], array_slice($people, 0, 40), true);
        $solo($semis[1], array_slice($people, 100, 40), false);
        $solo($final, array_slice($people, 0, 20), false);
        $grouped($pairs[0], array_slice($people, 0, 100), 2);
        $grouped($pairs[1], array_slice($people, 100, 100), 2);
        $grouped($pairsFinal, array_slice($people, 0, 20), 2);
        $grouped($teams[0], array_slice($people, 200, 100), 4);
        $grouped($teams[1], array_slice($people, 300, 100), 4);
        $grouped($teamFinal, array_slice($people, 200, 20), 4);
        $solo($kids, array_slice($people, 350, 30), false);
        $grouped($relay, array_slice($people, 250, 30), 3);

        $this->insertRows($database, 'competition_team', ['id', 'round_id', 'name', 'table_number'], $teamRows);
        $this->insertRows($database, 'competition_participant_round', ['id', 'participant_id', 'round_id', 'team_id', 'table_number', 'result_seconds'], $entries);

        return $competitionId;
    }

    /**
     * @param list<string> $columns
     * @param list<array<int, null|int|string>> $rows
     */
    private function insertRows(Connection $database, string $table, array $columns, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            $parameters = [];

            foreach ($chunk as $row) {
                $values[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                array_push($parameters, ...$row);
            }

            $database->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', $values)),
                $parameters,
            );
        }
    }
}
