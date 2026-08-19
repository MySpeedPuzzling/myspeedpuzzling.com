<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Value\PuzzlePickerCommunity;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use SpeedPuzzling\Web\Value\PuzzlePickerGap;
use SpeedPuzzling\Web\Value\PuzzlePickerMyTime;
use SpeedPuzzling\Web\Value\PuzzlePickerMyTimeOperator;
use SpeedPuzzling\Web\Value\PuzzlePickerOrder;
use SpeedPuzzling\Web\Value\PuzzlePickerPreset;
use SpeedPuzzling\Web\Value\PuzzlePickerSinceUnit;
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

    // ---------------------------------------------------------------------------------------------
    // Insights layer: difficulty tiers, gap, order, time budget - and who may use what
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function insightsQuery(): array
    {
        return [
            'difficulty' => ['3', '1', '3', '9', 'x', ''],
            'gap' => 'slower',
            'gap_min' => '5',
            'predicted_max' => '60',
            'order' => 'gap_slower',
        ];
    }

    public function testMemberWithPredictionsGetsEveryInsightsFilter(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(self::insightsQuery()), isAuthenticated: true, insightsAllowed: true, predictionsAllowed: true);

        self::assertSame([1, 3], $criteria->difficultyTiers, 'Deduplicated, sorted, invalid tiers dropped');
        self::assertSame(PuzzlePickerGap::Slower, $criteria->gap);
        self::assertSame(5, $criteria->gapMinMinutes);
        self::assertSame(300, $criteria->gapMinSeconds());
        self::assertSame(60, $criteria->predictedMaxMinutes);
        self::assertSame(PuzzlePickerOrder::GapSlower, $criteria->order);
        self::assertTrue($criteria->insightsAllowed);
        self::assertTrue($criteria->predictionsAllowed);
        self::assertTrue($criteria->usesPersonalPrediction());
        self::assertTrue($criteria->needsPredictions());
        self::assertTrue($criteria->hasPersonalFilters());
        self::assertFalse($criteria->isDefault());

        self::assertSame(
            ['difficulty' => ['1', '3'], 'gap' => 'slower', 'gap_min' => '5', 'predicted_max' => '60', 'order' => 'gap_slower'],
            $criteria->toQueryParams(),
        );
        self::assertEquals($criteria, PuzzlePickerCriteria::fromRequest(new Request($criteria->toQueryParams()), true, true, true), 'Round trip');
    }

    public function testNonMemberKeepsOnlyTheCommunityTimeBudget(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any'] + self::insightsQuery()), isAuthenticated: true);

        self::assertSame([], $criteria->difficultyTiers);
        self::assertNull($criteria->gap);
        self::assertNull($criteria->gapMinMinutes);
        self::assertSame(PuzzlePickerOrder::Random, $criteria->order);
        self::assertSame(60, $criteria->predictedMaxMinutes, 'The time budget is free - it just runs on the community engine');
        self::assertFalse($criteria->insightsAllowed);
        self::assertFalse($criteria->predictionsAllowed);
        self::assertFalse($criteria->usesPersonalPrediction());
        self::assertFalse($criteria->needsPredictions());
        self::assertFalse($criteria->hasPersonalFilters());
        self::assertSame(['source' => 'any', 'predicted_max' => '60'], $criteria->toQueryParams());
        self::assertSame(['predicted_max'], array_map(static fn ($filter) => $filter->key, $criteria->activeFilters()));

        // predictionsAllowed without insightsAllowed is not a thing
        $inconsistent = PuzzlePickerCriteria::fromRequest(new Request(self::insightsQuery()), isAuthenticated: true, insightsAllowed: false, predictionsAllowed: true);
        self::assertFalse($inconsistent->predictionsAllowed);
        self::assertNull($inconsistent->gap);
    }

    public function testMemberWhoOptedOutOfPredictionsKeepsDifficultyButNoPredictionFilters(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(self::insightsQuery()), isAuthenticated: true, insightsAllowed: true, predictionsAllowed: false);

        self::assertSame([1, 3], $criteria->difficultyTiers);
        self::assertNull($criteria->gap);
        self::assertNull($criteria->gapMinMinutes);
        self::assertSame(PuzzlePickerOrder::Random, $criteria->order);
        self::assertSame(60, $criteria->predictedMaxMinutes);
        self::assertTrue($criteria->insightsAllowed);
        self::assertFalse($criteria->predictionsAllowed);
        self::assertFalse($criteria->usesPersonalPrediction(), 'Community engine for the budget');
        self::assertFalse($criteria->needsPredictions());
        self::assertSame(['difficulty' => ['1', '3'], 'predicted_max' => '60'], $criteria->toQueryParams());
    }

    public function testGuestKeepsOnlyTheCommunityTimeBudgetWhateverTheFlagsSay(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(self::insightsQuery()), isAuthenticated: false, insightsAllowed: true, predictionsAllowed: true);

        self::assertSame([], $criteria->difficultyTiers);
        self::assertNull($criteria->gap);
        self::assertSame(PuzzlePickerOrder::Random, $criteria->order);
        self::assertSame(60, $criteria->predictedMaxMinutes);
        self::assertFalse($criteria->insightsAllowed);
        self::assertFalse($criteria->predictionsAllowed);
        self::assertSame(['predicted_max' => '60'], $criteria->toQueryParams());
    }

    public function testNeedsPredictionsOnlyForGapOrderOrPersonalBudget(): void
    {
        $member = static fn (array $query): PuzzlePickerCriteria => PuzzlePickerCriteria::fromRequest(new Request($query), true, true, true);

        self::assertFalse($member([])->needsPredictions());
        self::assertFalse($member(['difficulty' => ['3']])->needsPredictions(), 'Difficulty needs no prediction');
        self::assertTrue($member(['gap' => 'faster'])->needsPredictions());
        self::assertTrue($member(['order' => 'gap_faster'])->needsPredictions());
        self::assertTrue($member(['predicted_max' => '45'])->needsPredictions());
        self::assertTrue($member([])->isDefault());
        self::assertFalse($member(['order' => 'gap_faster'])->isDefault());
        self::assertFalse($member(['predicted_max' => '45'])->isDefault());
        self::assertFalse($member(['difficulty' => ['3']])->isDefault());
        self::assertTrue($member(['order' => 'random'])->isDefault(), 'Random is the default order');
        self::assertSame([], $member(['order' => 'random'])->toQueryParams());
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('provideInvalidInsightsInput')]
    public function testInvalidInsightsInputIsDropped(array $query): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), isAuthenticated: true, insightsAllowed: true, predictionsAllowed: true);

        self::assertSame([], $criteria->difficultyTiers);
        self::assertNull($criteria->gap);
        self::assertNull($criteria->gapMinMinutes);
        self::assertNull($criteria->predictedMaxMinutes);
        self::assertSame(PuzzlePickerOrder::Random, $criteria->order);
        self::assertTrue($criteria->isDefault());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidInsightsInput(): iterable
    {
        yield 'unknown enum values' => [['gap' => 'same', 'order' => 'newest']];
        yield 'nested structures' => [['gap' => ['slower'], 'order' => ['gap_slower'], 'difficulty' => [['3']], 'gap_min' => ['5'], 'predicted_max' => ['60']]];
        yield 'tiers out of range' => [['difficulty' => ['0', '7', '-1', '3.5', 'hard']]];
        yield 'gap_min without gap' => [['gap_min' => '5']];
        yield 'gap_min zero' => [['gap_min' => '0']];
        yield 'gap_min too big' => [['gap_min' => '601']];
        yield 'predicted_max out of range' => [['predicted_max' => '4']];
        yield 'predicted_max too big' => [['predicted_max' => '1441']];
        yield 'predicted_max not a number' => [['predicted_max' => 'hour']];
        yield 'blank inputs from the form' => [['gap' => '', 'gap_min' => '', 'predicted_max' => '', 'order' => '']];
    }

    public function testGapMinIsOptionalAndDefaultsToOneMinute(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['gap' => 'faster']), true, true, true);

        self::assertSame(PuzzlePickerGap::Faster, $criteria->gap);
        self::assertNull($criteria->gapMinMinutes);
        self::assertSame(60, $criteria->gapMinSeconds());
        self::assertSame(['gap' => 'faster'], $criteria->toQueryParams());

        // Out-of-range gap_min is dropped, the gap itself survives
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['gap' => 'faster', 'gap_min' => '999']), true, true, true);
        self::assertSame(PuzzlePickerGap::Faster, $criteria->gap);
        self::assertNull($criteria->gapMinMinutes);
    }

    public function testInsightsChipsRemoveThemselves(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['source' => 'any', 'difficulty' => ['2', '4'], 'gap' => 'slower', 'gap_min' => '3', 'predicted_max' => '90', 'order' => 'gap_slower', 'seed' => 'abcd1234']),
            isAuthenticated: true,
            insightsAllowed: true,
            predictionsAllowed: true,
        );

        $byKey = [];

        foreach ($criteria->activeFilters() as $filter) {
            $byKey[$filter->key] = $filter;
        }

        self::assertSame(['predicted_max', 'difficulty:2', 'difficulty:4', 'gap', 'order'], array_keys($byKey));

        self::assertSame('puzzle_picker.chips.predicted_max', $byKey['predicted_max']->translationKey);
        self::assertSame(['%minutes%' => 90], $byKey['predicted_max']->translationParameters);
        self::assertSame(
            ['source' => 'any', 'difficulty' => ['2', '4'], 'gap' => 'slower', 'gap_min' => '3', 'order' => 'gap_slower', 'seed' => 'abcd1234'],
            $byKey['predicted_max']->queryParametersWithoutThis,
        );

        self::assertSame('difficulty', $byKey['difficulty:2']->type);
        self::assertSame('puzzle_intelligence.difficulty.tiers.easy', $byKey['difficulty:2']->translationKey);
        self::assertSame('2', $byKey['difficulty:2']->value);
        self::assertSame(['4'], $byKey['difficulty:2']->queryParametersWithoutThis['difficulty']);
        self::assertSame('puzzle_intelligence.difficulty.tiers.challenging', $byKey['difficulty:4']->translationKey);
        $onlyFour = PuzzlePickerCriteria::fromRequest(new Request($byKey['difficulty:2']->queryParametersWithoutThis), true, true, true);
        self::assertSame([4], $onlyFour->difficultyTiers);
        self::assertArrayNotHasKey('difficulty', $onlyFour->activeFilters()[1]->queryParametersWithoutThis, 'Removing the last tier drops the parameter');

        self::assertSame('puzzle_picker.chips.gap.slower_by', $byKey['gap']->translationKey);
        self::assertSame(['%minutes%' => 3], $byKey['gap']->translationParameters);
        self::assertSame(
            ['source' => 'any', 'difficulty' => ['2', '4'], 'predicted_max' => '90', 'order' => 'gap_slower', 'seed' => 'abcd1234'],
            $byKey['gap']->queryParametersWithoutThis,
            'Removing the gap chip drops gap_min with it',
        );

        self::assertSame('puzzle_picker.chips.order.gap_slower', $byKey['order']->translationKey);
        self::assertSame(
            ['source' => 'any', 'difficulty' => ['2', '4'], 'gap' => 'slower', 'gap_min' => '3', 'predicted_max' => '90', 'seed' => 'abcd1234'],
            $byKey['order']->queryParametersWithoutThis,
        );

        // Gap chip without a minimum
        $plain = PuzzlePickerCriteria::fromRequest(new Request(['gap' => 'faster']), true, true, true)->activeFilters();
        self::assertSame('puzzle_picker.chips.gap.faster', $plain[1]->translationKey);
    }

    // ---------------------------------------------------------------------------------------------
    // Precision filters: solve-count range, "not solved since", my time, community results
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @param array{int, null|int} $expectedRange
     * @param array<string, mixed> $expectedParams
     */
    #[DataProvider('provideSolveCountRanges')]
    public function testSolvedStateIsOneSolveCountRange(array $query, array $expectedRange, PuzzlePickerSolved $expectedShape, array $expectedParams): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request($query), isAuthenticated: true);

        self::assertSame($expectedRange, [$criteria->solvedMin, $criteria->solvedMax]);
        self::assertSame($expectedShape, $criteria->solved);
        self::assertSame($expectedParams, $criteria->toQueryParams());
        self::assertEquals($criteria, PuzzlePickerCriteria::fromRequest(new Request($criteria->toQueryParams()), true), 'Round trip');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{int, null|int}, PuzzlePickerSolved, array<string, mixed>}>
     */
    public static function provideSolveCountRanges(): iterable
    {
        yield 'any' => [[], [0, null], PuzzlePickerSolved::Any, []];
        yield 'never' => [['solved' => 'never'], [0, 0], PuzzlePickerSolved::Never, ['solved' => 'never']];
        yield 'before' => [['solved' => 'before'], [1, null], PuzzlePickerSolved::Before, ['solved' => 'before']];
        yield 'explicit never' => [['solved_max' => '0'], [0, 0], PuzzlePickerSolved::Never, ['solved' => 'never']];
        yield 'explicit before' => [['solved_min' => '1'], [1, null], PuzzlePickerSolved::Before, ['solved' => 'before']];
        yield 'between' => [['solved_min' => '2', 'solved_max' => '5'], [2, 5], PuzzlePickerSolved::Any, ['solved_min' => '2', 'solved_max' => '5']];
        yield 'between reversed is normalized' => [['solved_min' => '5', 'solved_max' => '2'], [2, 5], PuzzlePickerSolved::Any, ['solved_min' => '2', 'solved_max' => '5']];
        yield 'at least' => [['solved_min' => '3'], [3, null], PuzzlePickerSolved::Any, ['solved_min' => '3']];
        yield 'at most' => [['solved_max' => '3'], [0, 3], PuzzlePickerSolved::Any, ['solved_max' => '3']];
        yield 'before refined by a maximum' => [['solved' => 'before', 'solved_max' => '5'], [1, 5], PuzzlePickerSolved::Any, ['solved_min' => '1', 'solved_max' => '5']];
        yield 'before refined by a minimum' => [['solved' => 'before', 'solved_min' => '2'], [2, null], PuzzlePickerSolved::Any, ['solved_min' => '2']];
        yield 'explicit minimum wins over never' => [['solved' => 'never', 'solved_min' => '2'], [2, null], PuzzlePickerSolved::Any, ['solved_min' => '2']];
        yield 'explicit maximum wins over before' => [['solved' => 'before', 'solved_max' => '0'], [0, 0], PuzzlePickerSolved::Never, ['solved' => 'never']];
        yield 'zero minimum is no bound' => [['solved_min' => '0'], [0, null], PuzzlePickerSolved::Any, []];
        yield 'blank inputs from the form' => [['solved' => 'any', 'solved_min' => '', 'solved_max' => ''], [0, null], PuzzlePickerSolved::Any, []];
        yield 'garbage is dropped' => [['solved_min' => 'many', 'solved_max' => '1000'], [0, null], PuzzlePickerSolved::Any, []];
    }

    public function testSolveCountChipDescribesTheWholeRangeAndClearsItAtOnce(): void
    {
        $chip = static function (array $query): \SpeedPuzzling\Web\Value\PuzzlePickerActiveFilter {
            $filters = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any'] + $query), true)->activeFilters();
            self::assertCount(1, $filters);

            return $filters[0];
        };

        self::assertSame('puzzle_picker.chips.solved.never', $chip(['solved' => 'never'])->translationKey);
        self::assertSame('puzzle_picker.chips.solved.before', $chip(['solved' => 'before'])->translationKey);
        self::assertSame('puzzle_picker.chips.solved.exact', $chip(['solved_min' => '3', 'solved_max' => '3'])->translationKey);
        self::assertSame(['%count%' => 3], $chip(['solved_min' => '3', 'solved_max' => '3'])->translationParameters);
        self::assertSame('puzzle_picker.chips.solved.between', $chip(['solved_min' => '2', 'solved_max' => '5'])->translationKey);
        self::assertSame(['%min%' => 2, '%max%' => 5], $chip(['solved_min' => '2', 'solved_max' => '5'])->translationParameters);
        self::assertSame('puzzle_picker.chips.solved.at_least', $chip(['solved_min' => '2'])->translationKey);
        self::assertSame('puzzle_picker.chips.solved.at_most', $chip(['solved_max' => '4'])->translationKey);
        self::assertSame(['%max%' => 4], $chip(['solved_max' => '4'])->translationParameters);

        self::assertSame('solved', $chip(['solved' => 'before', 'solved_max' => '5'])->key);
        self::assertSame(['source' => 'any'], $chip(['solved' => 'before', 'solved_max' => '5'])->queryParametersWithoutThis, 'The × drops the shape and both bounds');

        self::assertSame([], PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any', 'solved_min' => '0']), true)->activeFilters());
    }

    public function testNotSolvedSinceParsesAmountUnitAndTheSolvedOnlySwitch(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['since' => '6', 'since_unit' => 'm', 'since_require_solved' => '1']), true);

        self::assertSame(6, $criteria->sinceAmount);
        self::assertSame(PuzzlePickerSinceUnit::Month, $criteria->sinceUnit);
        self::assertTrue($criteria->sinceRequireSolved);
        self::assertTrue($criteria->hasPersonalFilters());
        self::assertFalse($criteria->isDefault());
        self::assertSame(['since' => '6', 'since_unit' => 'm', 'since_require_solved' => '1'], $criteria->toQueryParams());
        self::assertEquals($criteria, PuzzlePickerCriteria::fromRequest(new Request($criteria->toQueryParams()), true));

        // Days are the default unit and are not spelled out; unknown units fall back to days
        self::assertSame(['since' => '30'], PuzzlePickerCriteria::fromRequest(new Request(['since' => '30']), true)->toQueryParams());
        self::assertSame(PuzzlePickerSinceUnit::Day, PuzzlePickerCriteria::fromRequest(new Request(['since' => '30', 'since_unit' => 'y']), true)->sinceUnit);
        self::assertSame(['since' => '2', 'since_unit' => 'w'], PuzzlePickerCriteria::fromRequest(new Request(['since' => '2', 'since_unit' => 'w']), true)->toQueryParams());

        // Without a period the unit and the checkbox mean nothing
        $none = PuzzlePickerCriteria::fromRequest(new Request(['since_unit' => 'm', 'since_require_solved' => '1']), true);
        self::assertNull($none->sinceAmount);
        self::assertFalse($none->sinceRequireSolved);
        self::assertTrue($none->isDefault());

        foreach (['0', '-3', '1000', 'six', ''] as $invalid) {
            self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['since' => $invalid, 'since_unit' => 'm']), true)->sinceAmount, "since={$invalid}");
        }

        // Guests have no history
        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['since' => '6', 'since_unit' => 'm']), false)->sinceAmount);
        self::assertSame([], PuzzlePickerCriteria::fromRequest(new Request(['since' => '6', 'since_unit' => 'm']), false)->toQueryParams());

        self::assertSame('-6 months', PuzzlePickerSinceUnit::Month->modifier(6));
        self::assertSame('-2 weeks', PuzzlePickerSinceUnit::Week->modifier(2));
        self::assertSame('-30 days', PuzzlePickerSinceUnit::Day->modifier(30));
    }

    public function testNotSolvedSinceChips(): void
    {
        $filters = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any', 'since' => '6', 'since_unit' => 'm', 'seed' => 'abcd1234']), true)->activeFilters();
        self::assertCount(1, $filters);
        self::assertSame('since', $filters[0]->key);
        self::assertSame('puzzle_picker.chips.since.m', $filters[0]->translationKey);
        self::assertSame(['%count%' => 6], $filters[0]->translationParameters);
        self::assertSame(['source' => 'any', 'seed' => 'abcd1234'], $filters[0]->queryParametersWithoutThis);

        $solvedOnly = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any', 'since' => '3', 'since_unit' => 'w', 'since_require_solved' => '1']), true)->activeFilters();
        self::assertSame('puzzle_picker.chips.since_solved.w', $solvedOnly[0]->translationKey);
        self::assertSame(['source' => 'any'], $solvedOnly[0]->queryParametersWithoutThis, 'The × drops the period, the unit and the switch together');

        $days = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any', 'since' => '10']), true)->activeFilters();
        self::assertSame('puzzle_picker.chips.since.d', $days[0]->translationKey);
    }

    public function testMyTimeThresholdNeedsMetricAndMinutes(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['my_time' => 'latest', 'my_time_op' => 'gt', 'my_time_minutes' => '90']), true);

        self::assertSame(PuzzlePickerMyTime::Latest, $criteria->myTime);
        self::assertSame(PuzzlePickerMyTimeOperator::Over, $criteria->myTimeOperator);
        self::assertSame(90, $criteria->myTimeMinutes);
        self::assertSame(5400, $criteria->myTimeSeconds());
        self::assertTrue($criteria->hasPersonalFilters());
        self::assertFalse($criteria->isDefault());
        self::assertSame(['my_time' => 'latest', 'my_time_op' => 'gt', 'my_time_minutes' => '90'], $criteria->toQueryParams());
        self::assertEquals($criteria, PuzzlePickerCriteria::fromRequest(new Request($criteria->toQueryParams()), true));

        // "under" is the default operator and is not spelled out; unknown operators fall back to it
        $under = PuzzlePickerCriteria::fromRequest(new Request(['my_time' => 'fastest', 'my_time_minutes' => '30']), true);
        self::assertSame(PuzzlePickerMyTimeOperator::Under, $under->myTimeOperator);
        self::assertSame(['my_time' => 'fastest', 'my_time_minutes' => '30'], $under->toQueryParams());
        self::assertSame(PuzzlePickerMyTimeOperator::Under, PuzzlePickerCriteria::fromRequest(new Request(['my_time' => 'first', 'my_time_op' => 'eq', 'my_time_minutes' => '30']), true)->myTimeOperator);

        // Half a filter is no filter
        foreach (
            [
            ['my_time' => 'fastest'],
            ['my_time_minutes' => '30'],
            ['my_time' => 'fastest', 'my_time_minutes' => ''],
            ['my_time' => '', 'my_time_op' => 'gt', 'my_time_minutes' => '30'],
            ['my_time' => 'average', 'my_time_minutes' => '30'],
            ['my_time' => 'fastest', 'my_time_minutes' => '0'],
            ['my_time' => 'fastest', 'my_time_minutes' => '1441'],
            ['my_time' => ['fastest'], 'my_time_minutes' => ['30']],
            ] as $query
        ) {
            $none = PuzzlePickerCriteria::fromRequest(new Request($query), true);
            self::assertNull($none->myTime, json_encode($query) ?: '');
            self::assertNull($none->myTimeMinutes);
            self::assertNull($none->myTimeSeconds());
            self::assertTrue($none->isDefault());
        }

        // Guests have no times
        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['my_time' => 'fastest', 'my_time_minutes' => '30']), false)->myTime);

        $chip = PuzzlePickerCriteria::fromRequest(new Request(['source' => 'any', 'my_time' => 'first', 'my_time_op' => 'gt', 'my_time_minutes' => '45', 'pieces' => ['500']]), true)->activeFilters();
        self::assertSame(['my_time', 'pieces:500'], array_map(static fn ($filter) => $filter->key, $chip));
        self::assertSame('puzzle_picker.chips.my_time.first_gt', $chip[0]->translationKey);
        self::assertSame(['%minutes%' => 45], $chip[0]->translationParameters);
        self::assertSame(['source' => 'any', 'pieces' => ['500']], $chip[0]->queryParametersWithoutThis, 'The × drops metric, operator and minutes together');
        self::assertSame('puzzle_picker.chips.my_time.fastest_lt', $under->activeFilters()[1]->translationKey);
    }

    public function testCommunityResultsFilterIsFreeForEveryone(): void
    {
        foreach (['few' => PuzzlePickerCommunity::Few, 'rated' => PuzzlePickerCommunity::Rated, 'popular' => PuzzlePickerCommunity::Popular] as $value => $expected) {
            self::assertSame($expected, PuzzlePickerCriteria::fromRequest(new Request(['community' => $value]), true)->community);
            self::assertSame($expected, PuzzlePickerCriteria::fromRequest(new Request(['community' => $value]), false)->community, 'Guests keep the community filter');
        }

        $criteria = PuzzlePickerCriteria::fromRequest(new Request(['community' => 'rated', 'seed' => 'abcd1234']), false);
        self::assertSame(['community' => 'rated', 'seed' => 'abcd1234'], $criteria->toQueryParams());
        self::assertFalse($criteria->isDefault());
        self::assertFalse($criteria->hasPersonalFilters(), 'Community results are not a personal filter');

        $chips = $criteria->activeFilters();
        self::assertCount(1, $chips);
        self::assertSame('community', $chips[0]->key);
        self::assertSame('puzzle_picker.chips.community.rated', $chips[0]->translationKey);
        self::assertSame(['%count%' => 20], $chips[0]->translationParameters);
        self::assertSame(['seed' => 'abcd1234'], $chips[0]->queryParametersWithoutThis);

        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['community' => 'famous']), true)->community);
        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['community' => '']), true)->community);
        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(['community' => ['few']]), true)->community);

        self::assertSame('COALESCE(ps.solved_times_solo_count, 0) <= 5', PuzzlePickerCommunity::Few->sqlCondition('ps.solved_times_solo_count'));
        self::assertSame('ps.solved_times_solo_count >= 20', PuzzlePickerCommunity::Rated->sqlCondition('ps.solved_times_solo_count'));
        self::assertSame('ps.solved_times_solo_count >= 50', PuzzlePickerCommunity::Popular->sqlCondition('ps.solved_times_solo_count'));
    }

    // ---------------------------------------------------------------------------------------------
    // Specific collections (members)
    // ---------------------------------------------------------------------------------------------

    private const string COLLECTION_A = '018d0008-0000-0000-0000-000000000001';
    private const string COLLECTION_B = '018d0008-0000-0000-0000-000000000004';

    public function testMembersCanPickFromSpecificCollectionsWhichImplyTheirShelf(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['source' => 'not_mine', 'collections' => [self::COLLECTION_A, Collection::SYSTEM_ID, self::COLLECTION_A, 'not-a-uuid', '', self::COLLECTION_B]]),
            isAuthenticated: true,
            insightsAllowed: true,
        );

        self::assertSame([self::COLLECTION_A, Collection::SYSTEM_ID, self::COLLECTION_B], $criteria->collectionIds, 'Deduplicated, invalid ids dropped, sentinel kept');
        self::assertSame(PuzzlePickerSource::Mine, $criteria->source, 'Specific collections imply my shelf');
        self::assertTrue($criteria->includesSystemCollection());
        self::assertSame([self::COLLECTION_A, self::COLLECTION_B], $criteria->customCollectionIds());
        self::assertTrue($criteria->hasPersonalFilters());
        self::assertFalse($criteria->isDefault());
        self::assertSame(['collections' => [self::COLLECTION_A, Collection::SYSTEM_ID, self::COLLECTION_B]], $criteria->toQueryParams(), 'Source mine is the default and stays implicit');
        self::assertEquals($criteria, PuzzlePickerCriteria::fromRequest(new Request($criteria->toQueryParams()), true, true), 'Round trip');

        // A single value is accepted as a string
        self::assertSame([Collection::SYSTEM_ID], PuzzlePickerCriteria::fromRequest(new Request(['collections' => Collection::SYSTEM_ID]), true, true)->collectionIds);
        self::assertFalse(PuzzlePickerCriteria::fromRequest(new Request(['collections' => [self::COLLECTION_A]]), true, true)->includesSystemCollection());

        // Capped
        $many = [];

        for ($i = 1; $i <= 25; $i++) {
            $many[] = sprintf('018d0008-0000-0000-0000-%012d', $i);
        }

        self::assertCount(PuzzlePickerCriteria::MAX_COLLECTIONS, PuzzlePickerCriteria::fromRequest(new Request(['collections' => $many]), true, true)->collectionIds);
    }

    public function testCollectionsAreStrippedForNonMembersAndGuests(): void
    {
        $nonMember = PuzzlePickerCriteria::fromRequest(new Request(['collections' => [self::COLLECTION_A]]), isAuthenticated: true);
        self::assertSame([], $nonMember->collectionIds);
        self::assertTrue($nonMember->isDefault());
        self::assertSame([], $nonMember->toQueryParams());

        $guest = PuzzlePickerCriteria::fromRequest(new Request(['collections' => [self::COLLECTION_A]]), isAuthenticated: false, insightsAllowed: true, predictionsAllowed: true);
        self::assertSame([], $guest->collectionIds);
        self::assertSame(PuzzlePickerSource::Any, $guest->source);
    }

    public function testCollectionChipsReplaceTheShelfChipAndRemoveThemselves(): void
    {
        $criteria = PuzzlePickerCriteria::fromRequest(
            new Request(['collections' => [Collection::SYSTEM_ID, self::COLLECTION_A], 'solved' => 'never']),
            isAuthenticated: true,
            insightsAllowed: true,
        );

        $filters = $criteria->activeFilters();
        self::assertSame(['collection:' . Collection::SYSTEM_ID, 'collection:' . self::COLLECTION_A, 'solved'], array_map(static fn ($filter) => $filter->key, $filters), 'No separate "My collection" chip next to the collection chips');

        self::assertSame('collection', $filters[0]->type);
        self::assertSame(Collection::SYSTEM_ID, $filters[0]->value);
        self::assertSame('collections.system_name', $filters[0]->translationKey, 'The system collection has a fixed name');
        self::assertSame(['solved' => 'never', 'collections' => [self::COLLECTION_A]], $filters[0]->queryParametersWithoutThis);

        self::assertSame('puzzle_picker.chips.collection', $filters[1]->translationKey);
        self::assertSame(self::COLLECTION_A, $filters[1]->value);
        self::assertSame(['solved' => 'never', 'collections' => [Collection::SYSTEM_ID]], $filters[1]->queryParametersWithoutThis);

        // Removing the last collection chip falls back to the whole shelf - and its chip
        $shelf = PuzzlePickerCriteria::fromRequest(new Request($filters[1]->queryParametersWithoutThis), true, true)->activeFilters()[0];
        $shelfOnly = PuzzlePickerCriteria::fromRequest(new Request($shelf->queryParametersWithoutThis), true, true);
        self::assertSame([], $shelfOnly->collectionIds);
        self::assertSame(PuzzlePickerSource::Mine, $shelfOnly->source);
        self::assertSame('puzzle_picker.chips.source.mine', $shelfOnly->activeFilters()[0]->translationKey);
    }

    // ---------------------------------------------------------------------------------------------
    // Presets
    // ---------------------------------------------------------------------------------------------

    public function testActivePresetIsTheOneWhoseFiltersEqualTheCriteria(): void
    {
        $member = static fn (array $query): PuzzlePickerCriteria => PuzzlePickerCriteria::fromRequest(new Request($query), true, true, true);

        foreach (PuzzlePickerPreset::cases() as $preset) {
            self::assertSame($preset, $member($preset->queryParams())->activePreset(), $preset->value);
            self::assertSame($preset, $member($preset->queryParams() + ['seed' => 'abcd1234'])->activePreset(), 'The seed does not count');
        }

        self::assertSame(PuzzlePickerPreset::SurpriseMe, $member([])->activePreset(), 'The bare default is "surprise me from my shelf"');
        self::assertNull($member(['source' => 'any'])->activePreset());
        self::assertNull($member(['solved' => 'never', 'pieces' => ['1000']])->activePreset(), 'A superset of a preset is not the preset');
        self::assertNull($member(['pieces' => ['500'], 'solved' => 'never'])->activePreset(), 'A subset of a preset is not the preset');

        // A player without predictions never has "Beat my record" active - the stripped
        // criteria would otherwise look like plain "solved before"
        $nonMember = PuzzlePickerCriteria::fromRequest(new Request(PuzzlePickerPreset::BeatMyRecord->queryParams()), true);
        self::assertNull($nonMember->activePreset());
        self::assertSame(PuzzlePickerPreset::SomethingNew, PuzzlePickerCriteria::fromRequest(new Request(PuzzlePickerPreset::SomethingNew->queryParams()), true)->activePreset(), 'Free presets work for non-members');
        self::assertSame(PuzzlePickerPreset::RatingGrind, PuzzlePickerCriteria::fromRequest(new Request(PuzzlePickerPreset::RatingGrind->queryParams()), true)->activePreset());

        self::assertNull(PuzzlePickerCriteria::fromRequest(new Request(), false)->activePreset(), 'Guests get no presets');
        self::assertTrue(PuzzlePickerPreset::BeatMyRecord->requiresPredictions());
        self::assertFalse(PuzzlePickerPreset::RatingGrind->requiresPredictions());
    }

    public function testPresetsAreBuiltFromFreeFilters(): void
    {
        self::assertSame(['source' => 'mine'], PuzzlePickerPreset::SurpriseMe->queryParams());
        self::assertSame(['source' => 'mine', 'solved' => 'never'], PuzzlePickerPreset::SomethingNew->queryParams());
        self::assertSame(['predicted_max' => '60'], PuzzlePickerPreset::QuickOne->queryParams());
        self::assertSame(['since' => '6', 'since_unit' => 'm', 'since_require_solved' => '1'], PuzzlePickerPreset::DustOffTheShelf->queryParams());
        self::assertSame(['pieces' => ['500'], 'solved' => 'never', 'community' => 'rated'], PuzzlePickerPreset::RatingGrind->queryParams());
        self::assertSame(['solved' => 'before', 'gap' => 'slower', 'order' => 'gap_slower'], PuzzlePickerPreset::BeatMyRecord->queryParams());
    }
}
