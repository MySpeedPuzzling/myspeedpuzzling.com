<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The brand picker's ETag (PuzzleByBrandAutocompleteController) changes exactly when a puzzle's hide_until or
 * hide_image_until passes: the list (and the picture, EAN and brand code in it) changes at that moment, with no row
 * written.
 */
final class SearchPuzzleBrandFingerprintTest extends KernelTestCase
{
    public function testTheRevealMomentsChangeTheFingerprint(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $now = TestClock::now();
        $connection->executeStatement(
            'UPDATE puzzle SET hide_until = :hideUntil WHERE id = :id',
            ['hideUntil' => $now->modify('+1 day')->format('Y-m-d H:i:s'), 'id' => PuzzleFixture::PUZZLE_500_02],
        );
        $connection->executeStatement(
            'UPDATE puzzle SET hide_image_until = :hideUntil WHERE id = :id',
            ['hideUntil' => $now->modify('+2 days')->format('Y-m-d H:i:s'), 'id' => PuzzleFixture::PUZZLE_500_03],
        );

        $at = static fn (DateTimeImmutable $moment): string => new SearchPuzzle($connection, new MockClock($moment))
            ->brandFingerprint(ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        $fingerprints = [
            $at($now),
            $at($now->modify('+1 day -1 second')),
            $at($now->modify('+1 day')),
            $at($now->modify('+2 days -1 second')),
            $at($now->modify('+2 days')),
        ];

        self::assertSame($fingerprints[0], $fingerprints[1]);
        self::assertNotSame($fingerprints[1], $fingerprints[2]);
        self::assertSame($fingerprints[2], $fingerprints[3]);
        self::assertNotSame($fingerprints[3], $fingerprints[4]);
    }

    public function testEachBrandHasItsOwnFingerprint(): void
    {
        self::bootKernel();
        $search = self::getContainer()->get(SearchPuzzle::class);

        self::assertNotSame(
            $search->brandFingerprint(ManufacturerFixture::MANUFACTURER_RAVENSBURGER),
            $search->brandFingerprint(ManufacturerFixture::MANUFACTURER_TREFL),
        );
        self::assertSame(
            $search->brandFingerprint(ManufacturerFixture::MANUFACTURER_TREFL),
            $search->brandFingerprint(ManufacturerFixture::MANUFACTURER_TREFL),
        );
    }
}
