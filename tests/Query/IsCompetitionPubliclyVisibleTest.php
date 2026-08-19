<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IsCompetitionPubliclyVisibleTest extends KernelTestCase
{
    private IsCompetitionPubliclyVisible $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(IsCompetitionPubliclyVisible::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testApprovedStandaloneCompetitionIsVisible(): void
    {
        self::assertTrue($this->query->check(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testEditionsOfApprovedSeriesAreVisibleRegardlessOfTheirOwnApprovedAt(): void
    {
        // Editions never carry their own approval — the series approval governs them.
        self::assertTrue($this->query->check(CompetitionSeriesFixture::EDITION_EJJ_68));
        self::assertTrue($this->query->check(CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    public function testUnapprovedStandaloneCompetitionIsNotVisible(): void
    {
        self::assertFalse($this->query->check(CompetitionFixture::COMPETITION_UNAPPROVED));
    }

    public function testRejectedStandaloneCompetitionIsNotVisibleEvenWhenApproved(): void
    {
        // approve() and reject() do not clear each other — rejected must veto a stale approval.
        self::assertFalse($this->query->check(CompetitionApiFixture::COMPETITION_API_REJECTED));
    }

    public function testEditionOfRejectedSeriesIsNotVisible(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_series SET rejected_at = now() WHERE id = :seriesId',
            ['seriesId' => CompetitionSeriesFixture::SERIES_EJJ],
        );

        self::assertFalse($this->query->check(CompetitionSeriesFixture::EDITION_EJJ_68));
    }

    public function testEditionOfUnapprovedSeriesIsNotVisible(): void
    {
        self::assertFalse($this->query->check(CompetitionSeriesFixture::EDITION_UNAPPROVED_1));
    }

    public function testUnknownCompetitionIsNotVisible(): void
    {
        self::assertFalse($this->query->check('00000000-0000-0000-0000-000000000000'));
    }

    public function testInvalidUuidIsNotVisible(): void
    {
        self::assertFalse($this->query->check('not-a-uuid'));
    }
}
