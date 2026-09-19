<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class HiddenPlayersTest extends KernelTestCase
{
    private HiddenPlayers $hiddenPlayers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->hiddenPlayers = self::getContainer()->get(HiddenPlayers::class);
    }

    public function testNobodyIsHiddenWithoutASignedInViewer(): void
    {
        self::assertSame([], $this->hiddenPlayers->ids());
        self::assertFalse($this->hiddenPlayers->isHidden(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testQueriesAreLeftUntouchedWhenNobodyIsHidden(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertSame('', $this->hiddenPlayers->sqlExclude('player.id'));
        self::assertSame('', $this->hiddenPlayers->sqlExcludeTeam('pst.team'));
    }

    public function testBlockerDoesNotSeeTheBlockedPlayer(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame([PlayerFixture::PLAYER_PRIVATE], $this->hiddenPlayers->ids());
        self::assertTrue($this->hiddenPlayers->isHidden(PlayerFixture::PLAYER_PRIVATE));
        self::assertTrue($this->hiddenPlayers->isHidden(strtoupper(PlayerFixture::PLAYER_PRIVATE)));
        self::assertFalse($this->hiddenPlayers->isHidden(PlayerFixture::PLAYER_ADMIN));
        self::assertFalse($this->hiddenPlayers->isHidden(null));
    }

    public function testBlockIsOneDirectional(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);

        self::assertSame([], $this->hiddenPlayers->ids());
    }

    public function testFragmentsNameTheHiddenPlayerAndLetNullThrough(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame(
            " AND (p.id IS NULL OR p.id NOT IN ('" . PlayerFixture::PLAYER_PRIVATE . "'::uuid))",
            $this->hiddenPlayers->sqlExclude('p.id'),
        );

        $teamFragment = $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        self::assertStringContainsString('pst.team IS NULL', $teamFragment);
        self::assertStringContainsString('"player_id": "' . PlayerFixture::PLAYER_PRIVATE . '"', $teamFragment);
        // The viewer's own group times survive
        self::assertStringContainsString('"player_id": "' . PlayerFixture::PLAYER_REGULAR . '"', $teamFragment);
    }

    public function testAdminAreaSeesEveryone(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::getContainer()->get(RequestStack::class)->push(Request::create('/admin/moderation'));
        $this->hiddenPlayers->reset();

        self::assertSame([], $this->hiddenPlayers->ids());
    }

    public function testResetForgetsTheViewer(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertNotSame([], $this->hiddenPlayers->ids());

        TestingViewer::signOut(self::getContainer());

        self::assertSame([], $this->hiddenPlayers->ids());
    }
}
