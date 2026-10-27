<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates\GateV2Numbers;
use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Services\Insights\ResolveRadar;
use SpeedPuzzling\Web\Services\Insights\ShelfPicks;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\ShelfPickSource;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The re-solve radar and the shelf picks (docs/features/insights/computation.md §4c.4, §4c.7): predictions in bulk, the
 * shelf rules (collections and borrowed, not lent out, nothing done with the puzzle yet), secret puzzles never
 * suggested, members only for the picks - and the statement budgets.
 */
final class ResolveRadarAndShelfPicksTest extends KernelTestCase
{
    /** A piece count no fixture has: no improvement ratios, no other solvers but the seeded ones */
    private const int PIECES = 777;

    private Connection $connection;
    private DateTimeImmutable $now;
    private string $player;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->now = TestClock::now()->modify('today 12:00:00');
        self::getContainer()->set(ClockInterface::class, new MockClock($this->now));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->player = $this->newPlayer('radar');
        $this->connection->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at) VALUES (gen_random_uuid(), :player, :pieces, 3000, 10, 'direct', " . TestClock::SQL_NOW . ")",
            ['player' => $this->player, 'pieces' => self::PIECES],
        );
    }

    public function testTheRadarListsOldPuzzlesLikelyClearlyFasterNow(): void
    {
        $old = $this->puzzle('Old');
        $recent = $this->puzzle('Recent');
        $secret = $this->puzzle('Secret', hidden: true);
        $big = $this->puzzle('Big', pieces: 1777);
        $doneTogether = $this->puzzle('Done together lately');
        $flaggedLately = $this->puzzle('Flagged lately');

        $this->solve($this->player, $old, 4000, 400);
        $this->solve($this->player, $recent, 4000, 100);
        $this->solve($this->player, $secret, 4000, 400);
        $this->solve($this->player, $big, 9000, 500);
        $this->shelve($this->player, $old);

        // Solved solo long ago, but done since: in a pair a month ago, or solo with a result flagged for verification
        $this->solve($this->player, $doneTogether, 4000, 400);
        $this->solve($this->player, $flaggedLately, 4000, 400);
        $this->solve($this->player, $flaggedLately, 3000, 20, firstAttempt: false);
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle AND seconds_to_solve = 3000',
            ['player' => $this->player, 'puzzle' => $flaggedLately],
        );
        $partner = $this->newPlayer('partner');
        $team = Uuid::uuid7()->toString();
        $this->connection->executeStatement('INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, :key, 2, ' . TestClock::SQL_NOW . ')', ['id' => $team, 'key' => sha1($team)]);

        foreach ([$this->player, $partner] as $position => $member) {
            $this->connection->executeStatement(
                'INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:id, :team, :key, :player, NULL, :position)',
                ['id' => Uuid::uuid7()->toString(), 'team' => $team, 'key' => $member, 'player' => $member, 'position' => $position],
            );
        }

        $this->connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count, puzzling_team_id)
            VALUES (:id, :tracker, :puzzle, 2500, :at, :at, true, false, 'duo', 2, :team)",
            ['id' => Uuid::uuid7()->toString(), 'tracker' => $partner, 'puzzle' => $doneTogether, 'team' => $team, 'at' => $this->now->modify('-30 days')->format('Y-m-d H:i:s')],
        );

        $radar = $this->radar()->build($this->player);
        $ids = array_map(static fn ($candidate): string => $candidate->puzzle->puzzleId, $radar->candidates);

        self::assertContains($old, $ids);
        self::assertContains($big, $ids);
        self::assertNotContains($recent, $ids, 'Solved less than 12 months ago');
        self::assertNotContains($secret, $ids);
        self::assertNotContains($doneTogether, $ids, 'Done in a pair a month ago, tracked by the partner');
        self::assertNotContains($flaggedLately, $ids, 'Done solo lately, whatever the verification says');
        self::assertSame(1, $radar->onShelfCount);

        /** @var GetPlayerPredictions $predictions */
        $predictions = self::getContainer()->get(GetPlayerPredictions::class);
        $predicted = $predictions->forAllSolvedPuzzles($this->player);

        foreach ($radar->candidates as $candidate) {
            self::assertSame($predicted[$candidate->puzzle->puzzleId]->predictedSeconds, $candidate->predictedSeconds);
            self::assertEqualsWithDelta(1 - $candidate->predictedSeconds / $candidate->bestSeconds, $candidate->likelyGain, 1e-12);
            self::assertGreaterThanOrEqual(ResolveRadar::MINIMUM_GAIN, $candidate->likelyGain);
            self::assertGreaterThanOrEqual(12.0, $candidate->monthsSinceLastSolve);
        }

        $onlyBig = $this->radar()->build($this->player, PiecesRange::between(1777, 1777));
        self::assertSame([$big], array_map(static fn ($candidate): string => $candidate->puzzle->puzzleId, $onlyBig->candidates));
    }

    public function testShelfPicksForMembersOnly(): void
    {
        $others = array_map(fn (int $i): string => $this->newPlayer('solver' . $i), range(1, 6));

        $popular = $this->puzzle('Popular', difficulty: 1.0);
        $hard = $this->puzzle('Hard', difficulty: 1.4);
        $fewSolvers = $this->puzzle('Few solvers', difficulty: 1.0);
        $unrated = $this->puzzle('Unrated');
        $done = $this->puzzle('Done', difficulty: 1.0);
        $lentOut = $this->puzzle('Lent out', difficulty: 1.0);
        $borrowed = $this->puzzle('Borrowed', difficulty: 1.0);
        $secret = $this->puzzle('Secret', difficulty: 1.0, hidden: true);

        foreach ($others as $index => $other) {
            // Popular: the others take 3200-3700 s, the player is expected at 3000 → faster than all of them
            $this->solve($other, $popular, 3200 + 100 * $index, 50, firstAttempt: true);
            $this->solve($other, $hard, 3000 + 300 * $index, 50, firstAttempt: true);
        }

        $this->solve($others[0], $fewSolvers, 2000, 50, firstAttempt: true);

        foreach ([$popular, $hard, $fewSolvers, $unrated, $done, $lentOut, $secret] as $puzzle) {
            $this->shelve($this->player, $puzzle);
        }

        $this->solve($this->player, $done, 3000, 30);
        $this->connection->executeStatement(
            'INSERT INTO lent_puzzle (id, puzzle_id, owner_player_id, current_holder_player_id, lent_at) VALUES (:id, :puzzle, :owner, :holder, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'puzzle' => $lentOut, 'owner' => $this->player, 'holder' => $others[1]],
        );
        $this->connection->executeStatement(
            'INSERT INTO lent_puzzle (id, puzzle_id, owner_player_id, current_holder_player_id, lent_at) VALUES (:id, :puzzle, :owner, :holder, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'puzzle' => $borrowed, 'owner' => $others[2], 'holder' => $this->player],
        );

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');

        $holder->reset();
        $free = $this->picks()->build($this->player, isMember: false);
        self::assertTrue($free->locked);
        self::assertSame(5, $free->unsolvedOnShelf, 'Popular, hard, few solvers, unrated, borrowed');
        self::assertSame([], $free->picks);
        self::assertSame(1, $this->statements($holder));

        $holder->reset();
        $member = $this->picks()->build($this->player, isMember: true);
        self::assertSame(4, $this->statements($holder), "Shelf, estimates (GetPersonalEstimates), stored time lists, the other puzzles' times");

        self::assertFalse($member->locked);
        self::assertSame(4, $member->withExpectedTime, 'Every rated one');
        self::assertSame([$popular, $hard, $fewSolvers, $borrowed], array_map(static fn ($pick): string => $pick->puzzle->puzzleId, $member->picks));
        self::assertSame(1.0, $member->picks[0]->fasterThanShare);
        self::assertSame(3000, $member->picks[0]->expectedSeconds);
        self::assertSame(6, $member->picks[0]->solverCount);
        self::assertEqualsWithDelta(0.25, $member->picks[1]->fasterThanShare, 1e-12);
        self::assertNull($member->picks[2]->fasterThanShare, 'One solver says nothing');
        self::assertNull($member->picks[3]->fasterThanShare, 'Nobody else yet');
    }

    public function testAShelfPuzzleNobodyHasSolvedGetsItsPictureEstimateOnlyWhereTheGatePassed(): void
    {
        $unrated = $this->puzzle('Unrated');
        $this->shelve($this->player, $unrated);
        $image = 'box-' . $unrated . '.jpg';
        $fingerprint = PuzzleImageFingerprint::of($image);
        $this->connection->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $unrated]);
        $this->connection->executeStatement(
            "INSERT INTO puzzle_difficulty_estimate (puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, out_of_fold, training_size, image_fingerprint, computed_at, received_at)
            VALUES (:puzzle, 'ridge-siglip2-dinov2', '1.0.0', 0.1, -0.1, 0.35, true, 4979, :fingerprint, " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
            ['puzzle' => $unrated, 'fingerprint' => $fingerprint],
        );
        $this->connection->executeStatement(
            "INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
            VALUES (:id, :puzzle, 'artwork', '1.0.0', :fingerprint, '{\"kind\": \"flat\"}', NULL, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'puzzle' => $unrated, 'fingerprint' => $fingerprint],
        );

        // No gate record: closed - nothing to pick
        $closed = $this->picks()->build($this->player, isMember: true);
        self::assertSame(0, $closed->withExpectedTime);

        $this->connection->executeStatement(
            "INSERT INTO image_estimate_evaluation (id, evaluated_at, model_version, newer_than, measured_min_indices, groups, overall, by_k, gate_version)
            VALUES (:id, " . TestClock::SQL_NOW . ", :version, " . TestClock::SQL_NOW . " - INTERVAL '1 year', 10, CAST(:groups AS jsonb), '{}', '[]', 2)",
            ['id' => Uuid::uuid7()->toString(), 'version' => TimePredictionCalculator::MODEL_VERSION, 'groups' => json_encode([
                GateV2Numbers::passing(PiecesGroup::Odd->value, ImageKind::Flat->value, 'ridge-siglip2-dinov2 1.0.0', measuredPuzzles: 80),
            ], JSON_THROW_ON_ERROR)],
        );
        self::getContainer()->get('services_resetter')->reset();

        $open = $this->picks()->build($this->player, isMember: true);
        $picks = $open->picks;

        self::assertSame(1, $open->withExpectedTime);
        self::assertCount(1, $picks);
        self::assertSame($unrated, $picks[0]->puzzle->puzzleId);
        self::assertSame(ShelfPickSource::Image, $picks[0]->source);
        self::assertTrue($picks[0]->source->isEstimated());
        // The player's 777-piece pace × exp(0.1)
        self::assertEqualsWithDelta(3000 * exp(0.1), $picks[0]->expectedSeconds, 1.0);
    }

    public function testRadarStatements(): void
    {
        $this->solve($this->player, $this->puzzle('Old'), 4000, 400);

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->radar()->build($this->player);

        self::assertSame(4, $this->statements($holder));
    }

    private function statements(DebugDataHolder $holder): int
    {
        $queries = $holder->getData()['default'] ?? [];

        return is_array($queries) ? count($queries) : 0;
    }

    private function radar(): ResolveRadar
    {
        /** @var ResolveRadar $radar */
        $radar = self::getContainer()->get(ResolveRadar::class);

        return $radar;
    }

    private function picks(): ShelfPicks
    {
        /** @var ShelfPicks $picks */
        $picks = self::getContainer()->get(ShelfPicks::class);

        return $picks;
    }

    private function newPlayer(string $code): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO player (id, code, name, registered_at) VALUES (:id, :code, :name, ' . TestClock::SQL_NOW . ')',
            ['id' => $id, 'code' => 'shelf' . $code, 'name' => 'Shelf ' . $code],
        );

        return $id;
    }

    private function puzzle(string $name, null|float $difficulty = null, bool $hidden = false, int $pieces = self::PIECES): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until) VALUES (:id, :pieces, :name, true, true, :hideUntil)',
            ['id' => $id, 'pieces' => $pieces, 'name' => $name, 'hideUntil' => $hidden ? $this->now->modify('+1 month')->format('Y-m-d H:i:s') : null],
        );

        if ($difficulty !== null) {
            $this->connection->executeStatement(
                "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at) VALUES (:id, :score, 3, 'high', 30, " . TestClock::SQL_NOW . ")",
                ['id' => $id, 'score' => $difficulty],
            );
        }

        return $id;
    }

    private function solve(string $player, string $puzzle, int $seconds, int $daysAgo, bool $firstAttempt = true): void
    {
        $this->connection->executeStatement(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, puzzling_type, puzzlers_count)
            VALUES (:id, :player, :puzzle, :seconds, :at, :at, true, :first, 'solo', 1)",
            [
                'id' => Uuid::uuid7()->toString(),
                'player' => $player,
                'puzzle' => $puzzle,
                'seconds' => $seconds,
                'at' => $this->now->modify("-{$daysAgo} days")->format('Y-m-d H:i:s'),
                'first' => $firstAttempt ? 'true' : 'false',
            ],
        );
    }

    private function shelve(string $player, string $puzzle): void
    {
        $this->connection->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (:id, NULL, :player, :puzzle, NULL, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'player' => $player, 'puzzle' => $puzzle],
        );
    }
}
