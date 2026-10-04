<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetComparisonPuzzles;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetComparisonPuzzlesTest extends KernelTestCase
{
    use ComparisonSeeding;

    public function testHydratesInTheGivenOrderAndMasksHiddenImages(): void
    {
        self::bootKernel();
        $now = self::getContainer()->get(ClockInterface::class)->now();
        $query = self::getContainer()->get(GetComparisonPuzzles::class);

        $brand = $this->seedManufacturer('Comparison Brand');
        $first = $this->seedPuzzle(500, name: 'Lighthouse', manufacturerId: $brand, image: 'puzzles/lighthouse.jpg', imageRatio: 1.25);
        $second = $this->seedPuzzle(1000, name: 'Surprise', manufacturerId: $brand, hideImageUntil: $now->modify('+7 days'), image: 'puzzles/surprise.jpg', imageRatio: 0.8);
        $hidden = $this->seedPuzzle(1000, name: 'Not yet', hideUntil: $now->modify('+7 days'));
        $noBrand = $this->seedPuzzle(300, name: 'Brandless');

        $puzzles = $query->byIds([$second, 'not-a-uuid', $first, $hidden, strtoupper($noBrand), $first]);

        self::assertSame([$second, $first, $noBrand], array_keys($puzzles));

        self::assertSame('Lighthouse', $puzzles[$first]->name);
        self::assertSame('Comparison Brand', $puzzles[$first]->manufacturerName);
        self::assertSame(500, $puzzles[$first]->piecesCount);
        self::assertSame('puzzles/lighthouse.jpg', $puzzles[$first]->image);
        self::assertSame(1.25, $puzzles[$first]->imageRatio);

        self::assertNull($puzzles[$second]->image, 'Image hidden until later');
        self::assertNull($puzzles[$second]->imageRatio);
        self::assertSame('Surprise', $puzzles[$second]->name);

        self::assertNull($puzzles[$noBrand]->manufacturerName);
        self::assertSame([], $query->byIds([]));
    }
}
