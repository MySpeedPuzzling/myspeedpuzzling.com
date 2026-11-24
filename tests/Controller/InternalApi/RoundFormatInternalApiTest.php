<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md "Round format, labels and Unboxed" (spws-wizard.md package D): a back-to-back block set
 * up without a browser - the puzzles' order and labels (ArrangeRoundPuzzles), "Solve all 2" (the round PATCH's
 * puzzleFormat), Unboxed per puzzle (ChangeRoundUnboxed, a held picture let out only with a yes) - and the reads.
 */
final class RoundFormatInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testABackToBackBlockIsSetUpAndRead(): void
    {
        $browser = self::createClient();
        $roundId = $this->createBlock($browser);

        // Order and labels: Challenge 3 = the second puzzle given, Challenge 4 the first
        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/arrangement', [
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_03, PuzzleFixture::PUZZLE_500_02],
            'labels' => [PuzzleFixture::PUZZLE_500_03 => 'Challenge 3', PuzzleFixture::PUZZLE_500_02 => 'Challenge 4'],
        ]);
        self::assertResponseIsSuccessful();
        $byPosition = array_column(self::list($round['puzzles']), null, 'position');
        self::assertSame([PuzzleFixture::PUZZLE_500_03, 'Challenge 3'], [$byPosition[1]['puzzleId'], $byPosition[1]['label']]);
        self::assertSame([PuzzleFixture::PUZZLE_500_02, 'Challenge 4'], [$byPosition[2]['puzzleId'], $byPosition[2]['effectiveLabel']]);

        // "Solve all 2" = pick 2 of 2
        $round = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'pick', 'puzzlesToSolve' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['pick', 2], [$round['puzzleFormat'], $round['puzzlesToSolve']]);

        // Only Challenge 4 unboxed
        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => false, 'unboxedPuzzleIds' => [PuzzleFixture::PUZZLE_500_02]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($round['unboxed']);
        $unboxed = array_column(self::list($round['puzzles']), 'unboxed', 'puzzleId');
        self::assertSame([PuzzleFixture::PUZZLE_500_02 => true, PuzzleFixture::PUZZLE_500_03 => false], [PuzzleFixture::PUZZLE_500_02 => $unboxed[PuzzleFixture::PUZZLE_500_02], PuzzleFixture::PUZZLE_500_03 => $unboxed[PuzzleFixture::PUZZLE_500_03]]);

        // Boxing it again before the results are published lets its picture out - only with a yes
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => false, 'unboxedPuzzleIds' => []]);
        self::assertResponseStatusCodeSame(409);
        $refusal = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($refusal);
        self::assertSame('pictures_would_show', $refusal['error']);
        self::assertSame([PuzzleFixture::PUZZLE_500_02], array_column(self::list($refusal['puzzles']), 'puzzleId'));
        self::assertTrue($this->ownMark($roundId, PuzzleFixture::PUZZLE_500_02));

        // The whole round unboxed clears the own marks (the page's rule) - nothing comes out, no yes needed
        $round = self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($round['unboxed']);
        self::assertFalse($this->ownMark($roundId, PuzzleFixture::PUZZLE_500_02));

        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => false, 'confirmReveal' => true]);
        self::assertResponseIsSuccessful();

        // Back to one result for the round
        $round = self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'total']);
        self::assertSame(['total', null], [$round['puzzleFormat'], $round['puzzlesToSolve']]);
        // No table names - no key
        self::assertArrayNotHasKey('tableLabels', $round);
    }

    public function testTableNamesAreReadAsACountAndTheirEnds(): void
    {
        $browser = self::createClient();
        $roundId = $this->createBlock($browser);

        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/table-labels', ['generate' => ['letters' => 'A-B', 'numbers' => '1-2', 'seats' => 2, 'seatStyle' => 'side']]);
        self::assertResponseIsSuccessful();

        $competition = self::callInternalApi($browser, 'GET', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024);
        $round = array_column(self::list($competition['rounds']), null, 'roundId')[$roundId];
        self::assertSame(['count' => 8, 'first' => 'A1 L', 'last' => 'B2 R'], $round['tableLabels']);
    }

    public function testRefusalsWriteNothing(): void
    {
        $browser = self::createClient();
        $roundId = $this->createBlock($browser);

        // K beyond the round's puzzles - the round form's rule, a 409
        self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'pick', 'puzzlesToSolve' => 3]);
        self::assertResponseStatusCodeSame(409);
        self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'pick']);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'every']);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PATCH', '/internal-api/rounds/' . $roundId, ['puzzleFormat' => 'total', 'puzzlesToSolve' => 2]);
        self::assertResponseStatusCodeSame(400);

        // A label too long, a puzzle of another round, a puzzle twice, nothing to do
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/arrangement', ['labels' => [PuzzleFixture::PUZZLE_500_02 => str_repeat('x', 41)]]);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/arrangement', ['labels' => [PuzzleFixture::PUZZLE_1000_01 => 'Challenge 1']]);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/arrangement', ['puzzleIds' => [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_02]]);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/arrangement', []);
        self::assertResponseStatusCodeSame(400);

        // Unboxed: required, a foreign puzzle, a list with the whole round
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', []);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => false, 'unboxedPuzzleIds' => [PuzzleFixture::PUZZLE_1000_01]]);
        self::assertResponseStatusCodeSame(400);
        self::callInternalApi($browser, 'PUT', '/internal-api/rounds/' . $roundId . '/unboxed', ['unboxed' => true, 'unboxedPuzzleIds' => [PuzzleFixture::PUZZLE_500_02]]);
        self::assertResponseStatusCodeSame(400);

        $stored = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT cr.puzzle_format, cr.unboxed, COUNT(crp.id) FILTER (WHERE crp.label IS NOT NULL OR crp.unboxed) AS marked FROM competition_round cr JOIN competition_round_puzzle crp ON crp.round_id = cr.id WHERE cr.id = :id GROUP BY cr.id',
            ['id' => $roundId],
        );
        self::assertSame(['puzzle_format' => 'total', 'unboxed' => false, 'marked' => 0], $stored);
    }

    private function createBlock(KernelBrowser $browser): string
    {
        $round = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/rounds', [
            'name' => 'Challenges 3 + 4',
            'category' => 'team',
            'startsAt' => '2027-06-03T15:00:00Z',
            'minutesLimit' => 150,
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($round['roundId']);

        return $round['roundId'];
    }

    private function ownMark(string $roundId, string $puzzleId): bool
    {
        return (bool) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT unboxed FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id = :puzzle',
            ['round' => $roundId, 'puzzle' => $puzzleId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
