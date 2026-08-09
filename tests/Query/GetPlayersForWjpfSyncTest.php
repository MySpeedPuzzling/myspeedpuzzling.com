<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\WjpfIdentity;
use SpeedPuzzling\Web\Query\GetPlayersForWjpfSync;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\WjpfSyncCandidate;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayersForWjpfSyncTest extends KernelTestCase
{
    private GetPlayersForWjpfSync $getPlayersForWjpfSync;
    private EntityManagerInterface $entityManager;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getPlayersForWjpfSync = $container->get(GetPlayersForWjpfSync::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testPrivateProfilesAreNeverIncluded(): void
    {
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->playerIds());
        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, $this->playerIds(force: true, includePaired: true));
    }

    public function testUncheckedPlayerIsACandidate(): void
    {
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds());
    }

    public function testCheckedPlayerIsSkippedByDefault(): void
    {
        $this->givenIdentity(WjpfPairingStatus::NotFound, wjpfId: null);

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds());
    }

    /** The point of a repeat run: someone who was not a member last time may be one now. */
    public function testForceRechecksAPreviousMiss(): void
    {
        $this->givenIdentity(WjpfPairingStatus::NotFound, wjpfId: null);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds(force: true));
    }

    /** Re-asking about a settled mapping cannot improve on it - it is pure load on their host. */
    public function testForceLeavesAlreadyPairedPlayersAlone(): void
    {
        $this->givenIdentity(WjpfPairingStatus::Paired, wjpfId: '189');

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds(force: true));
    }

    /**
     * A player matched once and since gone not_found keeps their mapping, so they still count
     * as paired for selection even though the latest status says otherwise.
     */
    public function testForceLeavesAKnownMappingAloneEvenWhenTheLatestCheckMissed(): void
    {
        $this->givenIdentity(WjpfPairingStatus::NotFound, wjpfId: '189');

        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds(force: true));
    }

    public function testIncludePairedBringsMappedPlayersBack(): void
    {
        $this->givenIdentity(WjpfPairingStatus::Paired, wjpfId: '189');

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->playerIds(force: true, includePaired: true));
    }

    public function testLimitCapsTheBatch(): void
    {
        self::assertCount(2, $this->getPlayersForWjpfSync->all(limit: 2));
    }

    /**
     * Builds a mapping row for PLAYER_REGULAR. A non-null $wjpfId means "we already hold their
     * id"; combining it with NotFound reproduces a match that later stopped resolving.
     */
    private function givenIdentity(WjpfPairingStatus $status, null|string $wjpfId): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $email = (string) $player->email;
        $now = new DateTimeImmutable();

        $identity = new WjpfIdentity(
            id: Uuid::uuid7(),
            player: $player,
            checkedEmail: $email,
            status: $status,
            checkedAt: $now,
        );

        if ($wjpfId !== null) {
            $identity->recordPairing(
                checkedEmail: $email,
                wjpfId: $wjpfId,
                wjpfNameUrl: null,
                conflictingMySpeedPuzzlingId: null,
                claimLanded: false,
                response: [],
                checkedAt: $now,
            );

            if ($status === WjpfPairingStatus::NotFound) {
                $identity->recordNotFound($email, [], $now);
            }
        }

        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private function playerIds(bool $force = false, bool $includePaired = false): array
    {
        return array_map(
            static fn (WjpfSyncCandidate $candidate): string => $candidate->playerId,
            $this->getPlayersForWjpfSync->all(includeAlreadyChecked: $force, includePaired: $includePaired),
        );
    }
}
