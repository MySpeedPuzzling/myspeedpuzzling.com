<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;

final class MistypedYearNormalizerTest extends TestCase
{
    public function testNullIsLeftUntouched(): void
    {
        $normalizer = new MistypedYearNormalizer();

        self::assertNull($normalizer->normalizeFinishedAt(null));
    }

    #[DataProvider('provideDates')]
    public function testNormalizesYear(string $input, string $expected): void
    {
        $normalizer = new MistypedYearNormalizer();

        $result = $normalizer->normalizeFinishedAt(new DateTimeImmutable($input));

        self::assertNotNull($result);
        self::assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideDates(): array
    {
        return [
            // The real production corruption: dropped "20" prefix.
            'year 0026 becomes 2026' => ['0026-06-16 00:00:00', '2026-06-16 00:00:00'],
            'year 0024 becomes 2024' => ['0024-07-07 00:00:00', '2024-07-07 00:00:00'],
            // Pivot boundary: below 40 maps to 20xx, 40 and above to 19xx.
            'year 0039 becomes 2039' => ['0039-01-01 00:00:00', '2039-01-01 00:00:00'],
            'year 0040 becomes 1940' => ['0040-01-01 00:00:00', '1940-01-01 00:00:00'],
            'year 0085 becomes 1985' => ['0085-05-05 00:00:00', '1985-05-05 00:00:00'],
            'year 0099 becomes 1999' => ['0099-12-31 00:00:00', '1999-12-31 00:00:00'],
            // Time component is preserved.
            'time of day is kept' => ['0026-06-16 14:30:45', '2026-06-16 14:30:45'],
            // Full years are never touched.
            'valid year 2026 is untouched' => ['2026-06-16 00:00:00', '2026-06-16 00:00:00'],
            'valid year 1999 is untouched' => ['1999-01-01 00:00:00', '1999-01-01 00:00:00'],
        ];
    }
}
