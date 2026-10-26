<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Progression;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetProgressionDrift;
use SpeedPuzzling\Web\Services\Progression\ProgressionSafetyNet;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The nightly safety net (docs/features/xp-levels/README.md §Anti-farming): every kind of drift a write path outside
 * the live wiring (SQL, a missed message) can leave behind is found by its set-based check and repaired.
 */
final class ProgressionSafetyNetTest extends KernelTestCase
{
    private Connection $database;
    private ProgressionSafetyNet $safetyNet;
    private GetProgressionDrift $getProgressionDrift;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->safetyNet = self::getContainer()->get(ProgressionSafetyNet::class);
        $this->getProgressionDrift = self::getContainer()->get(GetProgressionDrift::class);
    }

    public function testRepairBringsEverybodyToTheirRecordsAndAFollowingRunFindsNothing(): void
    {
        // Fixture results come without a ledger - every player with results is drift at first
        $first = $this->safetyNet->check(repair: true);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $first->drift->baseNotFormula, 'A result without its award is found');
        self::assertSame([], $first->failed);

        $second = $this->safetyNet->check(repair: false);
        self::assertTrue($second->drift->isClean(), (string) json_encode($second->drift->counts()));
    }

    public function testEveryKindOfDriftIsFoundByItsCheckAndRepaired(): void
    {
        $this->safetyNet->check(repair: true);

        // A total written past the ledger
        $this->database->executeStatement('UPDATE player SET xp_total = xp_total + 50 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        // A base line that is not the formula's (the total follows it, so only the base check can see it)
        $baseEntry = $this->entryOf(PlayerFixture::PLAYER_PRIVATE, 'solve_base');
        $this->database->executeStatement('UPDATE xp_entry SET amount = amount + 5 WHERE id = :id', ['id' => $baseEntry]);
        $this->database->executeStatement('UPDATE player SET xp_total = xp_total + 5 WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);

        // A compensation line on a result that exists (an id that came back after deletion, before the fix)
        $solveId = $this->solveOf(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->insertEntry(PlayerFixture::PLAYER_WITH_FAVORITES, $solveId, 'solve_compensation', -3);

        // XP left on a deleted result
        $this->insertEntry(PlayerFixture::PLAYER_WITH_STRIPE, Uuid::uuid7()->toString(), 'solve_base', 7);

        // A result marked by SQL that nothing reconciled
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => $this->solveOf(PlayerFixture::PLAYER_ADMIN)]);

        // Achievement Points written past the achievements
        $this->database->executeStatement('UPDATE player SET achievement_points = achievement_points + 25 WHERE id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]);

        $drift = $this->getProgressionDrift->find();
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $drift->xpTotalNotLedger);
        self::assertContains(PlayerFixture::PLAYER_PRIVATE, $drift->baseNotFormula);
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $drift->xpTotalNotLedger);
        self::assertContains(PlayerFixture::PLAYER_WITH_FAVORITES, $drift->staleCompensation);
        self::assertContains(PlayerFixture::PLAYER_WITH_STRIPE, $drift->deletedSolveNotCompensated);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $drift->xpWithoutParticipation);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $drift->achievementPointsNotTiers);

        $run = $this->safetyNet->check(repair: true);
        self::assertSame([], $run->failed);
        self::assertGreaterThanOrEqual(6, $run->repaired);

        $after = $this->getProgressionDrift->find();
        self::assertTrue($after->isClean(), (string) json_encode($after->counts()));
    }

    private function entryOf(string $playerId, string $reason): string
    {
        $id = $this->database->fetchOne(
            'SELECT e.id FROM xp_entry e JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id WHERE e.player_id = :playerId AND e.reason = :reason LIMIT 1',
            ['playerId' => $playerId, 'reason' => $reason],
        );
        self::assertIsString($id);

        return $id;
    }

    private function solveOf(string $playerId): string
    {
        $id = $this->database->fetchOne(
            'SELECT e.solving_time_id FROM xp_entry e JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id WHERE e.player_id = :playerId AND pst.player_id = :playerId LIMIT 1',
            ['playerId' => $playerId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function insertEntry(string $playerId, string $solveId, string $reason, int $amount): void
    {
        $this->database->executeStatement(
            'INSERT INTO xp_entry (id, player_id, amount, reason, in_weekly_delta, earned_at, created_at, solving_time_id)
             VALUES (:id, :playerId, :amount, :reason, true, ' . TestClock::SQL_NOW . ', ' . TestClock::SQL_NOW . ', :solveId)',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'amount' => $amount, 'reason' => $reason, 'solveId' => $solveId],
        );
        $this->database->executeStatement('UPDATE player SET xp_total = xp_total + :amount WHERE id = :id', ['amount' => $amount, 'id' => $playerId]);
    }
}
