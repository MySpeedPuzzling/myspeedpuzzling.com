<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PrivateProfileViewerFixture: PLAYER_PRIVATE allows PLAYER_WITH_FAVORITES, nobody else.
 */
final class PrivateProfileAccessTest extends KernelTestCase
{
    private PrivateProfileAccess $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->access = self::getContainer()->get(PrivateProfileAccess::class);
    }

    public function testNobodyIsRevealedWithoutASignedInViewer(): void
    {
        self::assertSame([], $this->access->revealedIds());
        self::assertFalse($this->access->isRevealed(PlayerFixture::PLAYER_PRIVATE));
        self::assertFalse($this->access->hasRevealedSomebody());
    }

    public function testQueriesAreLeftUntouchedForAViewerOnNobodysList(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        // Byte for byte the pre-feature SQL: same text, same plan, shared-cache safe
        self::assertSame('p.is_private', $this->access->sqlIsPrivate('p'));
        self::assertSame('p.is_private = false', $this->access->sqlIsPublic('p'));
        self::assertFalse($this->access->hasRevealedSomebody());
    }

    public function testTheAllowedViewerSeesThePrivatePlayer(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame([PlayerFixture::PLAYER_PRIVATE], $this->access->revealedIds());
        self::assertTrue($this->access->isRevealed(strtoupper(PlayerFixture::PLAYER_PRIVATE)));
        self::assertFalse($this->access->isRevealed(PlayerFixture::PLAYER_ADMIN));
        self::assertFalse($this->access->isRevealed(null));
        self::assertTrue($this->access->hasRevealedSomebody());

        self::assertSame(
            "(p.is_private AND p.id NOT IN ('" . PlayerFixture::PLAYER_PRIVATE . "'::uuid))",
            $this->access->sqlIsPrivate('p'),
        );
        self::assertSame(
            "(p.is_private = false OR p.id IN ('" . PlayerFixture::PLAYER_PRIVATE . "'::uuid))",
            $this->access->sqlIsPublic('p'),
        );
    }

    public function testTheListIsOneDirectional(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);

        self::assertSame([], $this->access->revealedIds());
    }

    public function testAdminsAreNotLetInByBeingAdmins(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertSame([], $this->access->revealedIds());
    }

    /**
     * FrankenPHP worker mode keeps the instance between requests: a set that survived reset()
     * would unmask private players for the NEXT visitor - the worst failure this feature can have.
     */
    public function testResetForgetsTheViewer(): void
    {
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNotSame([], $this->access->revealedIds());

        TestingViewer::signOut(self::getContainer());

        self::assertSame([], $this->access->revealedIds());
        self::assertSame('p.is_private', $this->access->sqlIsPrivate('p'));
        self::assertFalse($this->access->hasRevealedSomebody());

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([], $this->access->revealedIds());
    }

    public function testItIsRegisteredForTheResetBetweenRequests(): void
    {
        $resetter = self::getContainer()->get('services_resetter');

        $services = (fn (): array => iterator_to_array($this->resettableServices))->call($resetter);

        self::assertContains($this->access, $services, 'PrivateProfileAccess must be reset between requests.');
    }

    public function testABlockInEitherDirectionOutranksTheList(): void
    {
        $database = self::getContainer()->get(Connection::class);

        foreach ([[PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES], [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE]] as [$blocker, $blocked]) {
            $database->executeStatement('DELETE FROM user_block');
            $database->executeStatement(
                "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (gen_random_uuid(), :blocker, :blocked, NOW(), 'admin')",
                ['blocker' => $blocker, 'blocked' => $blocked],
            );

            TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

            self::assertSame([], $this->access->revealedIds());
        }
    }

    public function testARowNamingTheOwnerThemselvesMeansNothing(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (gen_random_uuid(), :id, :id, NOW())',
            ['id' => PlayerFixture::PLAYER_PRIVATE],
        );

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);

        self::assertSame([], $this->access->revealedIds());
    }
}
