<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MarkFavoritesSeen;
use SpeedPuzzling\Web\MessageHandler\MarkFavoritesSeenHandler;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * "New since your last visit" on the Hub's Favorites feed (docs/features/navigation, INB-04a): the last visit moves to
 * now at most once per ten minutes, and a mark inside that window changes nothing - no UPDATE.
 */
final class MarkFavoritesSeenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private PlayerRepository $playerRepository;

    private MockClock $clock;

    private MarkFavoritesSeenHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->clock = new MockClock('2026-10-12 10:00:00');
        $this->handler = new MarkFavoritesSeenHandler($this->playerRepository, $this->clock);
    }

    public function testNoLastVisitBecomesNow(): void
    {
        self::assertNull($this->player()->favoritesSeenAt, 'Fixtures start without a last visit');

        $this->mark();

        self::assertSame('2026-10-12 10:00:00', $this->storedValue());
    }

    public function testFiveMinutesLaterNothingChanges(): void
    {
        $this->mark();
        $this->clock->modify('+5 minutes');

        ($this->handler)(new MarkFavoritesSeen(PlayerFixture::PLAYER_WITH_FAVORITES));

        // The entity left the value alone: no change set, so no UPDATE at the flush
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        self::assertSame([], $unitOfWork->getEntityChangeSet($this->player()));

        $this->entityManager->flush();
        self::assertSame('2026-10-12 10:00:00', $this->storedValue());
    }

    public function testElevenMinutesLaterItMovesToNow(): void
    {
        $this->mark();
        $this->clock->modify('+11 minutes');

        $this->mark();

        self::assertSame('2026-10-12 10:11:00', $this->storedValue());
    }

    public function testTheSignedInPlayersProfileRowCarriesIt(): void
    {
        $userId = $this->player()->userId;
        assert($userId !== null);
        $getPlayerProfile = self::getContainer()->get(GetPlayerProfile::class);

        self::assertNull($getPlayerProfile->byUserId($userId)->favoritesSeenAt);

        $this->mark();

        self::assertEquals(new DateTimeImmutable('2026-10-12 10:00:00'), $getPlayerProfile->byUserId($userId)->favoritesSeenAt);
        // Nobody else's profile reads it
        self::assertNull($getPlayerProfile->byId(PlayerFixture::PLAYER_WITH_FAVORITES)->favoritesSeenAt);
    }

    public function testAnUnknownPlayerIsRefused(): void
    {
        $this->expectException(PlayerNotFound::class);

        ($this->handler)(new MarkFavoritesSeen('018d0000-0000-0000-0000-00000000dead'));
    }

    private function mark(): void
    {
        ($this->handler)(new MarkFavoritesSeen(PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->entityManager->flush();
    }

    private function player(): Player
    {
        return $this->playerRepository->get(PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    private function storedValue(): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT favorites_seen_at FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
    }
}
