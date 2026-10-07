<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A merge deletes puzzles (ApprovePuzzleMergeRequestHandler, DeleteMergedPuzzlesOnMergeApproved). A table it forgets
 * either fails every merge of a puzzle with rows in it (no cascade) or loses those rows silently (cascade) - change
 * requests vanished that way until 2026-10 (docs/features/puzzle-approvals.md, "Outdated requests"). So every foreign
 * key to `puzzle` is listed here with what a merge does with its rows, and a new one fails until somebody decides.
 *
 * Puzzle ids kept without a foreign key on purpose (the decision log, merge audits, redirects, a merge request's
 * reported ids) outlive the puzzle by design - MergeRequestPuzzles follows the redirects.
 */
final class PuzzleMergeForeignKeyCoverageTest extends KernelTestCase
{
    private const array WHAT_A_MERGE_DOES = [
        'collection_item.puzzle_id' => 'moved onto the survivor, one already there for the same collection dropped',
        'competition_round_puzzle.puzzle_id' => 'moved onto the survivor, one already in the same round dropped',
        'conversation.puzzle_id' => 'moved onto the survivor',
        'duplicate_puzzle_signal.puzzle_a_id' => 'deleted with the puzzle - the daily detection finds a pair with the survivor again',
        'duplicate_puzzle_signal.puzzle_b_id' => 'deleted with the puzzle - the daily detection finds a pair with the survivor again',
        'lent_puzzle.puzzle_id' => 'moved onto the survivor, one the owner lends already dropped',
        'lent_puzzle_transfer.puzzle_id' => 'moved onto the survivor (bulk, ids recorded in the audit first)',
        'puzzle_change_request.puzzle_id' => 'moved onto the survivor (PuzzleChangeRequest::puzzleMergedInto()), then closed when already applied',
        'puzzle_difficulty.puzzle_id' => 'deleted with the puzzle - recomputed for the survivor by the insights batch',
        'puzzle_merge_request.source_puzzle_id' => 'set to null - the reported ids and the stored name stay (MergeRequestPuzzles)',
        'puzzle_solving_time.puzzle_id' => 'moved onto the survivor',
        'puzzle_statistics.puzzle_id' => 'deleted with the puzzle - recomputed for the survivor from the moved times',
        'sell_swap_list_item.puzzle_id' => 'moved onto the survivor, one the player offers already dropped',
        'sold_swapped_item.puzzle_id' => 'moved onto the survivor',
        'stopwatch.puzzle_id' => 'moved onto the survivor',
        'suspicious_time_puzzle_confirmation.puzzle_id' => 'deleted with the puzzle - bound to that puzzle\'s piece count, the queue asks about the survivor again',
        'tag_puzzle.puzzle_id' => 'moved onto the survivor, a tag it has already dropped',
        'wish_list_item.puzzle_id' => 'moved onto the survivor, one already on the player\'s list dropped',
    ];

    public function testEveryForeignKeyToPuzzleSaysWhatAMergeDoesWithIt(): void
    {
        self::bootKernel();

        $foreignKeys = self::getContainer()->get(Connection::class)->fetchFirstColumn(<<<SQL
SELECT c.conrelid::regclass::text || '.' || a.attname::text
FROM pg_constraint c
JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
WHERE c.confrelid = 'puzzle'::regclass AND c.contype = 'f'
ORDER BY 1
SQL);

        $listed = array_keys(self::WHAT_A_MERGE_DOES);
        sort($listed);

        self::assertSame(
            $listed,
            $foreignKeys,
            'A foreign key to puzzle appeared or went away - decide what a merge does with its rows (ApprovePuzzleMergeRequestHandler::migrateRecordsToSurvivor()) and list it here',
        );
    }
}
