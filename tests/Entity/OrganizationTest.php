<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLinkPlatform;
use SpeedPuzzling\Web\Value\SocialLinks;

/**
 * docs/features/organizations/README.md - the team, drafts and the PHP mirror of IsOrganizationPubliclyVisible.
 */
final class OrganizationTest extends TestCase
{
    public function testTheTeamIsTheCreatorAndTheMaintainers(): void
    {
        $creator = self::player('creator');
        $maintainer = self::player('maintainer');
        $stranger = self::player('stranger');

        $organization = self::organization(addedBy: $creator);
        $organization->maintainers->add($maintainer);

        self::assertTrue($organization->isOnTeam($creator));
        self::assertTrue($organization->isOnTeam($maintainer));
        self::assertFalse($organization->isOnTeam($stranger));
    }

    public function testAnOrganizationWithoutCreatorHasOnlyItsMaintainersOnTheTeam(): void
    {
        $maintainer = self::player('maintainer');
        $organization = self::organization();
        $organization->maintainers->add($maintainer);

        self::assertTrue($organization->isOnTeam($maintainer));
        self::assertFalse($organization->isOnTeam(self::player('stranger')));
    }

    public function testPublishAndUnpublish(): void
    {
        $organization = self::organization(isDraft: true);
        self::assertTrue($organization->isDraft);

        $organization->publish();
        self::assertFalse($organization->isDraft);

        $organization->unpublish();
        self::assertTrue($organization->isDraft);
    }

    public function testPubliclyVisibleOnlyWhenApprovedNotRejectedAndNotADraft(): void
    {
        $admin = self::player('admin');
        $now = new DateTimeImmutable('2026-10-08 10:00:00');

        $pending = self::organization();
        self::assertFalse($pending->isPubliclyVisible());

        $approved = self::organization();
        $approved->approve($admin, $now);
        self::assertTrue($approved->isApproved());
        self::assertTrue($approved->isPubliclyVisible());

        $approvedDraft = self::organization(isDraft: true);
        $approvedDraft->approve($admin, $now);
        self::assertFalse($approvedDraft->isPubliclyVisible());

        $rejected = self::organization();
        $rejected->approve($admin, $now);
        $rejected->reject($admin, $now, 'Not an organiser.');
        self::assertTrue($rejected->isRejected());
        self::assertSame('Not an organiser.', $rejected->rejectionReason);
        self::assertFalse($rejected->isPubliclyVisible());
    }

    public function testSocialLinksAreKeptAsUrls(): void
    {
        $organization = self::organization(links: new SocialLinks(['https://www.instagram.com/lanternclub', 'https://discord.gg/lanternclub']));

        self::assertSame(['https://www.instagram.com/lanternclub', 'https://discord.gg/lanternclub'], $organization->socialLinks);

        $links = $organization->socialLinks()->links();
        self::assertCount(2, $links);
        self::assertSame(SocialLinkPlatform::Instagram, $links[0]->platform);
        self::assertSame(SocialLinkPlatform::Discord, $links[1]->platform);
    }

    public function testTheCountryIsStoredLowerCase(): void
    {
        $organization = self::organization(countryCode: 'US');
        self::assertSame('us', $organization->countryCode);

        $organization->edit(
            name: 'Lantern Puzzle Club',
            slug: 'lantern-puzzle-club',
            shortName: 'LPC',
            logo: null,
            about: 'Casual nights.',
            website: 'https://lantern.example',
            socialLinks: new SocialLinks(['https://www.youtube.com/@lanternclub']),
            countryCode: 'GB',
            region: 'Harborside',
            kind: OrganizationKind::Club,
        );

        self::assertSame('gb', $organization->countryCode);
        self::assertSame('Lantern Puzzle Club', $organization->name);
        self::assertSame('lantern-puzzle-club', $organization->slug);
        self::assertSame('LPC', $organization->shortName);
        self::assertSame('Harborside', $organization->region);
        self::assertSame(OrganizationKind::Club, $organization->kind);
        self::assertSame(['https://www.youtube.com/@lanternclub'], $organization->socialLinks);

        $organization->edit('Lantern Puzzle Club', 'lantern-puzzle-club', null, null, null, null, new SocialLinks([]), null, null, null);
        self::assertNull($organization->countryCode);
        self::assertSame([], $organization->socialLinks);
    }

    private static function organization(
        null|Player $addedBy = null,
        bool $isDraft = false,
        SocialLinks $links = new SocialLinks([]),
        null|string $countryCode = null,
    ): Organization {
        return new Organization(
            id: Uuid::uuid7(),
            name: 'Riverbend Test Association',
            slug: 'riverbend-test-association',
            createdAt: new DateTimeImmutable('2026-10-01 10:00:00'),
            links: $links,
            countryCode: $countryCode,
            isDraft: $isDraft,
            addedByPlayer: $addedBy,
        );
    }

    private static function player(string $code): Player
    {
        return new Player(Uuid::uuid7(), $code, null, ucfirst($code), new DateTimeImmutable('2026-01-01'));
    }
}
