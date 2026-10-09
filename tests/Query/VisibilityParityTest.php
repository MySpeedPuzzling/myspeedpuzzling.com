<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Query\IsOrganizationPubliclyVisible;
use SpeedPuzzling\Web\Query\IsSeriesPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The PHP mirrors of the three visibility rules (Competition / CompetitionSeries / Organization::isPubliclyVisible(),
 * used by handlers before the flush) say exactly what the SQL fragments say - for every row of the fixtures, drafts,
 * pending and rejected ones among them (docs/features/organizations/implementation-plan.md 1.5).
 */
final class VisibilityParityTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCompetitions(): void
    {
        $check = self::getContainer()->get(IsCompetitionPubliclyVisible::class);
        $repository = self::getContainer()->get(CompetitionRepository::class);

        $this->assertMeaningful('SELECT c.id FROM competition c LEFT JOIN competition_series cs ON cs.id = c.series_id WHERE c.is_draft OR COALESCE(cs.is_draft, false)');

        foreach ($this->ids('competition') as $id) {
            self::assertSame($check->check($id), $repository->get($id)->isPubliclyVisible(), 'competition ' . $id);
        }
    }

    public function testSeries(): void
    {
        $check = self::getContainer()->get(IsSeriesPubliclyVisible::class);
        $repository = self::getContainer()->get(CompetitionSeriesRepository::class);

        $this->assertMeaningful('SELECT id FROM competition_series WHERE is_draft');

        foreach ($this->ids('competition_series') as $id) {
            self::assertSame($check->check($id), $repository->get($id)->isPubliclyVisible(), 'series ' . $id);
        }
    }

    public function testOrganizations(): void
    {
        $check = self::getContainer()->get(IsOrganizationPubliclyVisible::class);
        $repository = self::getContainer()->get(OrganizationRepository::class);

        $this->assertMeaningful('SELECT id FROM organization WHERE is_draft');

        foreach ($this->ids('organization') as $id) {
            self::assertSame($check->check($id), $repository->get($id)->isPubliclyVisible(), 'organization ' . $id);
        }
    }

    /**
     * @return list<string>
     */
    private function ids(string $table): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn("SELECT id FROM {$table} ORDER BY id");

        self::assertNotEmpty($ids);

        return $ids;
    }

    /**
     * Drafts are among the rows - otherwise the parity says nothing about them
     */
    private function assertMeaningful(string $draftRowsQuery): void
    {
        self::assertNotEmpty($this->database->fetchFirstColumn($draftRowsQuery), 'the fixtures hold no draft - the parity would prove nothing about drafts');
    }
}
