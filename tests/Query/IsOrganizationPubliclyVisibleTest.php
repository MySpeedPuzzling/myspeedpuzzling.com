<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\IsOrganizationPubliclyVisible;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IsOrganizationPubliclyVisibleTest extends KernelTestCase
{
    private IsOrganizationPubliclyVisible $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(IsOrganizationPubliclyVisible::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAPublishedApprovedOrganizationIsVisible(): void
    {
        self::assertTrue($this->query->check(OrganizationFixture::ORGANIZATION_RIVERBEND));
        self::assertTrue($this->query->check(strtoupper(OrganizationFixture::ORGANIZATION_RIVERBEND)));
    }

    public function testADraftOrganizationIsNotVisibleThoughApproved(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT));

        $this->database->executeStatement(
            'UPDATE organization SET is_draft = false WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT],
        );

        self::assertTrue($this->query->check(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT));
    }

    public function testAnOrganizationWaitingForApprovalIsNotVisible(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        self::assertFalse($this->query->check(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
    }

    public function testARejectedOrganizationIsNotVisibleEvenWhenApproved(): void
    {
        $this->database->executeStatement(
            'UPDATE organization SET rejected_at = now() WHERE id = :id',
            ['id' => OrganizationFixture::ORGANIZATION_RIVERBEND],
        );

        self::assertFalse($this->query->check(OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testUnknownOrInvalidIdIsNotVisible(): void
    {
        self::assertFalse($this->query->check('00000000-0000-0000-0000-000000000000'));
        self::assertFalse($this->query->check('not-a-uuid'));
    }
}
