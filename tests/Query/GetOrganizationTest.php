<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLink;
use SpeedPuzzling\Web\Value\SocialLinkPlatform;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetOrganizationTest extends KernelTestCase
{
    private GetOrganization $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetOrganization::class);
    }

    public function testBySlugReadsEveryField(): void
    {
        $organization = $this->query->bySlug(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG);

        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $organization->id);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $organization->name);
        self::assertSame('RJA', $organization->shortName);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $organization->slug);
        self::assertSame(OrganizationKind::Association, $organization->kind);
        self::assertSame(CountryCode::us, $organization->countryCode);
        self::assertSame('Riverbend Valley', $organization->region);
        self::assertSame('https://riverbend-jigsaw.example', $organization->website);
        self::assertNotNull($organization->about);
        self::assertStringContainsString("\n", $organization->about);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $organization->addedByPlayerId);
        self::assertNull($organization->addedByPlayerName, 'only the approval queue reads the creator\'s name');
        self::assertSame(
            [SocialLinkPlatform::Instagram, SocialLinkPlatform::Discord],
            array_map(static fn (SocialLink $link): SocialLinkPlatform => $link->platform, $organization->socialLinks),
        );
        self::assertSame('Instagram', $organization->socialLinks[0]->name());

        self::assertFalse($organization->isDraft);
        self::assertNotNull($organization->approvedAt);
        self::assertNull($organization->rejectedAt);
        self::assertTrue($organization->isPublic());
        self::assertFalse($organization->isPending());
    }

    public function testByIdGivesTheSameOrganization(): void
    {
        self::assertEquals(
            $this->query->bySlug(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG),
            $this->query->byId(OrganizationFixture::ORGANIZATION_RIVERBEND),
        );
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $this->query->byId(strtoupper(OrganizationFixture::ORGANIZATION_RIVERBEND))->id);
    }

    public function testADraftIsReadInAnyState(): void
    {
        $harbor = $this->query->bySlug(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT_SLUG);

        self::assertTrue($harbor->isDraft);
        self::assertNotNull($harbor->approvedAt);
        self::assertFalse($harbor->isPublic());
        self::assertFalse($harbor->isPending());
        self::assertSame(OrganizationKind::Club, $harbor->kind);
        self::assertSame([], $harbor->socialLinks);
    }

    public function testOneWaitingForApproval(): void
    {
        $maple = $this->query->byId(OrganizationFixture::ORGANIZATION_MAPLE_PENDING);

        self::assertTrue($maple->isPending());
        self::assertFalse($maple->isPublic());
        self::assertFalse($maple->isDraft);

        // Waiting for approval and a draft - pending all the same (it is submitted by publishing it)
        $cedar = $this->query->byId(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT);
        self::assertTrue($cedar->isPending());
        self::assertTrue($cedar->isDraft);
    }

    public function testUnknownSlug(): void
    {
        $this->expectException(OrganizationNotFound::class);

        $this->query->bySlug('no-such-organization');
    }

    public function testUnknownId(): void
    {
        $this->expectException(OrganizationNotFound::class);

        $this->query->byId('018d0042-0000-0000-0000-0000000000ff');
    }

    public function testInvalidId(): void
    {
        $this->expectException(OrganizationNotFound::class);

        $this->query->byId('not-a-uuid');
    }
}
