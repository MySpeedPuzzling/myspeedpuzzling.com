<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\IsSeriesPubliclyVisible;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IsSeriesPubliclyVisibleTest extends KernelTestCase
{
    private IsSeriesPubliclyVisible $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(IsSeriesPubliclyVisible::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAPublishedApprovedSeriesIsVisible(): void
    {
        self::assertTrue($this->query->check(OrganizationFixture::SERIES_LANTERN_NIGHTS));
        self::assertTrue($this->query->check(CompetitionSeriesFixture::SERIES_EJJ));
    }

    public function testASeriesUnderADraftOrganizationIsVisible(): void
    {
        self::assertTrue($this->query->check(OrganizationFixture::SERIES_HARBOR_CLUB_MEETS));
    }

    public function testADraftSeriesIsNotVisibleThoughApproved(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::SERIES_QUIET_PINES_DRAFT));

        $this->database->executeStatement(
            'UPDATE competition_series SET is_draft = false WHERE id = :id',
            ['id' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );

        self::assertTrue($this->query->check(OrganizationFixture::SERIES_QUIET_PINES_DRAFT));
    }

    public function testASeriesWaitingForApprovalIsNotVisible(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::SERIES_MAPLE_PENDING));
    }

    public function testARejectedSeriesIsNotVisibleEvenWhenApproved(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_series SET rejected_at = now() WHERE id = :id',
            ['id' => OrganizationFixture::SERIES_LANTERN_NIGHTS],
        );

        self::assertFalse($this->query->check(OrganizationFixture::SERIES_LANTERN_NIGHTS));
    }

    public function testUnknownOrInvalidIdIsNotVisible(): void
    {
        self::assertFalse($this->query->check('00000000-0000-0000-0000-000000000000'));
        self::assertFalse($this->query->check('not-a-uuid'));
    }
}
