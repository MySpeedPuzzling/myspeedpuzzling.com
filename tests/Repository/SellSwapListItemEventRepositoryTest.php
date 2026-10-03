<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Entity\SellSwapListItemEvent;
use SpeedPuzzling\Web\Repository\SellSwapListItemEventRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SellSwapListItemEventRepositoryTest extends KernelTestCase
{
    private SellSwapListItemEventRepository $repository;
    private EntityManagerInterface $entityManager;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(SellSwapListItemEventRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testFindsRowByBothIds(): void
    {
        $row = $this->repository->find(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertNotNull($row);
        self::assertSame(SellSwapListItemFixture::SELLSWAP_01, $row->sellSwapListItem->id->toString());
        self::assertSame(MarketplaceEventFixture::COMPETITION_SWAP_FAIR, $row->competition->id->toString());

        self::assertNotNull($this->repository->find(
            Uuid::fromString(SellSwapListItemFixture::SELLSWAP_02),
            Uuid::fromString(MarketplaceEventFixture::COMPETITION_SWAP_FAIR),
        ));
    }

    public function testFindReturnsNullForMissingOrInvalidIds(): void
    {
        self::assertNull($this->repository->find(SellSwapListItemFixture::SELLSWAP_03, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
        self::assertNull($this->repository->find('not-a-uuid', MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
        self::assertNull($this->repository->find(SellSwapListItemFixture::SELLSWAP_01, 'not-a-uuid'));
    }

    public function testSaveAndDeleteRoundTrip(): void
    {
        $listItem = $this->entityManager->find(SellSwapListItem::class, SellSwapListItemFixture::SELLSWAP_06);
        $competition = $this->entityManager->find(Competition::class, CompetitionSeriesFixture::EDITION_OFFLINE_1);
        self::assertNotNull($listItem);
        self::assertNotNull($competition);

        $this->repository->save(new SellSwapListItemEvent(
            sellSwapListItem: $listItem,
            competition: $competition,
            addedAt: new DateTimeImmutable('2026-10-01 12:00:00'),
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $row = $this->repository->find(SellSwapListItemFixture::SELLSWAP_06, CompetitionSeriesFixture::EDITION_OFFLINE_1);
        self::assertNotNull($row);
        self::assertSame('2026-10-01 12:00:00', $row->addedAt->format('Y-m-d H:i:s'));

        $this->repository->delete($row);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNull($this->repository->find(SellSwapListItemFixture::SELLSWAP_06, CompetitionSeriesFixture::EDITION_OFFLINE_1));
    }

    public function testForPlayerAndCompetitionReturnsOnlyThatSellersRowsForThatEvent(): void
    {
        self::assertSame(
            [SellSwapListItemFixture::SELLSWAP_01, SellSwapListItemFixture::SELLSWAP_02],
            self::listItemIds($this->repository->forPlayerAndCompetition(PlayerFixture::PLAYER_WITH_STRIPE, MarketplaceEventFixture::COMPETITION_SWAP_FAIR)),
        );
        self::assertSame(
            [SellSwapListItemFixture::SELLSWAP_07],
            self::listItemIds($this->repository->forPlayerAndCompetition(PlayerFixture::PLAYER_WITH_STRIPE, CompetitionSeriesFixture::EDITION_PAST_ONLY_1)),
        );
        self::assertSame([], $this->repository->forPlayerAndCompetition(PlayerFixture::PLAYER_ADMIN, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
        self::assertSame([], $this->repository->forPlayerAndCompetition('not-a-uuid', MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testForListItemReturnsEveryEventIncludingPastOnes(): void
    {
        $rows = $this->repository->forListItem(SellSwapListItemFixture::SELLSWAP_07);

        self::assertCount(1, $rows);
        self::assertSame(CompetitionSeriesFixture::EDITION_PAST_ONLY_1, $rows[0]->competition->id->toString());
        self::assertSame([], $this->repository->forListItem(SellSwapListItemFixture::SELLSWAP_03));
        self::assertSame([], $this->repository->forListItem('not-a-uuid'));
    }

    public function testRowsGoWithTheListingAndWithTheEvent(): void
    {
        $this->database->executeStatement('DELETE FROM sell_swap_list_item WHERE id = :id', ['id' => SellSwapListItemFixture::SELLSWAP_01]);
        self::assertSame(1, $this->rowsForEvent(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));

        $this->database->executeStatement('DELETE FROM competition_participant WHERE competition_id = :id', ['id' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR]);
        $this->database->executeStatement('DELETE FROM competition WHERE id = :id', ['id' => MarketplaceEventFixture::COMPETITION_SWAP_FAIR]);
        self::assertSame(0, $this->rowsForEvent(MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    /**
     * @param list<SellSwapListItemEvent> $rows
     * @return list<string>
     */
    private static function listItemIds(array $rows): array
    {
        $ids = array_map(static fn (SellSwapListItemEvent $row): string => $row->sellSwapListItem->id->toString(), $rows);
        sort($ids);

        return $ids;
    }

    private function rowsForEvent(string $competitionId): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM sell_swap_list_item_event WHERE competition_id = :id',
            ['id' => $competitionId],
        );
        assert(is_int($count));

        return $count;
    }
}
