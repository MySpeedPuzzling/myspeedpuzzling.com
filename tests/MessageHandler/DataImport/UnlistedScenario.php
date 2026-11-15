<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\DecideImportPuzzles;
use SpeedPuzzling\Web\Message\PairUnlistedPuzzle;
use SpeedPuzzling\Web\Message\ProcessUnlistedPairing;
use SpeedPuzzling\Web\Message\UndoUnlistedPairing;
use SpeedPuzzling\Web\MessageHandler\DecideImportPuzzlesHandler;
use SpeedPuzzling\Web\MessageHandler\ProcessUnlistedPairingHandler;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleDecisionChange;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Unlisted puzzles the way a player gets them (docs/features/data-import/unlisted-puzzles.md): rows of an import
 * whose puzzle the player keeps as unlisted ("Keep it as an unlisted puzzle" - the real DecideImportPuzzles), added
 * by the real engine; pairings sent through the bus, the worker's turns taken by hand. Invented puzzles only.
 */
trait UnlistedScenario
{
    use ImportAddingScenario;

    /**
     * "Keep it as an unlisted puzzle" for the import's puzzles of these names.
     */
    private function keepUnlisted(DataImport|string $import, Player|string $player, string ...$names): void
    {
        $changes = [];

        foreach ($names as $name) {
            $importPuzzle = $this->importPuzzle($import, $name);
            $changes[] = new ImportPuzzleDecisionChange($importPuzzle['id'], ImportPuzzleChoice::Unlisted, null, $importPuzzle['decision_version']);
        }

        $this->handled(self::getContainer()->get(DecideImportPuzzlesHandler::class), new DecideImportPuzzles(
            is_string($import) ? $import : $import->id->toString(),
            is_string($player) ? $player : $player->id->toString(),
            $changes,
        ));
    }

    /**
     * "It is this catalogue puzzle" as the page sends it - and the worker until the pairing is through.
     */
    private function pairUnlisted(Player|string $player, string $unlistedPuzzleId, string $puzzleId, null|string $requestId = null, bool $work = true): UnlistedPairingOutcome
    {
        $this->pairingEntityManager()->clear();
        $outcome = self::getContainer()->get(MessageBusInterface::class)->dispatch(new PairUnlistedPuzzle(
            $requestId ?? Uuid::uuid7()->toString(),
            $unlistedPuzzleId,
            is_string($player) ? $player : $player->id->toString(),
            $puzzleId,
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(UnlistedPairingOutcome::class, $outcome);
        $this->pairingEntityManager()->clear();

        if ($work && $outcome->status === UnlistedPairingOutcome::STARTED && $outcome->pairingId !== null) {
            $this->workPairing($outcome->pairingId);
        }

        return $outcome;
    }

    private function undoPairing(Player|string $player, string $pairingId, bool $work = true): UnlistedPairingOutcome
    {
        $this->pairingEntityManager()->clear();
        $outcome = self::getContainer()->get(MessageBusInterface::class)->dispatch(new UndoUnlistedPairing(
            $pairingId,
            is_string($player) ? $player : $player->id->toString(),
        ))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(UnlistedPairingOutcome::class, $outcome);
        $this->pairingEntityManager()->clear();

        if ($work && $outcome->status === UnlistedPairingOutcome::STARTED) {
            $this->workPairing($pairingId);
        }

        return $outcome;
    }

    /**
     * The `imports` worker: turns of the pairing (or of its Undo) until it stands.
     */
    private function workPairing(string $pairingId, int $turns = 20): void
    {
        $handler = self::getContainer()->get(ProcessUnlistedPairingHandler::class);

        for ($turn = 0; $turn < $turns; $turn++) {
            if (in_array($this->pairingDatabase()->fetchOne('SELECT state FROM unlisted_pairing WHERE id = :id', ['id' => $pairingId]), ['running', 'undoing'], true) === false) {
                break;
            }

            $handler(new ProcessUnlistedPairing($pairingId));
        }

        $this->pairingEntityManager()->clear();
    }

    /**
     * @return array{id: string, name: string, brand_text: null|string, pieces_count: null|int, eans: string, picture_url: null|string, paired_puzzle_id: null|string, paired_at: null|string, suggested_puzzle_id: null|string}
     */
    private function unlistedPuzzle(Player|string $player, string $name): array
    {
        $row = $this->pairingDatabase()->fetchAssociative(
            'SELECT id, name, brand_text, pieces_count, eans, picture_url, paired_puzzle_id, paired_at, suggested_puzzle_id FROM unlisted_puzzle WHERE player_id = :playerId AND name = :name',
            ['playerId' => is_string($player) ? $player : $player->id->toString(), 'name' => $name],
        );
        self::assertIsArray($row, sprintf('The player has no unlisted puzzle "%s".', $name));

        /** @var array{id: string, name: string, brand_text: null|string, pieces_count: null|int, eans: string, picture_url: null|string, paired_puzzle_id: null|string, paired_at: null|string, suggested_puzzle_id: null|string} $row */
        return $row;
    }

    /**
     * @return list<array<string, mixed>> the unlisted puzzle's results, in the order they were made
     */
    private function unlistedResults(string $unlistedPuzzleId): array
    {
        return $this->pairingDatabase()->fetchAllAssociative(
            'SELECT id, seconds_to_solve, solved_on, date_precision, puzzling_type, people, first_attempt, comment, import_row_id, converted_at IS NOT NULL AS converted, converted_created, converted_solving_time_id, kept_reason FROM unlisted_result WHERE unlisted_puzzle_id = :id ORDER BY id',
            ['id' => $unlistedPuzzleId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unlistedMarks(string $unlistedPuzzleId): array
    {
        return $this->pairingDatabase()->fetchAllAssociative(
            'SELECT id, kind, comment, converted_at IS NOT NULL AS converted, converted_created, converted_item_id, kept_reason FROM unlisted_ownership WHERE unlisted_puzzle_id = :id ORDER BY kind',
            ['id' => $unlistedPuzzleId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pairingNow(null|string $pairingId): array
    {
        self::assertNotNull($pairingId);
        $row = $this->pairingDatabase()->fetchAssociative('SELECT * FROM unlisted_pairing WHERE id = :id', ['id' => $pairingId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * What every public number is counted from - an unlisted row must never move any of these.
     *
     * @return array{puzzles: int, results: int, collectionItems: int, wishListItems: int}
     */
    private function publicCounts(): array
    {
        return [
            'puzzles' => $this->pairingCount('SELECT COUNT(*) FROM puzzle'),
            'results' => $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time'),
            'collectionItems' => $this->pairingCount('SELECT COUNT(*) FROM collection_item'),
            'wishListItems' => $this->pairingCount('SELECT COUNT(*) FROM wish_list_item'),
        ];
    }
}
