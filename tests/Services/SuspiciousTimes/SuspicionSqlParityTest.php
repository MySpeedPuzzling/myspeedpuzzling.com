<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SuspiciousTimes;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The scan computes the fingerprint and the piece-count range in SQL, the app in PHP - they must agree, or every
 * time would look like a different entry (a check on every run, trust lapsing) or land in another range.
 */
final class SuspicionSqlParityTest extends KernelTestCase
{
    public function testFingerprintIsTheSameInSqlAndPhp(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        // Solo, pair, a time without seconds and the fixture's own times
        $database->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = NULL WHERE id = :id', ['id' => SuspiciousTimesFixture::TIMES_EDITION_HISTORY[0]]);

        /** @var list<array{puzzle_id: string, pieces_count: int|string, seconds_to_solve: null|int|string, puzzling_type: string, puzzlers_count: int|string, fingerprint: string}> $rows */
        $rows = $database->fetchAllAssociative(
            'SELECT pst.puzzle_id, p.pieces_count, pst.seconds_to_solve, pst.puzzling_type, pst.puzzlers_count, ' . SuspicionFingerprint::sql('pst', 'p') . ' AS fingerprint
             FROM puzzle_solving_time pst INNER JOIN puzzle p ON p.id = pst.puzzle_id',
        );

        self::assertGreaterThan(50, count($rows));
        $types = [];

        foreach ($rows as $row) {
            $types[$row['puzzling_type']] = true;

            self::assertSame(
                SuspicionFingerprint::of(
                    $row['puzzle_id'],
                    (int) $row['pieces_count'],
                    $row['seconds_to_solve'] === null ? null : (int) $row['seconds_to_solve'],
                    $row['puzzling_type'],
                    (int) $row['puzzlers_count'],
                ),
                $row['fingerprint'],
            );
        }

        self::assertArrayHasKey('solo', $types);
        self::assertArrayHasKey('duo', $types);
    }

    public function testPiecesRangeIsTheSameInSqlAndPhp(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);

        $piecesCounts = [1, 99, 199, 200, 499, 500, 750, 751, 998, 999, 1000, 1200, 1201, 2000, 2001, 5000, 5001, 13500];

        /** @var list<array{pieces: int|string, pieces_range: string}> $rows */
        $rows = $database->fetchAllAssociative(
            'SELECT pieces, ' . SuspicionPiecesRange::sql('pieces') . ' AS pieces_range FROM unnest(CAST(:pieces AS int[])) AS pieces',
            ['pieces' => '{' . implode(',', $piecesCounts) . '}'],
        );

        self::assertCount(count($piecesCounts), $rows);

        foreach ($rows as $row) {
            self::assertSame(SuspicionPiecesRange::of((int) $row['pieces'])->value, $row['pieces_range'], "{$row['pieces']} pieces");
        }

        self::assertSame(SuspicionPiecesRange::From500, SuspicionPiecesRange::of(750));
        self::assertSame(SuspicionPiecesRange::From751, SuspicionPiecesRange::of(751));
        self::assertSame(SuspicionPiecesRange::From999, SuspicionPiecesRange::of(999));
        self::assertSame(SuspicionPiecesRange::From5001, SuspicionPiecesRange::of(5001));
    }
}
