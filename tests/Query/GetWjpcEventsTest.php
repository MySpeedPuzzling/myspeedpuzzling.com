<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetWjpcEvents;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The WJPC hub lists the championship's one-time events - publicly visible ones only (IsCompetitionPubliclyVisible):
 * never one waiting for approval, a rejected one or a draft (docs/features/organizations/README.md "Drafts").
 */
final class GetWjpcEventsTest extends KernelTestCase
{
    private GetWjpcEvents $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetWjpcEvents::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testListsThePublicChampionships(): void
    {
        self::assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $this->ids());
    }

    public function testLeavesOutADraft(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertNotContains(CompetitionFixture::COMPETITION_WJPC_2024, $this->ids());
    }

    public function testLeavesOutARejectedOne(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        self::assertNotContains(CompetitionFixture::COMPETITION_WJPC_2024, $this->ids());
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        return array_map(static fn (CompetitionEvent $event): string => $event->id, array_values($this->query->allEditions()));
    }
}
