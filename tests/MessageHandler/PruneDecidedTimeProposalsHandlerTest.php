<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\PruneDecidedTimeProposals;
use SpeedPuzzling\Web\MessageHandler\PruneDecidedTimeProposalsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PruneDecidedTimeProposalsHandlerTest extends KernelTestCase
{
    public function testDeletesOldDecidedProposalsAndKeepsRecentAndPendingOnes(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);

        $now = TestClock::now();
        $longAgo = $now->modify('-200 days');

        $oldRejected = $this->insert($connection, 'rejected', $longAgo, $now->modify('-91 days'));
        $oldConfirmed = $this->insert($connection, 'confirmed', $longAgo, $now->modify('-120 days'));
        // A decided row without a decision time is aged by its receipt
        $oldWithoutDecisionTime = $this->insert($connection, 'superseded', $longAgo, null);
        // Received long ago, decided recently - the decision counts
        $recentDecided = $this->insert($connection, 'withdrawn', $longAgo, $now->modify('-2 days'));
        $recentWithoutDecisionTime = $this->insert($connection, 'superseded', $now->modify('-3 days'), null);
        $oldPending = $this->insert($connection, 'pending', $longAgo, null);

        $deleted = $container->get(PruneDecidedTimeProposalsHandler::class)(new PruneDecidedTimeProposals(90));

        self::assertSame(3, $deleted);
        self::assertFalse($this->exists($connection, $oldRejected));
        self::assertFalse($this->exists($connection, $oldConfirmed));
        self::assertFalse($this->exists($connection, $oldWithoutDecisionTime));
        self::assertTrue($this->exists($connection, $recentDecided));
        self::assertTrue($this->exists($connection, $recentWithoutDecisionTime));
        self::assertTrue($this->exists($connection, $oldPending));
    }

    private function insert(Connection $connection, string $status, DateTimeImmutable $receivedAt, null|DateTimeImmutable $decidedAt): string
    {
        $id = Uuid::uuid7()->toString();
        $receivedAtMs = $receivedAt->getTimestamp() * 1000;

        $connection->insert('competition_time_proposal', [
            'id' => $id,
            'round_id' => OfficialResultsFixture::ROUND_FINAL,
            'participant_round_id' => OfficialResultsFixture::ENTRY_FINAL_ANNA,
            'proposed_by_participant_id' => OfficialResultsFixture::PARTICIPANT_ANNA,
            'status' => $status,
            'decided_at' => $decidedAt?->format('Y-m-d H:i:s'),
            'tapped_at_ms' => $receivedAtMs,
            'received_at_ms' => $receivedAtMs,
            'started_at_ms' => $receivedAtMs - 3_600_000,
            'proposed_seconds' => 3600,
            'receipt_seconds' => 3600,
            'late_by_ms' => 0,
            'clock_changed' => false,
            'clock_uncertain' => false,
            'queued_offline' => false,
        ], [
            'clock_changed' => ParameterType::BOOLEAN,
            'clock_uncertain' => ParameterType::BOOLEAN,
            'queued_offline' => ParameterType::BOOLEAN,
        ]);

        return $id;
    }

    private function exists(Connection $connection, string $id): bool
    {
        return $connection->fetchOne('SELECT 1 FROM competition_time_proposal WHERE id = :id', ['id' => $id]) !== false;
    }
}
