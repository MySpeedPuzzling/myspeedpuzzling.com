<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use SpeedPuzzling\Web\Value\PuzzleSearchList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The puzzle search (header search, catalogue, "Show all N") must find the same puzzles in the same order, page by
 * page, and count them the same, whatever the tag filter is written as. SearchPuzzleBeforeTagSemiJoin keeps the
 * statements as they were with the always present tag join; every input, sort and filter here is run through both.
 *
 * Tags are linked to fixture puzzles in setUp, a puzzle carrying two of them, and the secret puzzles (hide_until in
 * the future, a secret picture) carrying one too - the tag is where a duplicate or a leak would show.
 */
final class SearchPuzzleCharacterizationTest extends KernelTestCase
{
    private const array SEARCH_TERMS = [
        null,
        '',
        'a',
        'e',
        'p',
        'wa',
        'pu',
        'puzzle',
        'Puzzle 1',
        'puzzle 10',
        'Kouzelná',
        'kouzelna zahrada',
        'zauberhafter',
        '魔法の庭',
        '1000',
        '500',
        '4005556999996',
        '04005556999996',
        '4005556123456',
        '99999',
        'RB-1000',
        'rb-500-001',
        'rb',
        'API',
        'platform hidden',
        'Hidden',
        '%',
        '_',
        "'",
        'nothing like this anywhere',
    ];

