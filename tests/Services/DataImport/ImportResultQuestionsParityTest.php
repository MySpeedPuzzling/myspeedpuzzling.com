<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\UnlistedScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A result of a spreadsheet reaches the profile two ways: a row of an "Add" whose puzzle is a catalogue puzzle
 * (ImportRowAdder), and an unlisted result whose puzzle the player pairs with a catalogue puzzle later
 * (UnlistedPairer). Both ask the same questions through the same code (ImportResultQuestions) - and this test holds
 * them to it from the outside, in the spirit of ImportWritePathParityTest: the same rows, the same profile, once
 * added directly and once kept as unlisted and then paired, must come to the same thing - the same answer per row,
 * the same results on the puzzle, the same "these are two" told to the duplicate job.
 *
 * The one listed difference: a question the player waved through on the row ("add it anyway") is waved for the
 * puzzle the row was paired with when they said so - a pairing asks again.
 */
final class ImportResultQuestionsParityTest extends KernelTestCase
{
    use UnlistedScenario;

    /**
     * @return iterable<string, array{list<array{string, null|int, array<string, mixed>}>, list<array{string, int, bool}>, list<string>}>
     *         the rows of the file (day, seconds, more of `parsed` + `separate`), what the player typed before
     *         (day, seconds, first try), and what every row comes to
     */
    public static function provideCases(): iterable
    {
        yield 'a new result with a time' => [[['2024-01-02', 2400, ['comment' => 'Words']]], [], ['made']];
        yield 'a new result without a time' => [[['2024-01-02', null, []]], [], ['made']];
        yield 'a pair with a guest' => [[['2024-01-02', 3000, ['type' => 'duo', 'people' => ['Anna']]]], [], ['made']];
        yield 'the same result is on the profile' => [[['2024-01-09', 2500, []]], [['2024-01-09', 2500, false]], ['already']];
        yield 'a similar result is on the profile' => [[['2024-01-12', 2600, []]], [['2024-01-12', 2700, false]], ['refused:similar_result_exists']];
        yield 'a pair that names nobody' => [[['2024-01-02', 3000, ['type' => 'duo']]], [], ['refused:people_do_not_fit_type']];
        yield 'the file says it is on MySpeedPuzzling' => [[['2024-01-02', 2400, ['alreadyOnMsp' => true]]], [], ['refused:said_already_on_profile']];
        yield 'faster than anybody puzzles' => [[['2024-01-02', 60, []]], [], ['refused:too_fast']];
        yield 'a first try that is taken' => [[['2024-02-02', 2400, ['firstTry' => true]]], [['2024-01-01', 2500, true]], ['refused:first_try_taken']];
        yield 'two equal rows that are one result' => [[['2024-01-02', 2400, []], ['2024-01-02', 2400, ['occurrence' => 2, 'sameAsFirst' => true]]], [], ['made', 'already']];
        yield 'two equal rows the player says are two' => [[['2024-01-02', 2400, []], ['2024-01-02', 2400, ['occurrence' => 2, 'sameAsFirst' => true, 'separate' => true]]], [], ['made', 'made']];
    }

