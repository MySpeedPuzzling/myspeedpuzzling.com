<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use SpeedPuzzling\Web\Value\PuzzlePickerSolved;
use SpeedPuzzling\Web\Value\PuzzlePickerSource;
use Symfony\Component\HttpFoundation\Request;

final class PuzzlePickerCriteriaTest extends TestCase
{
    private const string BRAND_A = '018d0002-0000-0000-0000-000000000001';
    private const string BRAND_B = '018d0002-0000-0000-0000-000000000002';

    public function testSignedInDefaults(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(), isAuthenticated: true);

        self::assertSame(PuzzlePickerSource::Mine, $criteria->source);
        self::assertSame(PuzzlePickerSolved::Any, $criteria->solved);
        self::assertSame([], $criteria->pieces);
        self::assertSame([], $criteria->brandIds);
        self::assertFalse($criteria->includeLentOut);
        self::assertNull($criteria->seed);
        self::assertTrue($criteria->isAuthenticated);
        self::assertTrue($criteria->isDefault());
        self::assertSame([], $criteria->toQueryParams(), 'Bare defaults must produce the bare canonical URL');
    }

    public function testGuestDefaults(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(), isAuthenticated: false);

        self::assertSame(PuzzlePickerSource::Any, $criteria->source);
        self::assertSame(PuzzlePickerSolved::Any, $criteria->solved);
        self::assertTrue($criteria->isDefault());
        self::assertFalse($criteria->hasPersonalFilters());
        self::assertSame([], $criteria->toQueryParams());
        self::assertSame([], $criteria->activeFilters());
    }

    public function testGuestPersonalFiltersAreForcedOff(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['source' => 'mine', 'solved' => 'never', 'lent' => '1', 'pieces' => ['500']]),
            isAuthenticated: false,
        );

        self::assertSame(PuzzlePickerSource::Any, $criteria->source);
        self::assertSame(PuzzlePickerSolved::Any, $criteria->solved);
        self::assertFalse($criteria->includeLentOut);
        self::assertFalse($criteria->hasPersonalFilters());
        self::assertSame([[500, 500]], $criteria->pieces, 'Puzzle-attribute filters stay available to guests');
        self::assertSame(['pieces' => ['500']], $criteria->toQueryParams());
    }

    public function testParsesEveryFilterFromTheRequest(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request([
                'source' => 'not_mine',
                'solved' => 'before',
                'pieces' => ['500', '300-499', '2000-', '-199'],
                'brand' => [self::BRAND_A, self::BRAND_B],
                'lent' => '1',
                'seed' => 'abcd1234',
            ]),
            isAuthenticated: true,
        );

        self::assertSame(PuzzlePickerSource::NotMine, $criteria->source);
        self::assertSame(PuzzlePickerSolved::Before, $criteria->solved);
        self::assertSame([[500, 500], [300, 499], [2000, null], [null, 199]], $criteria->pieces);
        self::assertSame(['500', '300-499', '2000-', '-199'], $criteria->piecesValues());
        self::assertSame([self::BRAND_A, self::BRAND_B], $criteria->brandIds);
        self::assertTrue($criteria->includeLentOut);
        self::assertSame('abcd1234', $criteria->seed);
        self::assertFalse($criteria->isDefault());
        self::assertTrue($criteria->hasPersonalFilters());
    }

    public function testToQueryParamsRoundTrips(): void
    {
        $query = [
            'source' => 'not_mine',
            'solved' => 'never',
            'pieces' => ['1000', '300-499'],
            'brand' => [self::BRAND_A],
            'lent' => '1',
            'seed' => 'zz99',
        ];

        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), isAuthenticated: true);
        $params = $criteria->toQueryParams();

        self::assertSame($query, $params);

        $rebuilt = PuzzlePickerCriteria::fromRequest(new Request($params), isAuthenticated: true);

        self::assertEquals($criteria, $rebuilt);
    }

    public function testToQueryParamsOmitsDefaultsAndCanOverrideTheSeed(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['source' => 'mine', 'solved' => 'any', 'seed' => 'abcd1234']),
            isAuthenticated: true,
        );

        self::assertSame(['seed' => 'abcd1234'], $criteria->toQueryParams());
        self::assertSame(['seed' => 'fresh01'], $criteria->toQueryParams('fresh01'));
        self::assertTrue($criteria->isDefault(), 'The seed alone does not make the criteria non-default');

        $bare = PuzzlePickerCriteria::fromRequest(new Request(), isAuthenticated: true);

        self::assertSame(['seed' => 'fresh01'], $bare->toQueryParams('fresh01'));
        self::assertSame(['source' => 'any'], PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any']), isAuthenticated: true)->toQueryParams());
        self::assertSame([], PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any']), isAuthenticated: false)->toQueryParams());
    }

    public function testWithSeed(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['pieces' => ['500']]), isAuthenticated: true);
        $seeded = $criteria->withSeed('c0ffee');

        self::assertNull($criteria->seed);
        self::assertSame('c0ffee', $seeded->seed);
        self::assertSame($criteria->pieces, $seeded->pieces);
        self::assertSame(['pieces' => ['500'], 'seed' => 'c0ffee'], $seeded->toQueryParams());
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('provideInvalidInput')]
    public function testInvalidInputIsDroppedNotCrashedOn(array $query): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), isAuthenticated: true);

        self::assertSame(PuzzlePickerSource::Mine, $criteria->source);
        self::assertSame(PuzzlePickerSolved::Any, $criteria->solved);
        self::assertSame([], $criteria->pieces);
        self::assertSame([], $criteria->brandIds);
        self::assertFalse($criteria->includeLentOut);
        self::assertNull($criteria->seed);
        self::assertTrue($criteria->isDefault());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidInput(): iterable
    {
        yield 'unknown enum values' => [['source' => 'theirs', 'solved' => 'maybe']];
        yield 'nested structures' => [['source' => ['mine'], 'solved' => ['never'], 'pieces' => [['500']], 'brand' => [['x']], 'seed' => ['abcd']]];
        yield 'garbage pieces' => [['pieces' => ['abc', '0', '-', '500-abc', '1000000000', '', ' ']]];
        yield 'garbage custom range' => [['pieces_min' => 'abc', 'pieces_max' => '']];
        yield 'invalid brand ids' => [['brand' => ['not-a-uuid', '', '123']]];
        yield 'lent must be exactly 1' => [['lent' => 'yes']];
        yield 'seed too short' => [['seed' => 'abc']];
        yield 'seed too long' => [['seed' => str_repeat('a', 17)]];
        yield 'seed with forbidden characters' => [['seed' => 'ABCD-EFGH']];
    }

    /**
     * @param null|array{null|int, null|int} $expected
     */
    #[DataProvider('providePiecesGrammar')]
    public function testPiecesGrammar(string $input, null|array $expected): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['pieces' => [$input]]), isAuthenticated: true);

        self::assertSame($expected === null ? [] : [$expected], $criteria->pieces);
    }

    /**
     * @return iterable<string, array{string, null|array{null|int, null|int}}>
     */
    public static function providePiecesGrammar(): iterable
    {
        yield 'exact' => ['500', [500, 500]];
        yield 'exact with whitespace' => [' 500 ', [500, 500]];
        yield 'between' => ['300-499', [300, 499]];
        yield 'between reversed is normalized' => ['499-300', [300, 499]];
        yield 'at least' => ['2000-', [2000, null]];
        yield 'at most' => ['-199', [null, 199]];
        yield 'same bounds collapse to exact' => ['500-500', [500, 500]];
        yield 'zero is invalid' => ['0', null];
        yield 'negative is invalid' => ['-0', null];
        yield 'above the cap is invalid' => ['100001', null];
        yield 'dash only is invalid' => ['-', null];
        yield 'text is invalid' => ['many', null];
    }

    public function testCustomMinMaxInputsAreFoldedIntoPieces(): void
    {
        self::assertSame(
            [[600, 800]],
            PuzzlePickerCriteria::fromRequest(new Request(['pieces_min' => '600', 'pieces_max' => '800']), true)->pieces,
        );
        self::assertSame(
            [[600, null]],
            PuzzlePickerCriteria::fromRequest(new Request(['pieces_min' => '600', 'pieces_max' => '']), true)->pieces,
        );
        self::assertSame(
            [[null, 800]],
            PuzzlePickerCriteria::fromRequest(new Request(['pieces_max' => '800']), true)->pieces,
        );
        self::assertSame(
            [[600, 800]],
            PuzzlePickerCriteria::fromRequest(new Request(['pieces_min' => '800', 'pieces_max' => '600']), true)->pieces,
        );

        // Chips + custom range together, duplicates collapse
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['pieces' => ['500', '600-800'], 'pieces_min' => '600', 'pieces_max' => '800']),
            isAuthenticated: true,
        );

        self::assertSame([[500, 500], [600, 800]], $criteria->pieces);
        self::assertSame([600, 800], $criteria->customPiecesRange());
        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['pieces' => ['500', '2000-']]), true)->customPiecesRange());
    }

    public function testListsAreCappedAndDeduplicated(): void
    {
        $pieces = [];

        for ($i = 1; $i <= 20; $i++) {
            $pieces[] = (string) ($i * 100);
        }

        $pieces[] = '100';

        $brands = [];

        for ($i = 1; $i <= 25; $i++) {
            $brands[] = sprintf('018d0002-0000-0000-0000-%012d', $i);
        }

        $brands[] = self::BRAND_A;
        $brands[] = self::BRAND_A;

        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['pieces' => $pieces, 'brand' => $brands]), isAuthenticated: true);

        self::assertCount(PuzzlePickerCriteria::MAX_PIECES_RANGES, $criteria->pieces);
        self::assertCount(PuzzlePickerCriteria::MAX_BRANDS, $criteria->brandIds);
        self::assertSame(array_unique($criteria->brandIds), $criteria->brandIds);
    }

    public function testSinglePiecesAndBrandValuesAreAcceptedAsStrings(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['pieces' => '500', 'brand' => self::BRAND_A]), isAuthenticated: true);

        self::assertSame([[500, 500]], $criteria->pieces);
        self::assertSame([self::BRAND_A], $criteria->brandIds);
    }

    public function testActiveFiltersCarryTheQueryWithoutThemselves(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request([
                'source' => 'not_mine',
                'solved' => 'never',
                'pieces' => ['500', '2000-'],
                'brand' => [self::BRAND_A, self::BRAND_B],
                'lent' => '1',
            ]),
            isAuthenticated: true,
        );

        $filters = $criteria->activeFilters();
        $byKey = [];

        foreach ($filters as $filter) {
            $byKey[$filter->key] = $filter;
        }

        self::assertSame(['source', 'solved', 'pieces:500', 'pieces:2000-', 'brand:' . self::BRAND_A, 'brand:' . self::BRAND_B, 'lent'], array_keys($byKey));

        self::assertSame('puzzle_picker.chips.source.not_mine', $byKey['source']->translationKey);
        self::assertSame(
            ['source' => 'any', 'solved' => 'never', 'pieces' => ['500', '2000-'], 'brand' => [self::BRAND_A, self::BRAND_B], 'lent' => '1'],
            $byKey['source']->queryParametersWithoutThis,
            'Removing the source chip widens the pool to any puzzle',
        );

        self::assertSame('puzzle_picker.chips.solved.never', $byKey['solved']->translationKey);
        self::assertSame(
            ['source' => 'not_mine', 'pieces' => ['500', '2000-'], 'brand' => [self::BRAND_A, self::BRAND_B], 'lent' => '1'],
            $byKey['solved']->queryParametersWithoutThis,
        );

        self::assertSame('puzzle_picker.chips.pieces.exact', $byKey['pieces:500']->translationKey);
        self::assertSame(['%count%' => 500], $byKey['pieces:500']->translationParameters);
        self::assertSame(
            ['source' => 'not_mine', 'solved' => 'never', 'pieces' => ['2000-'], 'brand' => [self::BRAND_A, self::BRAND_B], 'lent' => '1'],
            $byKey['pieces:500']->queryParametersWithoutThis,
        );

        self::assertSame('puzzle_picker.chips.pieces.at_least', $byKey['pieces:2000-']->translationKey);
        self::assertSame(['%min%' => 2000], $byKey['pieces:2000-']->translationParameters);

        self::assertSame('brand', $byKey['brand:' . self::BRAND_A]->type);
        self::assertSame(self::BRAND_A, $byKey['brand:' . self::BRAND_A]->value);
        self::assertSame(
            ['source' => 'not_mine', 'solved' => 'never', 'pieces' => ['500', '2000-'], 'brand' => [self::BRAND_B], 'lent' => '1'],
            $byKey['brand:' . self::BRAND_A]->queryParametersWithoutThis,
        );

        self::assertSame(
            ['source' => 'not_mine', 'solved' => 'never', 'pieces' => ['500', '2000-'], 'brand' => [self::BRAND_A, self::BRAND_B]],
            $byKey['lent']->queryParametersWithoutThis,
        );
    }

    public function testActiveFiltersOnSignedInDefaultsShowTheShelfChip(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(), isAuthenticated: true);
        $filters = $criteria->activeFilters();

        self::assertCount(1, $filters);
        self::assertSame('source', $filters[0]->key);
        self::assertSame('puzzle_picker.chips.source.mine', $filters[0]->translationKey);
        self::assertSame(['source' => 'any'], $filters[0]->queryParametersWithoutThis, 'The × on the shelf chip widens the pool to any puzzle');

        self::assertSame([], PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any']), isAuthenticated: true)->activeFilters());
    }

    public function testActiveFiltersKeepTheSeedOfTheCurrentUrl(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['solved' => 'before', 'seed' => 'abcd1234']), isAuthenticated: true);
        $filters = $criteria->activeFilters();

        self::assertSame(['seed' => 'abcd1234'], $filters[1]->queryParametersWithoutThis);
    }

    public function testPiecesChipRangeFormatting(): void
    {
        self::assertSame('500', PuzzlePickerCriteria::formatPiecesRange([500, 500]));
        self::assertSame('300-499', PuzzlePickerCriteria::formatPiecesRange([300, 499]));
        self::assertSame('2000-', PuzzlePickerCriteria::formatPiecesRange([2000, null]));
        self::assertSame('-199', PuzzlePickerCriteria::formatPiecesRange([null, 199]));

        foreach (PuzzlePickerCriteria::PIECES_CHIPS as $chip) {
            $criteria = PuzzlePickerCriteria::fromRequest(new Request(['pieces' => [(string) $chip]]), isAuthenticated: true);

            self::assertSame([(string) $chip], $criteria->piecesValues(), "Chip {$chip} must round-trip through the grammar");
        }
    }
}
