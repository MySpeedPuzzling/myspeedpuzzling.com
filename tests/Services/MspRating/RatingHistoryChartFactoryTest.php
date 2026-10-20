<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MspRating;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\RatingHistory;
use SpeedPuzzling\Web\Services\MspRating\RatingHistoryChartFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RatingHistoryChartFactoryTest extends KernelTestCase
{
    public function testRatingAndReversedPlaceWithTheFormulaChangeMarked(): void
    {
        self::bootKernel();
        /** @var RatingHistoryChartFactory $factory */
        $factory = self::getContainer()->get(RatingHistoryChartFactory::class);

        $history = RatingHistory::fromSnapshots([
            ['pieces_count' => 500, 'date' => new DateTimeImmutable('2026-10-16'), 'rating' => 0.9, 'place' => 12],
            ['pieces_count' => 500, 'date' => new DateTimeImmutable('2026-10-17'), 'rating' => 0.91, 'place' => 10],
            ['pieces_count' => 500, 'date' => new DateTimeImmutable('2026-10-18'), 'rating' => 0.89, 'place' => 9],
            ['pieces_count' => 500, 'date' => new DateTimeImmutable('2026-10-19'), 'rating' => 0.895, 'place' => null],
        ]);

        self::assertSame(9, $history->bestPlace);
        self::assertSame('2026-10-18', $history->bestPlaceOn?->format('Y-m-d'));
        self::assertSame(3, $history->daysOnLadder);
        self::assertSame(910, $history->ratingOn(new DateTimeImmutable('2026-10-17')));

        $charts = $factory->charts($history);
        self::assertNotNull($charts);
        self::assertNotNull($charts['place']);

        /** @var array{scales: array{y: array{reverse: bool}}, plugins: array{leaderboardMarkers: array{markers: list<array{position: float}>}}} $rating */
        $rating = $charts['rating']->getOptions();
        /** @var array{scales: array{y: array{reverse: bool}}} $place */
        $place = $charts['place']->getOptions();
        /** @var array{datasets: list<array{data: list<null|int>}>} $ratingData */
        $ratingData = $charts['rating']->getData();
        /** @var array{datasets: list<array{data: list<null|int>}>} $placeData */
        $placeData = $charts['place']->getData();

        self::assertSame([900, 910, 890, 895], $ratingData['datasets'][0]['data']);
        self::assertFalse($rating['scales']['y']['reverse']);
        self::assertTrue($place['scales']['y']['reverse']);
        self::assertSame([12, 10, 9, null], $placeData['datasets'][0]['data']);
        // 18 Oct is the third day: centred on 2.5 in the marker plugin's bar units
        self::assertCount(1, $rating['plugins']['leaderboardMarkers']['markers']);
        self::assertSame(2.5, $rating['plugins']['leaderboardMarkers']['markers'][0]['position']);
    }

    public function testNoChartForASingleDay(): void
    {
        self::bootKernel();
        /** @var RatingHistoryChartFactory $factory */
        $factory = self::getContainer()->get(RatingHistoryChartFactory::class);

        self::assertNull($factory->charts(RatingHistory::fromSnapshots([
            ['pieces_count' => 500, 'date' => new DateTimeImmutable('2026-06-01'), 'rating' => 0.9, 'place' => 3],
        ])));
    }
}
