<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The every-brand list of the pickers (allIncludingUnapproved() without parameters) is exactly what the statement it
 * was built on returns - the statement is kept here verbatim, so a cache or a rewrite cannot quietly change a row,
 * a count, the order, or which secret brands stay out.
 */
final class GetManufacturersTest extends KernelTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string REFERENCE_SQL = <<<'SQL'
SELECT
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.approved AS manufacturer_approved,
    manufacturer.logo AS manufacturer_logo,
    manufacturer.ean_prefix AS manufacturer_ean_prefix,
    COUNT(puzzle.id) FILTER (WHERE NOT (((puzzle.hide_until IS NOT NULL AND puzzle.hide_until > :now::timestamp) OR (puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp)) AND (puzzle.approved = false OR EXISTS (SELECT 1 FROM competition_round_puzzle secrecy_crp WHERE secrecy_crp.puzzle_id = puzzle.id AND secrecy_crp.hides_everywhere)))) AS puzzles_count
FROM manufacturer
LEFT JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
WHERE true
GROUP BY manufacturer.id
HAVING COUNT(puzzle.id) = 0
    OR COUNT(puzzle.id) FILTER (WHERE NOT (((puzzle.hide_until IS NOT NULL AND puzzle.hide_until > :now::timestamp) OR (puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp)) AND (puzzle.approved = false OR EXISTS (SELECT 1 FROM competition_round_puzzle secrecy_crp WHERE secrecy_crp.puzzle_id = puzzle.id AND secrecy_crp.hides_everywhere)))) > 0
ORDER BY COUNT(puzzle.id) FILTER (WHERE NOT (((puzzle.hide_until IS NOT NULL AND puzzle.hide_until > :now::timestamp) OR (puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp)) AND (puzzle.approved = false OR EXISTS (SELECT 1 FROM competition_round_puzzle secrecy_crp WHERE secrecy_crp.puzzle_id = puzzle.id AND secrecy_crp.hides_everywhere)))) DESC, manufacturer.name ASC
SQL;

    public function testTheFixtureListIsTheReferenceStatement(): void
    {
        self::bootKernel();
        $manufacturers = self::getContainer()->get(GetManufacturers::class);

        self::assertEquals($this->reference(TestClock::now()), $manufacturers->allIncludingUnapproved());
        // Asked again in the same request / worker: still the same rows
        self::assertEquals($this->reference(TestClock::now()), $manufacturers->allIncludingUnapproved());
    }

    public function testEverySecretScenarioIsTheReferenceStatement(): void
    {
        self::bootKernel();
        $manufacturers = self::getContainer()->get(GetManufacturers::class);

        // A brand typed for a secret round puzzle only (unapproved, hidden) - left out
        $this->addSecretPuzzle('Reference Secret', 'Reference Secret Brand');
        self::assertEquals($this->reference(TestClock::now()), $manufacturers->allIncludingUnapproved());

        // A brand whose only puzzle is approved and hidden by hand (the Puzzle Month placeholder) - listed, counted
        $placeholderBrandId = $this->insertBrand('Reference Placeholder Brand');
        $this->insertPuzzle($placeholderBrandId, approved: true, hideUntil: TestClock::now()->modify('+30 days'));
        // A brand with no puzzle at all - listed with 0
        $this->insertBrand('Reference Empty Brand');
        // A brand whose only puzzle is unapproved and its picture hidden (secret by hide_image_until) - left out
        $pictureBrandId = $this->insertBrand('Reference Hidden Picture Brand');
        $this->insertPuzzle($pictureBrandId, approved: false, hideImageUntil: TestClock::now()->modify('+30 days'));
        // A public puzzle of an existing brand - counted
        $this->insertPuzzle(ManufacturerFixture::MANUFACTURER_TREFL, approved: true);

        $expected = $this->reference(TestClock::now());
        self::assertSame(1, $this->brandCount($expected, $placeholderBrandId));
        self::assertNull($this->brandCount($expected, $pictureBrandId));

        // The class may have been asked before these rows came: a fresh kernel asks it now
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertEquals($expected, self::getContainer()->get(GetManufacturers::class)->allIncludingUnapproved());
    }

    /**
     * @return list<ManufacturerOverview>
     */
    private function reference(DateTimeImmutable $now): array
    {
        $rows = self::getContainer()->get(Connection::class)
            ->executeQuery(self::REFERENCE_SQL, ['now' => $now->format('Y-m-d H:i:s')])
            ->fetchAllAssociative();

        return array_map(static function (array $row): ManufacturerOverview {
            /**
             * @var array{
             *      manufacturer_id: string,
             *      manufacturer_name: string,
             *      manufacturer_approved: bool,
             *      manufacturer_logo: string|null,
             *      manufacturer_ean_prefix: string|null,
             *      puzzles_count: int,
             * } $row
             */
            return ManufacturerOverview::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * @param array<ManufacturerOverview> $overviews
     */
    private function brandCount(array $overviews, string $manufacturerId): null|int
    {
        foreach ($overviews as $overview) {
            if ($overview->manufacturerId === $manufacturerId) {
                return $overview->puzzlesCount;
            }
        }

        return null;
    }

    private function insertBrand(string $name): string
    {
        $id = Uuid::uuid7()->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO manufacturer (id, name, approved) VALUES (:id, :name, false)',
            ['id' => $id, 'name' => $name],
        );

        return $id;
    }

    private function insertPuzzle(
        string $manufacturerId,
        bool $approved,
        null|DateTimeImmutable $hideUntil = null,
        null|DateTimeImmutable $hideImageUntil = null,
    ): string {
        $id = Uuid::uuid7()->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, hide_until, hide_image_until, alternative_names, is_available)
             VALUES (:id, 500, :name, :approved, :manufacturerId, :hideUntil, :hideImageUntil, '[]', false)",
            [
                'id' => $id,
                'name' => 'Reference puzzle ' . $id,
                'approved' => $approved ? 'true' : 'false',
                'manufacturerId' => $manufacturerId,
                'hideUntil' => $hideUntil?->format('Y-m-d H:i:s'),
                'hideImageUntil' => $hideImageUntil?->format('Y-m-d H:i:s'),
            ],
        );

        return $id;
    }

    private function addSecretPuzzle(string $name, string $brand): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
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
    }
}
