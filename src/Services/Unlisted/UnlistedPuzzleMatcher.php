<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Unlisted;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\UnlistedPuzzle;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Services\DataImport\ImportBrandResolver;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleMatcher;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleTierRules;
use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * What the catalogue has for a player's unlisted puzzles - **the import's own pairing rules, nothing of its own**
 * (docs/features/data-import/pairing.md): the same lookups of names, barcodes and brand codes
 * (GetImportPuzzleCandidates - never a hidden or secret puzzle, never one waiting for approval unless the player
 * added it), classified by the same pure rules (ImportPuzzleTierRules). Only what an unlisted puzzle holds is asked
 * about: the player's results on the profile are not looked at.
 *
 * Reads the catalogue and writes nothing.
 */
readonly final class UnlistedPuzzleMatcher
{
    public function __construct(
        private GetImportPuzzleCandidates $candidates,
        private ImportBrandResolver $brandResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The exact rules for unlisted puzzles **of one player**. One lookup for all of them.
     *
     * @param list<UnlistedPuzzle> $unlistedPuzzles
     *
     * @return array<string, array{tier: null|ImportPuzzleTier, puzzleId: null|string, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>, manufacturerId: null|string}>
     *         by unlisted puzzle id; `puzzleId` only for "found"
     */
    public function exact(array $unlistedPuzzles, string $playerId, DateTimeImmutable $now): array
    {
        if ($unlistedPuzzles === []) {
            return [];
        }

        $brandTexts = [];

        foreach ($unlistedPuzzles as $unlistedPuzzle) {
            if ($unlistedPuzzle->brandText !== null) {
                $brandTexts[] = $unlistedPuzzle->brandText;
            }
        }

        $brands = $this->brandResolver->resolve(array_values(array_unique($brandTexts)));
        $wanted = [];
        $manufacturerIds = [];

        foreach ($unlistedPuzzles as $unlistedPuzzle) {
            $id = $unlistedPuzzle->id->toString();
            $manufacturerIds[$id] = $unlistedPuzzle->brandText === null ? null : $brands[$unlistedPuzzle->brandText] ?? null;
            $wanted[] = [
                'id' => $id,
                'pieces' => $unlistedPuzzle->piecesCount,
                'manufacturerId' => $manufacturerIds[$id],
                'ownId' => null,
                'names' => ImportPuzzleMatcher::nameLines($unlistedPuzzle->name),
                'eans' => ImportPuzzleMatcher::codeLines(self::strings($unlistedPuzzle->eans), PuzzleTextSearch::barcodeLine(...)),
                'codes' => ImportPuzzleMatcher::codeLines(self::strings($unlistedPuzzle->brandCodes), PuzzleTextSearch::brandCodeLine(...)),
            ];
        }

        $hits = [];

        foreach ($this->candidates->exactHits($wanted, $playerId, $now) as $hit) {
            $hits[$hit['importPuzzleId']][] = ['puzzleId' => $hit['puzzleId'], 'how' => $hit['how'], 'pieces' => $hit['pieces'], 'manufacturerId' => $hit['manufacturerId'], 'isPlayers' => $hit['isPlayers'], 'capped' => $hit['capped']];
        }

        $said = [];

        foreach ($unlistedPuzzles as $unlistedPuzzle) {
            $id = $unlistedPuzzle->id->toString();
            $said[$id] = [...ImportPuzzleTierRules::exact($unlistedPuzzle->piecesCount, $manufacturerIds[$id], $hits[$id] ?? []), 'manufacturerId' => $manufacturerIds[$id]];
        }

        return $said;
    }

    /**
     * "Is it one of these?" for one unlisted puzzle's page: what the exact rules find, else the catalogue puzzles
     * with a similar name.
     *
     * @return array{manufacturerId: null|string, candidates: list<array{puzzleId: string, how: string, score: null|float, piecesDiffer: bool}>}
     */
    public function candidatesOf(UnlistedPuzzle $unlistedPuzzle, DateTimeImmutable $now): array
    {
        $playerId = $unlistedPuzzle->player->id->toString();
        $id = $unlistedPuzzle->id->toString();
        $exact = $this->exact([$unlistedPuzzle], $playerId, $now)[$id];

        if ($exact['candidates'] !== []) {
            return ['manufacturerId' => $exact['manufacturerId'], 'candidates' => $exact['candidates']];
        }

        $folded = PuzzleTextSearch::foldedName($unlistedPuzzle->name);

        // Under three characters there is no trigram to compare
        if (mb_strlen($folded) < 3) {
            return ['manufacturerId' => $exact['manufacturerId'], 'candidates' => []];
        }

        $wanted = [['id' => $id, 'name' => $folded, 'pieces' => $unlistedPuzzle->piecesCount, 'manufacturerId' => $exact['manufacturerId']]];
        // The similar-name lookup sets the trigram threshold for its transaction
        $found = $this->entityManager->wrapInTransaction(
            fn (): array => $this->candidates->similarNames($wanted, ImportPuzzleTierRules::SIMILAR_NAME_THRESHOLD, $playerId, $now),
        );
        $similar = [];

        foreach ($found as $puzzle) {
            $similar[] = ['puzzleId' => $puzzle['puzzleId'], 'pieces' => $puzzle['pieces'], 'score' => $puzzle['score']];
        }

        return ['manufacturerId' => $exact['manufacturerId'], 'candidates' => ImportPuzzleTierRules::similar($unlistedPuzzle->piecesCount, $similar)['candidates']];
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }
}
