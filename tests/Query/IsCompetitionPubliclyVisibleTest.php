<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
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

    public function testADraftOneTimeEventIsNotVisibleThoughApproved(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertFalse($this->query->check(OrganizationFixture::COMPETITION_DRAFT_PAST));
        self::assertFalse($this->query->check(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT));
    }

    public function testADraftEditionHidesOnlyItself(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::EDITION_LANTERN_DRAFT));
        self::assertTrue($this->query->check(OrganizationFixture::EDITION_LANTERN_1));
        self::assertTrue($this->query->check(OrganizationFixture::EDITION_LANTERN_2));
    }

    public function testADraftSeriesHidesItsEditions(): void
    {
        self::assertFalse($this->query->check(OrganizationFixture::EDITION_QUIET_PINES_1));

        $this->database->executeStatement(
            'UPDATE competition_series SET is_draft = false WHERE id = :seriesId',
            ['seriesId' => OrganizationFixture::SERIES_QUIET_PINES_DRAFT],
        );

        self::assertTrue($this->query->check(OrganizationFixture::EDITION_QUIET_PINES_1));
    }

    public function testADraftOrganizationHidesNothingUnderIt(): void
    {
        self::assertTrue($this->query->check(OrganizationFixture::EDITION_HARBOR_CLUB_1));
    }

    public function testPublishingTheDraftMakesItVisible(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id = :id',
            ['id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );

        self::assertTrue($this->query->check(OrganizationFixture::COMPETITION_DRAFT_NIGHT));
    }

    public function testTheApprovalFragmentIgnoresDraftsAndTheDraftFragmentIgnoresApproval(): void
    {
        self::assertTrue($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_APPROVED, OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertTrue($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_APPROVED, OrganizationFixture::EDITION_QUIET_PINES_1));
        self::assertFalse($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_APPROVED, OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT));
        self::assertFalse($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_APPROVED, CompetitionFixture::COMPETITION_UNAPPROVED));

        self::assertFalse($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_NOT_DRAFT, OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertFalse($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_NOT_DRAFT, OrganizationFixture::EDITION_QUIET_PINES_1));
        self::assertFalse($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_NOT_DRAFT, OrganizationFixture::EDITION_LANTERN_DRAFT));
        self::assertTrue($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_NOT_DRAFT, CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertTrue($this->conditionHolds(IsCompetitionPubliclyVisible::SQL_NOT_DRAFT, OrganizationFixture::EDITION_LANTERN_1));
    }

    public function testTheFragmentsStayCorrectBehindNot(): void
    {
        $condition = IsCompetitionPubliclyVisible::SQL_CONDITION;

        self::assertTrue($this->conditionHolds("NOT {$condition}", OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertFalse($this->conditionHolds("NOT {$condition}", CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertTrue($this->conditionHolds('NOT ' . IsCompetitionPubliclyVisible::SQL_APPROVED, CompetitionFixture::COMPETITION_UNAPPROVED));
    }

    public function testUnknownCompetitionIsNotVisible(): void
    {
        self::assertFalse($this->query->check('00000000-0000-0000-0000-000000000000'));
    }

    public function testInvalidUuidIsNotVisible(): void
    {
        self::assertFalse($this->query->check('not-a-uuid'));
    }

    private function conditionHolds(string $condition, string $competitionId): bool
    {
        return $this->database->fetchOne(
            "SELECT 1 FROM competition c LEFT JOIN competition_series cs ON cs.id = c.series_id WHERE c.id = :id AND {$condition}",
            ['id' => $competitionId],
        ) !== false;
    }
}
