<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\OrganizationOnEdition;

/**
 * docs/features/organizations/README.md - an edition never has its own organization, moving an edition (P21), drafts
 * and the PHP mirror of IsCompetitionPubliclyVisible.
 */
final class CompetitionOrganizationTest extends TestCase
{
    public function testAnEditionCannotBeCreatedWithAnOrganization(): void
    {
        $this->expectException(OrganizationOnEdition::class);

        self::competition(series: self::series(), organization: self::organization());
    }

    public function testAnEditionRefusesAnOrganizationButMayBeLeftWithout(): void
    {
        $edition = self::competition(series: self::series());

        $edition->assignOrganization(null);
        self::assertNull($edition->organization);

        $this->expectException(OrganizationOnEdition::class);
        $edition->assignOrganization(self::organization());
    }

    public function testAOneTimeEventMovesIntoAndOutOfAnOrganization(): void
    {
        $organization = self::organization();
        $event = self::competition();

        $event->assignOrganization($organization);
        self::assertSame($organization, $event->organization);

        $event->assignOrganization(null);
        self::assertNull($event->organization);
    }

    public function testOnlyAnEditionMovesToAnotherSeries(): void
    {
        $this->expectException(LogicException::class);

        self::competition()->moveToSeries(self::series(), 'new-slug');
    }

    public function testAMovedEditionTakesTheTargetsPlaceWhereItKeptItsOldSeriesPlace(): void
    {
        $old = self::series(location: 'Riverbend', countryCode: 'us');
        $target = self::series(location: 'Harborside', countryCode: 'gb', isOnline: false);
        $edition = self::competition(series: $old, location: 'Riverbend', countryCode: 'us');

        $edition->moveToSeries($target, 'lantern-night-one');

        self::assertSame($target, $edition->series);
        self::assertSame('lantern-night-one', $edition->slug);
        self::assertSame('Harborside', $edition->location);
        self::assertSame('gb', $edition->locationCountryCode);
        self::assertFalse($edition->isOnline);
    }

    public function testAPlaceTheOrganiserChangedStaysWhenTheEditionMoves(): void
    {
        $old = self::series(location: 'Riverbend', countryCode: 'us');
        $target = self::series(location: 'Harborside', countryCode: 'gb');
        // Another venue in the same country: the country follows the series, the venue stays
        $edition = self::competition(series: $old, location: 'Lantern Brewing', countryCode: 'us');

        $edition->moveToSeries($target, 'special-night');

        self::assertSame('Lantern Brewing', $edition->location);
        self::assertSame('gb', $edition->locationCountryCode);
    }

    public function testAnEditionMovedToAnOnlineSeriesIsOnline(): void
    {
        $edition = self::competition(series: self::series(), location: 'Riverbend', countryCode: 'us');

        $edition->moveToSeries(self::series(location: null, countryCode: null, isOnline: true), 'virtual-contest-3');

        self::assertTrue($edition->isOnline);
        self::assertNull($edition->location);
        self::assertNull($edition->locationCountryCode);
    }

    public function testHiddenAsADraftByItsOwnFlagOrItsSeries(): void
    {
        $event = self::competition(isDraft: true);
        self::assertTrue($event->isHiddenAsDraft());

        $event->publish();
        self::assertFalse($event->isHiddenAsDraft());

        $event->unpublish();
        self::assertTrue($event->isDraft);

        $draftSeries = self::series(isDraft: true);
        $edition = self::competition(series: $draftSeries);
        self::assertFalse($edition->isDraft);
        self::assertTrue($edition->isHiddenAsDraft());

        $draftSeries->publish();
        self::assertFalse($edition->isHiddenAsDraft());
    }

    public function testPubliclyVisibleMirrorsTheSqlRule(): void
    {
        $admin = self::player();
        $now = new DateTimeImmutable('2026-10-08 10:00:00');

        // One-time events: approved, not rejected, not a draft
        $pending = self::competition();
        self::assertFalse($pending->isPubliclyVisible());

        $approved = self::competition();
        $approved->approve($admin, $now);
        self::assertTrue($approved->isPubliclyVisible());

        $approvedDraft = self::competition(isDraft: true);
        $approvedDraft->approve($admin, $now);
        self::assertFalse($approvedDraft->isPubliclyVisible());

        $rejected = self::competition();
        $rejected->approve($admin, $now);
        $rejected->reject($admin, $now, 'Duplicate.');
        self::assertFalse($rejected->isPubliclyVisible());

        // Editions: their series decides, their own draft flag and rejection veto
        $approvedSeries = self::series();
        $approvedSeries->approve($admin, $now);
        self::assertTrue(self::competition(series: $approvedSeries)->isPubliclyVisible());
        self::assertFalse(self::competition(series: $approvedSeries, isDraft: true)->isPubliclyVisible());
        self::assertFalse(self::competition(series: self::series())->isPubliclyVisible(), 'a series waiting for approval hides its editions');

        $draftSeries = self::series(isDraft: true);
        $draftSeries->approve($admin, $now);
        self::assertFalse(self::competition(series: $draftSeries)->isPubliclyVisible());

        $rejectedEdition = self::competition(series: $approvedSeries);
        $rejectedEdition->reject($admin, $now, 'Duplicate.');
        self::assertFalse($rejectedEdition->isPubliclyVisible());
    }

    public function testEligibilityHasItsOwnMethod(): void
    {
        $event = self::competition();

        $event->changeEligibility('21+');
        self::assertSame('21+', $event->eligibility);

        $event->changeEligibility(null);
        self::assertNull($event->eligibility);
    }

    private static function competition(
        null|CompetitionSeries $series = null,
        null|Organization $organization = null,
        null|string $location = null,
        null|string $countryCode = null,
        bool $isDraft = false,
    ): Competition {
        return new Competition(
            id: Uuid::uuid7(),
            name: 'Lantern Night',
            slug: 'lantern-night',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: $location,
            locationCountryCode: $countryCode,
            dateFrom: null,
            dateTo: null,
            tag: null,
            series: $series,
            organization: $organization,
            isDraft: $isDraft,
        );
    }

    private static function series(
        null|string $location = 'Riverbend',
        null|string $countryCode = 'us',
        bool $isOnline = false,
        bool $isDraft = false,
    ): CompetitionSeries {
        return new CompetitionSeries(
            id: Uuid::uuid7(),
            name: 'Lantern Brewing Test Nights',
            slug: 'lantern-brewing-test-nights-' . substr(Uuid::uuid7()->toString(), -6),
            logo: null,
            description: null,
            link: null,
            isOnline: $isOnline,
            location: $location,
            locationCountryCode: $countryCode,
            isDraft: $isDraft,
        );
    }

    private static function organization(): Organization
    {
        return new Organization(Uuid::uuid7(), 'Riverbend Test Association', 'riverbend-test-association', new DateTimeImmutable('2026-10-01'));
    }

    private static function player(): Player
    {
        return new Player(Uuid::uuid7(), 'admin', null, 'Admin', new DateTimeImmutable('2026-01-01'));
    }
}
