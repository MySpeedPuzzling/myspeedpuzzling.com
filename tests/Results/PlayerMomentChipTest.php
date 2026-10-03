<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerMomentChip;
use SpeedPuzzling\Web\Value\PlayerMomentType;

/**
 * docs/features/players-page/README.md, "This week": up to two chips per person, most notable first.
 */
final class PlayerMomentChipTest extends TestCase
{
    public function testTheMostNotableComeFirst(): void
    {
        $chips = [
            self::chip(PlayerMomentType::FirstResult, daysAgo: 1),
            self::chip(PlayerMomentType::PersonalBest, daysAgo: 1, piecesCount: 300),
            self::chip(PlayerMomentType::PiecesMilestone, daysAgo: 1, value: 100_000),
            self::chip(PlayerMomentType::PuzzlesMilestone, daysAgo: 6, value: 50),
            self::chip(PlayerMomentType::PersonalBest, daysAgo: 5, piecesCount: 1000),
        ];

        self::assertSame(
            ['personal_best:1000', 'puzzles_milestone', 'pieces_milestone', 'personal_best:300', 'first_result'],
            self::labels(PlayerMomentChip::mostNotable($chips, 10)),
        );
        self::assertSame(
            ['personal_best:1000', 'puzzles_milestone'],
            self::labels(PlayerMomentChip::mostNotable($chips, 2)),
        );
    }

    public function testTwoChipsNeverSayTheSame(): void
    {
        $older500 = self::chip(PlayerMomentType::PersonalBest, daysAgo: 5, piecesCount: 500, value: 2400);
        $newer500 = self::chip(PlayerMomentType::PersonalBest, daysAgo: 2, piecesCount: 500, value: 2300);
        $fifty = self::chip(PlayerMomentType::PuzzlesMilestone, daysAgo: 4, value: 50);
        $hundred = self::chip(PlayerMomentType::PuzzlesMilestone, daysAgo: 3, value: 100);

        $picked = PlayerMomentChip::mostNotable([$older500, $fifty, $newer500, $hundred], 2);

        self::assertSame([$newer500, $hundred], $picked);
    }

    public function testBothBigPersonalBestsBeatAMilestone(): void
    {
        $chips = [
            self::chip(PlayerMomentType::PuzzlesMilestone, daysAgo: 1, value: 500),
            self::chip(PlayerMomentType::PersonalBest, daysAgo: 4, piecesCount: 500),
            self::chip(PlayerMomentType::PersonalBest, daysAgo: 2, piecesCount: 1000),
        ];

        self::assertSame(['personal_best:1000', 'personal_best:500'], self::labels(PlayerMomentChip::mostNotable($chips, 2)));
    }

    public function testNothingHappenedIsNoChip(): void
    {
        self::assertSame([], PlayerMomentChip::mostNotable([], 2));
        self::assertSame([], PlayerMomentChip::listFromJson('[]'));
    }

    public function testTheQueryJsonBecomesChipsAndSkipsWhatItDoesNotKnow(): void
    {
        $chips = PlayerMomentChip::listFromJson((string) json_encode([
            ['type' => 'personal_best', 'pieces_count' => 500, 'value' => 2300, 'occurred_at' => '2026-10-01T10:15:00'],
            ['type' => 'pieces_milestone', 'pieces_count' => null, 'value' => 1_000_000, 'occurred_at' => '2026-09-30T08:00:00'],
            ['type' => 'something_new', 'pieces_count' => null, 'value' => null, 'occurred_at' => '2026-09-30T08:00:00'],
            ['type' => 'first_result'],
        ]));

        self::assertCount(2, $chips);
        self::assertSame(PlayerMomentType::PersonalBest, $chips[0]->type);
        self::assertSame(500, $chips[0]->piecesCount);
        self::assertSame('2026-10-01 10:15', $chips[0]->occurredAt->format('Y-m-d H:i'));
        self::assertSame(PlayerMomentType::PiecesMilestone, $chips[1]->type);
        self::assertSame(1_000_000, $chips[1]->value);
    }

    #[DataProvider('compactValues')]
    public function testPiecesMilestonesAreCompact(int $value, string $expected): void
    {
        self::assertSame($expected, self::chip(PlayerMomentType::PiecesMilestone, daysAgo: 1, value: $value)->compactValue());
    }

    /**
     * @return iterable<array{int, string}>
     */
    public static function compactValues(): iterable
    {
        yield [100_000, '100k'];
        yield [250_000, '250k'];
        yield [500_000, '500k'];
        yield [1_000_000, '1M'];
        yield [2_500_000, '2.5M'];
        yield [10_000_000, '10M'];
        yield [999, '999'];
    }

    private static function chip(
        PlayerMomentType $type,
        int $daysAgo,
        null|int $piecesCount = null,
        null|int $value = null,
    ): PlayerMomentChip {
        return new PlayerMomentChip($type, $piecesCount, $value, (new DateTimeImmutable('2026-10-03 12:00'))->modify("-{$daysAgo} days"));
    }

    /**
     * @param list<PlayerMomentChip> $chips
     * @return list<string>
     */
    private static function labels(array $chips): array
    {
        return array_map(
            static fn (PlayerMomentChip $chip): string => $chip->type->value . ($chip->type === PlayerMomentType::PersonalBest ? ':' . $chip->piecesCount : ''),
            $chips,
        );
    }
}
