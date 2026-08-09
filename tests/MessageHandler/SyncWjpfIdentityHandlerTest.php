<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Entity\WjpfIdentity;
use SpeedPuzzling\Web\Message\SyncWjpfIdentity;
use SpeedPuzzling\Web\MessageHandler\SyncWjpfIdentityHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\WjpfIdentityRepository;
use SpeedPuzzling\Web\Services\Wjpf\WjpfClient;
use SpeedPuzzling\Web\Services\Wjpf\WjpfIdentityRecorder;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The handler is driven directly rather than through the bus so the WJPF endpoint can be
 * mocked per test.
 */
final class SyncWjpfIdentityHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private WjpfIdentityRepository $wjpfIdentityRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->wjpfIdentityRepository = $container->get(WjpfIdentityRepository::class);
    }

    public function testClaimingAFreeRecordPairsThePlayer(): void
    {
        // Their column is empty, so the id we sent alongside this call was stored.
        $this->handle('{"IdJugador":"189","NombreURL":"john-doe","MySpeedPuzzlingId":"","status":"ok"}', claim: true);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::Paired, $identity->status);
        self::assertSame('189', $identity->wjpfId);
        self::assertSame('john-doe', $identity->wjpfNameUrl);
        self::assertNotNull($identity->pairedAt);
        self::assertNotNull($identity->claimedAt);
        self::assertNull($identity->conflictingMySpeedPuzzlingId);
    }

    /**
     * A survey sends no id, so nothing can have landed on their side even though their
     * column is free.
     */
    public function testSurveyPairsLocallyWithoutClaiming(): void
    {
        $this->handle('{"IdJugador":"189","NombreURL":"john-doe","MySpeedPuzzlingId":"","status":"ok"}', claim: false);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::Paired, $identity->status);
        self::assertNull($identity->claimedAt);
    }

    public function testRecordAlreadyHoldingOurIdIsPairedNotConflicting(): void
    {
        $this->handle(sprintf(
            '{"IdJugador":"189","NombreURL":"john-doe","MySpeedPuzzlingId":"%s","status":"ok"}',
            PlayerFixture::PLAYER_REGULAR,
        ), claim: true);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::Paired, $identity->status);
        self::assertNull($identity->conflictingMySpeedPuzzlingId);
        // Their column was already set, so our claim was silently dropped by their guard.
        self::assertNull($identity->claimedAt);
    }

    public function testRecordHoldingSomebodyElseIsAConflictButStillPairedLocally(): void
    {
        $foreignId = '018d0000-0000-0000-0000-0000000000ff';

        $this->handle(sprintf(
            '{"IdJugador":"189","NombreURL":"john-doe","MySpeedPuzzlingId":"%s","status":"ok"}',
            $foreignId,
        ), claim: true);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::Conflict, $identity->status);
        self::assertSame($foreignId, $identity->conflictingMySpeedPuzzlingId);
        // Decision: we keep our half of the mapping even when theirs disagrees.
        self::assertSame('189', $identity->wjpfId);
        self::assertNull($identity->claimedAt);
    }

    public function testMissingPlayerIsRecordedAsNotFound(): void
    {
        $this->handle('{"status": "error", "mensaje": "player not found"}', claim: true);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::NotFound, $identity->status);
        self::assertNull($identity->wjpfId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $identity->checkedEmail);
    }

    /** A later disappearance from their database must not erase an earlier match. */
    public function testNotFoundAfterAPairingKeepsTheKnownWjpfId(): void
    {
        $this->handle('{"IdJugador":"189","NombreURL":"john-doe","MySpeedPuzzlingId":"","status":"ok"}', claim: true);
        $pairedAt = $this->identity()->pairedAt;

        $this->handle('{"status": "error", "mensaje": "player not found"}', claim: true);

        $identity = $this->identity();
        self::assertSame(WjpfPairingStatus::NotFound, $identity->status);
        self::assertSame('189', $identity->wjpfId);
        self::assertEquals($pairedAt, $identity->pairedAt);
    }

    private function handle(string $responseBody, bool $claim): void
    {
        $container = self::getContainer();

        $client = new WjpfClient(
            new MockHttpClient(new MockResponse($responseBody)),
            new NullLogger(),
            'https://worldjigsawpuzzle.org/users/users_pr.php',
            'test-wjpf-token',
        );

        $handler = new SyncWjpfIdentityHandler(
            $container->get(PlayerRepository::class),
            $client,
            $container->get(WjpfIdentityRecorder::class),
            new NullLogger(),
        );

        $handler(new SyncWjpfIdentity(PlayerFixture::PLAYER_REGULAR, $claim));

        // The bus is bypassed here, so doctrine_transaction middleware does not flush for us.
        $this->entityManager->flush();
    }

    private function identity(): WjpfIdentity
    {
        $identity = $this->wjpfIdentityRepository->findByPlayerId(PlayerFixture::PLAYER_REGULAR);
        self::assertInstanceOf(WjpfIdentity::class, $identity);

        return $identity;
    }
}
