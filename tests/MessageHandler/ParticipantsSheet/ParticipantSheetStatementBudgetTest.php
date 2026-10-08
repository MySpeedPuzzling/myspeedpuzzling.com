<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A save of the participants sheet reads the same whatever its size - the snapshot, the version and the applier's
 * loads are one statement per kind, never one per row (WJPC: 400 people, a pasted round of 200 pairs). Writes are one
 * statement per row written (Doctrine inserts rows one by one), never more.
 */
final class ParticipantSheetStatementBudgetTest extends KernelTestCase
{
    use SheetChangeSets;

    public function testAPasteOfTwoHundredPairsReadsAsMuchAsOneOfTwenty(): void
    {
        self::bootKernel();
        $people = $this->seedPeople(440);

        $small = $this->measure(Cup::ROUND_PAIRS_FINAL, array_slice($people, 0, 40));
        $large = $this->measure(Cup::ROUND_PAIRS, array_slice($people, 40, 400));

        self::assertSame(['applied'], array_values(array_unique($large['statuses'])));
        self::assertCount(200, $large['statuses']);
        self::assertSame($small['reads'], $large['reads'], "Reads of 20 pairs:\n" . implode("\n", $small['readSql']) . "\n\nReads of 200 pairs:\n" . implode("\n", $large['readSql']));
        self::assertLessThanOrEqual(25, $large['reads']);

        // A pair and its two places, plus the change set's receipt
        self::assertSame(20 * 3 + 1, $small['writes']);
        self::assertSame(200 * 3 + 1, $large['writes']);

        self::assertSame(200, self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM competition_team WHERE round_id = :round AND name LIKE :name',
            ['round' => Cup::ROUND_PAIRS, 'name' => 'Paste %'],
        ));
    }

    /**
     * @param list<string> $people
     * @return array{reads: int, writes: int, readSql: list<string>, statuses: list<string>}
     */
    private function measure(string $roundId, array $people): array
    {
        $groups = [];
        foreach (array_chunk($people, 2) as $number => [$first, $second]) {
            $teamId = Uuid::uuid7()->toString();
            $groups[] = self::sheetGroup(
                ['op' => 'newTeam', 'id' => $teamId, 'round' => $roundId, 'name' => 'Paste ' . $number],
                self::placeChange($first, $roundId, 'out', 'team:' . $teamId),
                self::placeChange($second, $roundId, 'out', 'team:' . $teamId),
            );
        }

        // Like a request: nothing loaded before
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $applied = $this->applySheetChanges($groups);

        $reads = [];
        $writes = 0;
        foreach ($debugData->getData() as $queries) {
            if (!is_array($queries)) {
                continue;
            }

            foreach ($queries as $query) {
                $sql = is_array($query) && is_string($query['sql'] ?? null) ? ltrim($query['sql']) : '';

                if (preg_match('/^(INSERT|UPDATE|DELETE)\b/i', $sql) === 1) {
                    $writes++;
                } elseif (preg_match('/^(SELECT|WITH)\b/i', $sql) === 1) {
                    $reads[] = $sql;
                }
            }
        }

        return [
            'reads' => count($reads),
            'writes' => $writes,
            'readSql' => $reads,
            'statuses' => self::groupStatuses($applied),
        ];
    }

    /**
     * @return list<string> participant ids
     */
    private function seedPeople(int $count): array
    {
        $database = self::getContainer()->get(Connection::class);
        $ids = [];
        $values = [];
        $parameters = [];

        for ($i = 0; $i < $count; $i++) {
            $id = Uuid::uuid7()->toString();
            $ids[] = $id;
            $values[] = sprintf('(:id%1$d, :name%1$d, :competition, \'imported\')', $i);
            $parameters['id' . $i] = $id;
            $parameters['name' . $i] = sprintf('Seeded Person %03d', $i);
        }

        $parameters['competition'] = Cup::COMPETITION_RESULTS_CUP;
        $database->executeStatement(
            'INSERT INTO competition_participant (id, name, competition_id, source) VALUES ' . implode(', ', $values),
            $parameters,
        );

        return $ids;
    }
}