    /**
     * @param list<array{string, null|int, array<string, mixed>}> $rows
     * @param list<array{string, int, bool}> $typedBefore
     * @param list<string> $expected
     */
    #[DataProvider('provideCases')]
    public function testAddingDirectlyAndPairingLaterComeToTheSameThing(array $rows, array $typedBefore, array $expected): void
    {
        self::bootKernel();
        $brand = $this->seedBrand('Zqparity');
        $puzzleOfAdd = $this->seedCataloguePuzzle('Zq Parity Added Directly', 1000, $brand);
        $puzzleOfPairing = $this->seedCataloguePuzzle('Zq Parity Paired Later', 1000, $this->pairingEntityManager()->find(Manufacturer::class, $brand->id->toString()));

        // --- An "Add" of rows whose puzzle is the catalogue puzzle ---
        $adder = $this->seedPairingPlayer('parity-add');
        $this->typed($adder, $puzzleOfAdd, $typedBefore);
        [$import, $sheet] = $this->importOf($adder);
        $rowIdsOfAdd = $this->seedRows($import, $sheet, 'Zq Parity Added Directly', $rows);
        $this->pair($import);
        $this->addAndWork($import, $adder);
        $answersOfAdd = [];

        foreach ($rowIdsOfAdd as $rowId) {
            $row = $this->rowNow($rowId);
            $answersOfAdd[] = match (true) {
                $row['state'] === 'added' && $row['result_made'] => 'made',
                $row['state'] === 'already_yours' => 'already',
                default => 'refused:' . ($row['failure'] ?? $row['state']),
            };
        }

        // --- The same rows kept as an unlisted puzzle, which is paired with the catalogue puzzle afterwards ---
        $pairer = $this->seedPairingPlayer('parity-pair');
        $this->typed($pairer, $puzzleOfPairing, $typedBefore);
        [$import, $sheet] = $this->importOf($pairer);
        $rowIdsOfPairing = $this->seedRows($import, $sheet, 'Zq Parity My Own Copy', $rows);
        $this->pair($import);
        $this->keepUnlisted($import, $pairer, 'Zq Parity My Own Copy');
        $this->addAndWork($import, $pairer);
        $this->pairUnlisted($pairer, $this->unlistedPuzzle($pairer, 'Zq Parity My Own Copy')['id'], $puzzleOfPairing);
        $answersOfPairing = [];

        foreach ($rowIdsOfPairing as $rowId) {
            $result = $this->pairingDatabase()->fetchAssociative('SELECT converted_at IS NOT NULL AS converted, converted_created, kept_reason FROM unlisted_result WHERE import_row_id = :id', ['id' => $rowId]);
            $answersOfPairing[] = match (true) {
                // A row that is the same result as another row of the file made no unlisted result of its own
                $result === false => 'already',
                $result['converted'] === true && $result['converted_created'] === true => 'made',
                $result['converted'] === true => 'already',
                default => 'refused:' . (is_string($result['kept_reason']) ? $result['kept_reason'] : 'nothing'),
            };
        }

        self::assertSame($expected, $answersOfAdd, 'An "Add" of the rows');
        self::assertSame($expected, $answersOfPairing, 'The rows kept as unlisted and paired later');
        // The same results on the puzzle - what both paths wrote, and what the player had
        self::assertSame($this->publicResults($adder, $puzzleOfAdd), $this->publicResults($pairer, $puzzleOfPairing));
        // "These are two", told to the duplicate job by both
        self::assertSame($this->savedAnyway($adder), $this->savedAnyway($pairer));
        self::assertSame(count(array_filter($rows, static fn (array $row): bool => ($row[2]['separate'] ?? false) === true)), $this->savedAnyway($pairer));
    }

    /**
     * @param list<array{string, null|int, array<string, mixed>}> $rows
     *
     * @return list<string>
     */
    private function seedRows(DataImport $import, DataImportSheet $sheet, string $name, array $rows): array
    {
        $ids = [];

        foreach ($rows as [$day, $seconds, $more]) {
            $sameAsFirst = ($more['sameAsFirst'] ?? false) === true;
            $separate = ($more['separate'] ?? false) === true;
            unset($more['sameAsFirst'], $more['separate']);
            $id = $this->resultRow($import, $sheet, $name, 1000, 'Zqparity', $day, $seconds, $more);

            if ($sameAsFirst) {
                // Equal rows as ImportEqualRows leaves them: numbered, linked to the row that stands for them
                $this->pairingDatabase()->update('data_import_row', ['same_as_row_id' => $ids[0], 'add_as_separate' => $separate ? 'true' : 'false'], ['id' => $id]);
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<array{string, int, bool}> $results
     */
    private function typed(Player $player, string $puzzleId, array $results): void
    {
        foreach ($results as [$day, $seconds, $firstTry]) {
            $this->typedResult($player, $puzzleId, $day, $seconds, [], $firstTry);
        }
    }

    /**
     * @return list<list<mixed>>
     */
    private function publicResults(Player $player, string $puzzleId): array
    {
        return array_map(
            static fn (array $result): array => [$result['finished_at'], $result['seconds_to_solve'], $result['puzzling_type'], $result['first_attempt'], $result['comment'], $result['created_via']],
            $this->resultsOf($player, $puzzleId),
        );
    }

    private function savedAnyway(Player $player): int
    {
        return $this->pairingCount("SELECT COUNT(*) FROM result_duplicate_prevention WHERE player_id = :id AND kind = 'saved_anyway'", ['id' => $player->id->toString()]);
    }
}
