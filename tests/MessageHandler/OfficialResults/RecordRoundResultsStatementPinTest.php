<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Baseline pin of the competition toolkit (R-review-and-plan.md §2.9, package F0): what one plain change set of the
 * official results write path (RecordRoundResultsHandler) costs in SQL statements - a solo round and a pair round,
 * results, table numbers and qualification only, nothing new. The toolkit's packages (A3 per-puzzle results, P1 time
 * proposals) must leave a set without their new fields exactly this cheap: a change here is a regression unless the
 * package says why in its PR.
 */
final class RecordRoundResultsStatementPinTest extends KernelTestCase
{
    public function testAPlainSoloChangeSet(): void
    {
        $statements = $this->statementsOf(OfficialResultsFixture::ROUND_GROUP_A, [
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'result', null, ['seconds' => 4000]),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'table_number', null, 7),
            self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'qualified', false, true),
        ]);

        self::assertSame(['applied', 'applied', 'applied'], $statements['statuses']);
        // Reads: the round, the acting player, the round's entries, the change receipts. Writes: a receipt per change,
        // one UPDATE of the entry
        self::assertSame(['reads' => 4, 'writes' => 4], ['reads' => count($statements['reads']), 'writes' => count($statements['writes'])], self::describe($statements));
    }

    public function testAPlainPairChangeSet(): void
    {
        $team = $this->teamRow(OfficialResultsFixture::TEAM_EDGES);
        $ref = 'team:' . OfficialResultsFixture::TEAM_EDGES;

        $statements = $this->statementsOf(OfficialResultsFixture::ROUND_PAIRS, [
            self::change($ref, 'table_number', $team['table_number'], 99),
            self::change($ref, 'qualified', $team['qualified_at'] !== null, $team['qualified_at'] === null),
        ]);

        self::assertSame(['applied', 'applied'], $statements['statuses']);
        // Reads: the round, the acting player, the round's participant rows, its teams, the change receipts. Writes: a
        // receipt per change, one UPDATE of the team
        self::assertSame(['reads' => 5, 'writes' => 3], ['reads' => count($statements['reads']), 'writes' => count($statements['writes'])], self::describe($statements));
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     * @return array{reads: list<string>, writes: list<string>, statuses: list<string>}
     */
    private function statementsOf(string $roundId, array $changes): array
    {
        self::bootKernel();
        $container = self::getContainer();
        // Like a request: nothing loaded before
        $container->get(EntityManagerInterface::class)->clear();

        /** @var DebugDataHolder $debugData */
        $debugData = $container->get('doctrine.debug_data_holder');
        $debugData->reset();

        $envelope = $container->get(MessageBusInterface::class)->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: $roundId,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
        ));
        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);

        $reads = [];
        $writes = [];
        foreach ($debugData->getData() as $queries) {
            if (!is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                $sql = is_array($query) && is_string($query['sql'] ?? null) ? ltrim($query['sql']) : '';

                if (preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $sql) === 1) {
                    $writes[] = $sql;
                } elseif (preg_match('/^(SELECT|WITH)\b/i', $sql) === 1) {
                    $reads[] = $sql;
                }
            }
        }

        return [
            'reads' => $reads,
            'writes' => $writes,
            'statuses' => array_map(static fn (RoundResultChangeOutcome $outcome): string => $outcome->status->value, $recorded->outcomes),
        ];
    }

    /**
     * @param array{reads: list<string>, writes: list<string>, statuses: list<string>} $statements
     */
    private static function describe(array $statements): string
    {
        $shorten = static fn (string $sql): string => substr((string) preg_replace('/\s+/', ' ', $sql), 0, 160);

        return "The plain change set's statements changed:\nReads:\n- " . implode("\n- ", array_map($shorten, $statements['reads']))
            . "\nWrites:\n- " . implode("\n- ", array_map($shorten, $statements['writes']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $entry, string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => $entry, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @return array{table_number: null|int, qualified_at: null|string}
     */
    private function teamRow(string $teamId): array
    {
        self::bootKernel();
        $row = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT table_number, qualified_at FROM competition_team WHERE id = :id', ['id' => $teamId]);
        assert(is_array($row));

        /** @var array{table_number: null|int, qualified_at: null|string} $row */
        return $row;
    }
}
