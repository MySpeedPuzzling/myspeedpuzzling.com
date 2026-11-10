<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The every-brand list (allIncludingUnapproved() without parameters) is cached under a fingerprint of what decides
 * which brands are listed: every brand row, every hidden puzzle (and the reveal moments at :now), the puzzle totals of
 * the brands holding a hidden puzzle and the round puzzles hiding one everywhere. A new, renamed, merged or deleted
 * brand and a reveal show on the very next call; only the plain puzzle counts of public puzzles may lag the TTL.
 * The organisers' variants never touch the shared entry.
 */
final class GetManufacturersCacheTest extends KernelTestCase
{
    use ReadsRoundAutomaticReveal;

    private Connection $connection;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->pool = new ArrayAdapter();
    }

    public function testSecondCallIsServedFromTheCache(): void
    {
        $manufacturers = $this->manufacturers();
        $before = self::brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_TREFL);
        self::assertNotNull($before);

        $this->insertPuzzle(ManufacturerFixture::MANUFACTURER_TREFL, approved: true);

        self::assertSame($before, self::brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_TREFL));
        self::assertCount(1, $this->pool->getValues());

        // Computed afresh: the new puzzle is counted
        $this->pool->clear();
        self::assertSame($before + 1, self::brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_TREFL));
    }

    public function testANewBrandAppearsAtOnce(): void
    {
        $manufacturers = $this->manufacturers();
        $manufacturers->allIncludingUnapproved();

        // What AddPuzzleHandler does with a typed brand name
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $brand = self::getContainer()->get(ManufacturerResolver::class)->resolve('Just Typed Brand', $player, TestClock::now());
        $entityManager->flush();

        self::assertSame(0, self::brandCount($manufacturers->allIncludingUnapproved(), $brand->id->toString()));
    }

    public function testADeletedOrMergedBrandDisappearsAtOnce(): void
    {
        $emptyBrandId = $this->insertBrand('Soon Deleted Brand');
        $mergedBrandId = $this->insertBrand('Soon Merged Brand');
        $this->insertPuzzle($mergedBrandId, approved: true);

        $manufacturers = $this->manufacturers();
        self::assertSame(0, self::brandCount($manufacturers->allIncludingUnapproved(), $emptyBrandId));
        self::assertSame(1, self::brandCount($manufacturers->allIncludingUnapproved(), $mergedBrandId));

        $this->connection->executeStatement('DELETE FROM manufacturer WHERE id = :id', ['id' => $emptyBrandId]);
        // A merge moves the puzzles and deletes the brand (ManufacturerMerger)
        $this->connection->executeStatement(
            'UPDATE puzzle SET manufacturer_id = :into WHERE manufacturer_id = :id',
            ['into' => ManufacturerFixture::MANUFACTURER_TREFL, 'id' => $mergedBrandId],
        );
        $this->connection->executeStatement('DELETE FROM manufacturer WHERE id = :id', ['id' => $mergedBrandId]);

        $after = $manufacturers->allIncludingUnapproved();
        self::assertNull(self::brandCount($after, $emptyBrandId));
        self::assertNull(self::brandCount($after, $mergedBrandId));
    }

    public function testEveryRenameShowsAtOnce(): void
    {
        $manufacturers = $this->manufacturers();
        $manufacturers->allIncludingUnapproved();

        // Twice in one transaction: the row's xmin would not change the second time, its content does
        foreach (['Ravensburger Renamed', 'Ravensburger Renamed Again'] as $name) {
            $this->connection->executeStatement(
                'UPDATE manufacturer SET name = :name WHERE id = :id',
                ['name' => $name, 'id' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER],
            );

            self::assertSame($name, self::brandName($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER));
        }
    }

    public function testASecretOnlyBrandAppearsExactlyAtTheRevealMoment(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Cached Secret', 'Cached Secret Brand');
        $roundPuzzle = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);
        $brandId = $roundPuzzle->puzzle->manufacturer?->id->toString();
        self::assertNotNull($brandId);
        $revealAt = $roundPuzzle->revealsAt();
        self::assertNotNull($revealAt);

        self::assertNull(self::brandCount($this->manufacturers($revealAt->modify('-1 second'))->allIncludingUnapproved(), $brandId));
        // The same pool, a moment later: the list is computed again
        self::assertSame(1, self::brandCount($this->manufacturers($revealAt)->allIncludingUnapproved(), $brandId));
        // And before it again (a clock never goes back, but nothing ever answers with another moment's list)
        self::assertNull(self::brandCount($this->manufacturers($revealAt->modify('-1 second'))->allIncludingUnapproved(), $brandId));
    }

    public function testABrandLeftWithOnlySecretPuzzlesDisappearsAtOnce(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Secret Among Public', 'Half Secret Brand');
        $roundPuzzle = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);
        $brandId = $roundPuzzle->puzzle->manufacturer?->id->toString();
        self::assertNotNull($brandId);
        $publicPuzzleId = $this->insertPuzzle($brandId, approved: true);

        $manufacturers = $this->manufacturers();
        self::assertSame(1, self::brandCount($manufacturers->allIncludingUnapproved(), $brandId));

        // Its only public puzzle moves to another brand: what is left would give the secret brand away
        $this->connection->executeStatement(
            'UPDATE puzzle SET manufacturer_id = :into WHERE id = :id',
            ['into' => ManufacturerFixture::MANUFACTURER_TREFL, 'id' => $publicPuzzleId],
        );

        self::assertNull(self::brandCount($manufacturers->allIncludingUnapproved(), $brandId));
    }

    public function testTheOrganisersVariantsNeverReadNorWriteTheSharedEntry(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Organiser Secret', 'Organiser Secret Brand');
        $roundPuzzle = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);
        $brandId = $roundPuzzle->puzzle->manufacturer?->id->toString();
        self::assertNotNull($brandId);

        $manufacturers = $this->manufacturers();

        // The organiser first: nothing is stored, and the public list does not get the secret brand
        self::assertSame(0, self::brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024), $brandId));
        self::assertSame(0, self::brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR), $brandId));
        self::assertCount(0, $this->pool->getValues());

        self::assertNull(self::brandCount($manufacturers->allIncludingUnapproved(), $brandId));
        self::assertCount(1, $this->pool->getValues());

        // The shared entry first: the organiser still gets their secret brand
        self::assertSame(0, self::brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024), $brandId));
        self::assertCount(1, $this->pool->getValues());
    }

    public function testTheContainersServiceIsCached(): void
    {
        $manufacturers = self::getContainer()->get(GetManufacturers::class);
        $before = self::brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_TREFL);

        $this->insertPuzzle(ManufacturerFixture::MANUFACTURER_TREFL, approved: true);

        self::assertSame($before, self::brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_TREFL));
    }

    private function manufacturers(null|DateTimeImmutable $now = null): GetManufacturers
    {
        return new GetManufacturers($this->connection, new MockClock($now ?? TestClock::now()), $this->pool);
    }

    /**
     * @param array<ManufacturerOverview> $overviews
     */
    private static function brandCount(array $overviews, string $manufacturerId): null|int
    {
        foreach ($overviews as $overview) {
            if ($overview->manufacturerId === $manufacturerId) {
                return $overview->puzzlesCount;
            }
        }

        return null;
    }

    /**
     * @param array<ManufacturerOverview> $overviews
     */
    private static function brandName(array $overviews, string $manufacturerId): null|string
    {
        foreach ($overviews as $overview) {
            if ($overview->manufacturerId === $manufacturerId) {
                return $overview->manufacturerName;
            }
        }

        return null;
    }

    private function insertBrand(string $name): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'INSERT INTO manufacturer (id, name, approved) VALUES (:id, :name, false)',
            ['id' => $id, 'name' => $name],
        );

        return $id;
    }

    private function insertPuzzle(string $manufacturerId, bool $approved): string
    {
        $id = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, alternative_names, is_available)
             VALUES (:id, 500, :name, :approved, :manufacturerId, '[]', false)",
            [
                'id' => $id,
                'name' => 'Cache test puzzle ' . $id,
                'approved' => $approved ? 'true' : 'false',
                'manufacturerId' => $manufacturerId,
            ],
        );

        return $id;
    }

    private function addSecretPuzzle(string $name, string $brand): string
    {
        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: $brand,
            puzzle: $name,
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
        ));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $roundPuzzleId->toString();
    }
}
