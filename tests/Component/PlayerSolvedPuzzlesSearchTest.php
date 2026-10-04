<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The search on a player's results reads every name of a puzzle and its brand code, folded like the typed text
 * (SearchText): PLAYER_REGULAR solved PUZZLE_1000_02 ("Puzzle 7", Czech "Kouzelná zahrada", German "Zauberhafter
 * Garten"), PUZZLE_300 ("Puzzle 11", "Kouzelna zahrada") and PUZZLE_500_01 ("Puzzle 1", brand code RB-500-001).
 */
final class PlayerSolvedPuzzlesSearchTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /**
     * @param list<string> $expectedPuzzleIds
     */
    #[DataProvider('provideSearches')]
    public function testSearchFindsEveryNameAndTheBrandCode(string $search, array $expectedPuzzleIds): void
    {
        $component = $this->createLiveComponent('PlayerSolvedPuzzles', ['playerId' => PlayerFixture::PLAYER_REGULAR], self::createClient());
        $component->setRouteLocale('en');
        $component->set('searchQuery', $search);

        $results = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $results);
        $results->populate();

        $puzzleIds = [];

        foreach ($results->soloSolvedPuzzles as $group) {
            foreach ($group as $solvedPuzzle) {
                $puzzleIds[] = $solvedPuzzle->puzzleId;
            }
        }

        $puzzleIds = array_values(array_unique($puzzleIds));
        sort($puzzleIds);
        sort($expectedPuzzleIds);

        self::assertSame($expectedPuzzleIds, $puzzleIds);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSearches(): iterable
    {
        yield 'the main title' => ['puzzle 7', [PuzzleFixture::PUZZLE_1000_02]];
        // PUZZLE_300 ("Puzzle 11") carries the same name without accents
        yield 'the Czech name without accents' => ['KOUZELNA zahrada', [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300]];
        yield 'the Czech name with accents' => ['kouzelná', [PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300]];
        yield 'the German name' => ['zauberhafter', [PuzzleFixture::PUZZLE_1000_02]];
        yield 'full-width letters' => ['Ｇａｒｔｅｎ', [PuzzleFixture::PUZZLE_1000_02]];
        yield 'the brand code' => ['rb-500', [PuzzleFixture::PUZZLE_500_01]];
        yield 'words of two names' => ['zahrada zauberhafter', []];
    }
}
