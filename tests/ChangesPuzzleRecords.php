<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * Changes a fixture puzzle's names or codes the way the app does - through the entity, which rebuilds the search keys
 * the search reads. A raw UPDATE of `name` or `ean` would leave the keys behind.
 */
trait ChangesPuzzleRecords
{
    /**
     * @param null|PuzzleNames $alternativeNames null keeps the puzzle's other names
     */
    protected static function renamePuzzle(string $puzzleId, string $name, null|PuzzleNames $alternativeNames = null): void
    {
        self::changePuzzle($puzzleId, static function (Puzzle $puzzle) use ($name, $alternativeNames): void {
            $puzzle->changeNames($name, $puzzle->nameLanguage, $alternativeNames ?? $puzzle->alternativeNames(), new DateTimeImmutable());
        });
    }

    protected static function changePuzzleEan(string $puzzleId, null|string $ean): void
    {
        self::changePuzzle($puzzleId, static function (Puzzle $puzzle) use ($ean): void {
            $puzzle->updateProductIdentifiers($ean, $puzzle->identificationNumber);
        });
    }

    protected static function changePuzzleBrandCode(string $puzzleId, null|string $identificationNumber): void
    {
        self::changePuzzle($puzzleId, static function (Puzzle $puzzle) use ($identificationNumber): void {
            $puzzle->updateProductIdentifiers($puzzle->ean, $identificationNumber);
        });
    }

    /**
     * @param callable(Puzzle): void $change
     */
    private static function changePuzzle(string $puzzleId, callable $change): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $change($puzzle);
        $entityManager->flush();
        $entityManager->clear();
    }
}
