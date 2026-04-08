<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetReferralProgramMembers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetReferralProgramMembersTest extends KernelTestCase
{
    private GetReferralProgramMembers $getReferralProgramMembers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getReferralProgramMembers = self::getContainer()->get(GetReferralProgramMembers::class);
    }

    public function testCountByStatus(): void
    {
        $counts = $this->getReferralProgramMembers->countByStatus();

        // PLAYER_REGULAR is active, PLAYER_WITH_STRIPE is suspended
        self::assertSame(1, $counts['active']);
        self::assertSame(1, $counts['suspended']);
    }

    public function testActiveMembers(): void
    {
        $members = $this->getReferralProgramMembers->byStatus(suspended: false);

        self::assertCount(1, $members);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $members[0]['player_id']);
    }

    public function testSuspendedMembers(): void
    {
        $members = $this->getReferralProgramMembers->byStatus(suspended: true);

        self::assertCount(1, $members);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $members[0]['player_id']);
    }

    public function testActiveMembersIncludeStats(): void
    {
        $members = $this->getReferralProgramMembers->byStatus(suspended: false);

        // PLAYER_REGULAR has 1 supporter and payouts
        self::assertSame(1, (int) $members[0]['supporter_count']);
        self::assertSame(120, (int) $members[0]['total_earned_cents']);
        self::assertSame(60, (int) $members[0]['pending_payout_cents']);
    }
}