    private const array FILTERS = [
        'no filter' => [],
        'tag' => ['tag' => TagFixture::TAG_WJPC],
        'tag of the twice tagged puzzle' => ['tag' => TagFixture::TAG_NATIONAL],
        'tag with no puzzle' => ['tag' => '018d0001-0000-0000-0000-0000000000ff'],
        'brand' => ['brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER],
        'brand + tag' => ['brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 'tag' => TagFixture::TAG_WJPC],
        'pieces' => ['pieces' => '1000'],
        'pieces range + tag' => ['pieces' => '200-1000', 'tag' => TagFixture::TAG_NATIONAL],
        'difficulty tiers' => ['tiers' => [PuzzleSearchCriteria::UNRATED_DIFFICULTY, 2, 3]],
        'difficulty + tag' => ['tiers' => [PuzzleSearchCriteria::UNRATED_DIFFICULTY], 'tag' => TagFixture::TAG_WJPC],
        'my collection' => ['list' => 'collection:' . CollectionFixture::COLLECTION_STRIPE_TREFL],
        'my solved + tag' => ['list' => 'solved', 'tag' => TagFixture::TAG_WJPC],
        'my unsolved' => ['list' => 'unsolved'],
        // A blank tag matches nothing (it never was "no filter"); only null means no tag
        'empty tag' => ['tag' => ''],
        'zero tag' => ['tag' => '0'],
        'empty tag + brand' => ['brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 'tag' => ''],
        'zero tag + my list' => ['list' => 'solved', 'tag' => '0'],
    ];

    private SearchPuzzle $searchPuzzle;

    private SearchPuzzleBeforeTagSemiJoin $before;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->searchPuzzle = $container->get(SearchPuzzle::class);

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->before = new SearchPuzzleBeforeTagSemiJoin($connection, $clock);

        $links = [
            TagFixture::TAG_WJPC => [
                PuzzleFixture::PUZZLE_500_01,
                PuzzleFixture::PUZZLE_500_02,
                PuzzleFixture::PUZZLE_1000_01,
                PuzzleFixture::PUZZLE_1000_03,
                PuzzleFixture::PUZZLE_HIDDEN_IMAGE,
                CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN,
                CompetitionApiFixture::PUZZLE_HIDDEN_ENTIRELY, // secret only through its round, not hide_until
            ],
            TagFixture::TAG_NATIONAL => [
                PuzzleFixture::PUZZLE_1000_01,
                PuzzleFixture::PUZZLE_500_03,
                CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN,
            ],
            TagFixture::TAG_ONLINE => [
                PuzzleFixture::PUZZLE_1000_01,
                CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN,
            ],
        ];

        foreach ($links as $tagId => $puzzleIds) {
            foreach ($puzzleIds as $puzzleId) {
                $connection->insert('tag_puzzle', ['tag_id' => $tagId, 'puzzle_id' => $puzzleId]);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filters(): iterable
    {
        foreach (array_keys(self::FILTERS) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('filters')]
    public function testEverySearchFindsTheSamePuzzlesInTheSameOrderAndCountsThemTheSame(string $filterName): void
    {
        $filter = self::FILTERS[$filterName];
        // Every sort without a filter; with one, the default and the best match (both tiebreak chains)
        $sorts = $filter === [] ? PuzzleSearchCriteria::VALID_SORTS : ['most-solved', 'best-match', 'a-z'];

        foreach (self::SEARCH_TERMS as $term) {
            $count = $this->numberFound($this->searchPuzzle, $term, $filter);
            self::assertSame($this->numberFound($this->before, $term, $filter), $count, $this->describe($term, $filterName, 'count'));

            foreach ($sorts as $sort) {
                $all = $this->ids($this->searchPuzzle, $term, $filter, $sort, 0, 1000);
                $context = $this->describe($term, $filterName, $sort);

                self::assertSame($this->ids($this->before, $term, $filter, $sort, 0, 1000), $all, $context);
                self::assertSame(count($all), $count, $context . ': the count must match the list');
                self::assertSame(array_values(array_unique($all)), $all, $context . ': a puzzle listed twice');

                foreach ($filter === [] ? [0, 5, 20] : [0, 5] as $offset) {
                    $page = $this->ids($this->searchPuzzle, $term, $filter, $sort, $offset, 5);
                    self::assertSame($this->ids($this->before, $term, $filter, $sort, $offset, 5), $page, $context . " offset {$offset}");
                    self::assertSame(array_slice($all, $offset, 5), $page, $context . " offset {$offset} must be a slice of the whole list");
                }
            }
        }
    }

    public function testABlankTagMatchesNothing(): void
    {
        foreach (['', '0'] as $tag) {
            foreach ([$this->searchPuzzle, $this->before] as $search) {
                foreach ([null, 'puzzle', 'a'] as $term) {
                    self::assertSame([], $this->ids($search, $term, ['tag' => $tag], 'most-solved', 0, 1000));
                    self::assertSame(0, $this->numberFound($search, $term, ['tag' => $tag]));
                }
            }
        }
    }

    public function testATagThatIsNoUuidIsRefusedByTheDatabaseTheSameWay(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        foreach ([$this->before, $this->searchPuzzle] as $search) {
            foreach (['page', 'count'] as $statement) {
                $connection->beginTransaction();

                try {
                    if ($statement === 'page') {
                        $this->ids($search, 'puzzle', ['tag' => 'abc'], 'most-solved', 0, 5);
                    } else {
                        $this->numberFound($search, 'puzzle', ['tag' => 'abc']);
                    }

                    self::fail($search::class . ' ' . $statement . ' accepted a tag that is no uuid');
                } catch (DriverException $exception) {
                    self::assertStringContainsString('uuid', $exception->getMessage());
                } finally {
                    $connection->rollBack();
                }
            }
        }
    }

    public function testAPuzzleWithSeveralTagsIsListedAndCountedOnce(): void
    {
        foreach ([null, TagFixture::TAG_WJPC, TagFixture::TAG_NATIONAL, TagFixture::TAG_ONLINE] as $tag) {
            $filter = $tag === null ? [] : ['tag' => $tag];
            $ids = $this->ids($this->searchPuzzle, 'Puzzle 6', $filter, 'most-solved', 0, 1000);

            self::assertSame([PuzzleFixture::PUZZLE_1000_01], array_values(array_filter($ids, static fn (string $id): bool => $id === PuzzleFixture::PUZZLE_1000_01)));
            self::assertSame(count($ids), $this->numberFound($this->searchPuzzle, 'Puzzle 6', $filter));
        }
    }

    public function testASecretPuzzleWithATagIsFoundNeitherByItsNameNorByItsTag(): void
    {
        foreach ([TagFixture::TAG_WJPC, TagFixture::TAG_NATIONAL] as $tag) {
            foreach ([null, 'API Platform Hidden', 'platform', 'API'] as $term) {
                $ids = $this->ids($this->searchPuzzle, $term, ['tag' => $tag], 'most-solved', 0, 1000);

                self::assertNotContains(CompetitionApiFixture::PUZZLE_PLATFORM_HIDDEN, $ids);
                self::assertSame(count($ids), $this->numberFound($this->searchPuzzle, $term, ['tag' => $tag]));
            }
        }

        self::assertSame(0, $this->numberFound($this->searchPuzzle, 'API Platform Hidden', ['tag' => TagFixture::TAG_NATIONAL]));
    }

    public function testAPuzzleWithASecretPictureIsListedByItsTagWithoutThePicture(): void
    {
        $puzzles = $this->searchPuzzle->byUserInput(null, null, PiecesRange::any(), TagFixture::TAG_ONLINE, 'a-z', 0, 100);
        $ids = array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);

        self::assertSame([CompetitionApiFixture::PUZZLE_PLATFORM_IMAGE_HIDDEN, PuzzleFixture::PUZZLE_1000_01], $ids);
        self::assertNull($puzzles[0]->puzzleImage);
    }

    /**
     * @param array{tag?: string, brand?: string, pieces?: string, tiers?: list<int>, list?: string} $filter
     */
    private function numberFound(SearchPuzzle|SearchPuzzleBeforeTagSemiJoin $search, null|string $term, array $filter): int
    {
        $list = PuzzleSearchList::tryFrom($filter['list'] ?? null);

        return $search->countByUserInput(
            $filter['brand'] ?? null,
            $term,
            PiecesRange::parse($filter['pieces'] ?? null) ?? PiecesRange::any(),
            $filter['tag'] ?? null,
            $filter['tiers'] ?? [],
            $list,
            $list === null ? null : PlayerFixture::PLAYER_WITH_STRIPE,
        );
    }

    /**
     * @param array{tag?: string, brand?: string, pieces?: string, tiers?: list<int>, list?: string} $filter
     *
     * @return list<string>
     */
    private function ids(SearchPuzzle|SearchPuzzleBeforeTagSemiJoin $search, null|string $term, array $filter, string $sort, int $offset, int $limit): array
    {
        $list = PuzzleSearchList::tryFrom($filter['list'] ?? null);

        $puzzles = $search->byUserInput(
            $filter['brand'] ?? null,
            $term,
            PiecesRange::parse($filter['pieces'] ?? null) ?? PiecesRange::any(),
            $filter['tag'] ?? null,
            $sort,
            $offset,
            $limit,
            $filter['tiers'] ?? [],
            $list,
            $list === null ? null : PlayerFixture::PLAYER_WITH_STRIPE,
        );

        return array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);
    }

    private function describe(null|string $term, string $filterName, string $what): string
    {
        return sprintf('"%s" with %s, %s', $term ?? 'null', $filterName, $what);
    }
}
