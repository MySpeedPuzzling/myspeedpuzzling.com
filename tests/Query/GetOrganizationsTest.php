<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetOrganizations;
use SpeedPuzzling\Web\Results\OrganizationChoice;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Results\OrganizationDirectoryRow;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetOrganizationsTest extends KernelTestCase
{
    private GetOrganizations $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetOrganizations::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    /**
     * Only publicly visible organizations: not the draft (Harbor), not the ones waiting for approval (Maple, Cedar)
     */
    public function testThePublicDirectory(): void
    {
        $rows = $this->query->publicDirectory();

        self::assertSame(
            [OrganizationFixture::ORGANIZATION_RIVERBEND],
            array_map(static fn (OrganizationDirectoryRow $row): string => $row->id, $rows),
        );

        $riverbend = $rows[0];
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $riverbend->name);
        self::assertSame('RJA', $riverbend->shortName);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $riverbend->slug);
        self::assertSame(OrganizationKind::Association, $riverbend->kind);
        self::assertSame(CountryCode::us, $riverbend->countryCode);
        self::assertSame('Riverbend Valley', $riverbend->region);
        // The Lantern nights and the virtual contest; the Spring Open
        self::assertSame(2, $riverbend->seriesCount);
        self::assertSame(1, $riverbend->eventCount);
    }

    public function testTheCountsAreOfPubliclyVisibleItemsOnly(): void
    {
        $this->database->executeStatement('UPDATE competition_series SET is_draft = true WHERE id = :id', ['id' => OrganizationFixture::SERIES_LANTERN_NIGHTS]);
        $this->database->executeStatement('UPDATE competition SET approved_at = NULL WHERE id = :id', ['id' => OrganizationFixture::COMPETITION_RIVERBEND_OPEN]);

        $riverbend = $this->query->publicDirectory()[0];

        self::assertSame(1, $riverbend->seriesCount);
        self::assertSame(0, $riverbend->eventCount);
    }

    public function testAPublishedOrganizationJoinsTheDirectory(): void
    {
        $this->database->executeStatement('UPDATE organization SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);

        self::assertSame(
            [OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, OrganizationFixture::ORGANIZATION_RIVERBEND],
            array_map(static fn (OrganizationDirectoryRow $row): string => $row->id, $this->query->publicDirectory()),
        );
    }

    /**
     * The team: creator or maintainer - drafts and pending ones included, by name
     */
    public function testChoicesForPlayer(): void
    {
        self::assertSame(
            [OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, OrganizationFixture::ORGANIZATION_MAPLE_PENDING, OrganizationFixture::ORGANIZATION_RIVERBEND],
            $this->ids($this->query->choicesForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );
        self::assertSame(
            [OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, OrganizationFixture::ORGANIZATION_RIVERBEND],
            $this->ids($this->query->choicesForPlayer(PlayerFixture::PLAYER_WITH_STRIPE)),
        );
        self::assertSame([], $this->query->choicesForPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([], $this->query->choicesForPlayer('not-a-uuid'));

        $choices = $this->byId($this->query->choicesForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertSame(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT_NAME, $choices[OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT]->name);
        self::assertTrue($choices[OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT]->isDraft);
        self::assertFalse($choices[OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT]->isApproved);
        self::assertFalse($choices[OrganizationFixture::ORGANIZATION_MAPLE_PENDING]->isDraft);
        self::assertFalse($choices[OrganizationFixture::ORGANIZATION_MAPLE_PENDING]->isApproved);
        self::assertTrue($choices[OrganizationFixture::ORGANIZATION_RIVERBEND]->isApproved);
    }

    public function testARejectedOrganizationIsNoChoice(): void
    {
        $this->database->executeStatement('UPDATE organization SET rejected_at = now() WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_MAPLE_PENDING]);

        self::assertSame(
            [OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, OrganizationFixture::ORGANIZATION_RIVERBEND],
            $this->ids($this->query->choicesForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)),
        );
        self::assertNotContains(OrganizationFixture::ORGANIZATION_MAPLE_PENDING, $this->ids($this->query->allChoices()));
    }

    public function testAdminsChooseAmongEveryOrganizationNotRejected(): void
    {
        self::assertSame(
            [
                OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT,
                OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT,
                OrganizationFixture::ORGANIZATION_MAPLE_PENDING,
                OrganizationFixture::ORGANIZATION_RIVERBEND,
            ],
            $this->ids($this->query->allChoices()),
        );
    }

    /**
     * Waiting for approval and not a draft - Cedar is both waiting and a draft: it is submitted by publishing it
     */
    public function testTheApprovalQueue(): void
    {
        $queue = $this->query->allUnapproved();

        self::assertSame(
            [OrganizationFixture::ORGANIZATION_MAPLE_PENDING],
            array_map(static fn (OrganizationDetail $organization): string => $organization->id, $queue),
        );
        self::assertSame('Michael Johnson', $queue[0]->addedByPlayerName);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $queue[0]->addedByPlayerId);
        self::assertSame(OrganizationKind::Community, $queue[0]->kind);
        self::assertTrue($queue[0]->isPending());

        // Publishing Cedar submits it
        $this->database->executeStatement('UPDATE organization SET is_draft = false WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT]);
        self::assertCount(2, $this->query->allUnapproved());
    }

    /**
     * @param list<OrganizationChoice> $choices
     *
     * @return list<string>
     */
    private function ids(array $choices): array
    {
        return array_map(static fn (OrganizationChoice $choice): string => $choice->id, $choices);
    }

    /**
     * @param list<OrganizationChoice> $choices
     *
     * @return array<string, OrganizationChoice>
     */
    private function byId(array $choices): array
    {
        $byId = [];

        foreach ($choices as $choice) {
            $byId[$choice->id] = $choice;
        }

        return $byId;
    }
}
