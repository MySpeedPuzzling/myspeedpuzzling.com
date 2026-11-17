<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioSignals;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Update;

/**
 * Secret puzzles by exclusion (analytics-studio.md §5, R1): the studio knows a round's puzzles only through
 * RoundPuzzlesVisibleNow, and every exclusion list is built from the VISIBLE puzzles only - so nothing can be learnt by
 * subtraction. The swap test: Group B of the "Results Cup" with a visible slot (PUZZLE_1000_01) and a hidden one
 * (manual reveal, not made) holding puzzle X, then puzzle Y instead - X and Y of the same brand and piece count as the
 * visible puzzle; Hugo (PLAYER_REGULAR, an entrant) solved X before the round and logged a time on X after its start.
 * The state, analytics and entrant answers are byte-identical (serverNowMs aside). After the reveal X is there.
 */
final class AnalyticsStudioSecretPuzzleTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string HIDDEN_SLOT = '018d0020-0000-0000-0000-00000000aaa2';
    private const string X = PuzzleFixture::PUZZLE_1000_02;
    private const string Y = PuzzleFixture::PUZZLE_1000_03;

    public function testTheAnswersAreTheSameWhicheverPuzzleIsHidden(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $visible = PuzzleFixture::PUZZLE_1000_01;
        $database->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle, position = 1 WHERE round_id = :round', ['puzzle' => $visible, 'round' => F::ROUND_GROUP_B]);
        $database->executeStatement(
            'UPDATE puzzle SET manufacturer_id = (SELECT manufacturer_id FROM puzzle WHERE id = :visible), pieces_count = 1000 WHERE id IN (:x, :y)',
            ['visible' => $visible, 'x' => self::X, 'y' => self::Y],
        );
        $database->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, hide_mode, reveal_mode, reveal_at, hides_everywhere, position)
             VALUES (:id, :round, :puzzle, true, 'entirely', 'manual', NULL, false, 2)",
            ['id' => self::HIDDEN_SLOT, 'round' => F::ROUND_GROUP_B, 'puzzle' => self::X],
        );
        $this->seedStudioTime(PlayerFixture::PLAYER_REGULAR, self::X, 2900, '2026-09-01 10:00:00');
        $this->seedStudioTime(PlayerFixture::PLAYER_REGULAR, self::X, 2500, '2026-10-10 10:00:00');

        $withX = $this->answers($browser);
        $xName = $this->studioString('SELECT name FROM puzzle WHERE id = :id', ['id' => self::X]);
        foreach (['state', 'analytics'] as $answer) {
            foreach ([self::X, $xName] as $secret) {
                self::assertStringNotContainsString($secret, $withX[$answer], "{$answer} carries the hidden puzzle");
            }
        }
        self::assertStringContainsString('"label":"B"', $withX['state'], 'no canary: the hidden slot is not listed by its label');
        self::assertStringContainsString('"puzzle":null', $withX['state']);

        $database->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle WHERE id = :id', ['puzzle' => self::Y, 'id' => self::HIDDEN_SLOT]);
        $withY = $this->answers($browser);

        self::assertSame($withX, $withY, 'an answer tells which puzzle is hidden');

        // The reveal: X is there - its slot, its statistics, Hugo's solve of it before the round
        $database->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle, hide_until_round_starts = false WHERE id = :id', ['puzzle' => self::X, 'id' => self::HIDDEN_SLOT]);
        $revealed = $this->answers($browser);
        self::assertStringContainsString($xName, $revealed['state']);
        $analytics = json_decode($revealed['analytics'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($analytics);
        self::assertArrayHasKey(self::HIDDEN_SLOT, self::digArray($analytics, 'puzzles'));
        self::assertGreaterThanOrEqual(1, self::dig($analytics, 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'perPuzzle', self::HIDDEN_SLOT, 'solves'));
        self::assertNotSame($withX['analytics'], $revealed['analytics']);
    }

    /**
     * The studio's public signal (R21) of a watched round is the same whichever puzzle is hidden - and names neither.
     */
    public function testTheSignalIsTheSameWhicheverPuzzleIsHidden(): void
    {
        self::bootKernel();
        $database = $this->studioDatabase();
        $database->executeStatement('UPDATE competition_round_puzzle SET position = 1 WHERE round_id = :round', ['round' => F::ROUND_GROUP_B]);
        $database->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, hide_mode, reveal_mode, reveal_at, hides_everywhere, position)
             VALUES (:id, :round, :puzzle, true, 'entirely', 'manual', NULL, false, 2)",
            ['id' => self::HIDDEN_SLOT, 'round' => F::ROUND_GROUP_B, 'puzzle' => self::X],
        );
        $this->studioWatch()->markWatched(F::ROUND_GROUP_B);
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);
        $liveUpdates = self::getContainer()->get(OfficialResultsLiveUpdates::class);

        $signal = function () use ($hub, $liveUpdates): string {
            $hub->reset();
            $liveUpdates->puzzlesRevealed(F::ROUND_GROUP_B);
            $signals = array_values(array_filter($hub->getPublishedUpdates(), static fn (Update $update): bool => $update->getTopics() === [AnalyticsStudioSignals::topic(F::ROUND_GROUP_B)]));
            self::assertCount(1, $signals);

            return $signals[0]->getData();
        };

        $withX = $signal();
        $database->executeStatement('UPDATE competition_round_puzzle SET puzzle_id = :puzzle WHERE id = :id', ['puzzle' => self::Y, 'id' => self::HIDDEN_SLOT]);
        self::assertSame($withX, $signal());
        foreach ([self::X, self::Y, self::HIDDEN_SLOT] as $secret) {
            self::assertStringNotContainsString($secret, $withX);
        }
    }

    /**
     * @return array{state: string, analytics: string, entrant: string, predictions: string}
     */
    private function answers(KernelBrowser $browser): array
    {
        $this->clearAnalyticsStudioCache();
        $base = '/analytics-studio/' . F::ROUND_GROUP_B;
        $answers = [];

        foreach (['state' => '/state', 'analytics' => '/analytics', 'entrant' => '/entrant/' . F::ENTRY_B_HUGO, 'predictions' => '/predictions?entries=' . F::ENTRY_B_HUGO] as $name => $path) {
            $browser->request('GET', $base . $path);
            self::assertResponseIsSuccessful($name);
            $answers[$name] = (string) preg_replace('/"serverNowMs":\d+,/', '', (string) $browser->getResponse()->getContent());
        }

        return $answers;
    }
}
