<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchPlayersTest extends KernelTestCase
{
    private SearchPlayers $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(SearchPlayers::class);
        $this->database = $container->get(Connection::class);
    }

    public function testBlockerDoesNotFindTheBlockedPlayer(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME, limit: 5));
        // Every other match is still found: the filter applies to the whole OR chain, not its last arm
        self::assertNotEmpty($this->search('a'));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->search('o'));
    }

    public function testFindsExactlyWhatThePreviousQueryFound(): void
    {
        $sarka = $this->createPlayer('Šárka Nováková', 'sarkanova');
        $zoe = $this->createPlayer('Zoë Müller', 'zoemuller');
        $lukasz = $this->createPlayer('Łukasz Żółw', 'ŁUKASZ7');

        // 3+ characters take the trigram index (immutable_unaccent), 1-2 characters the scan (unaccent)
        $terms = ['sar', 'šár', 'ŠÁRKA', 'nová', 'ova', 'zoe', 'Zoë', 'müll', 'mull', 'łuk', 'luk', 'lukasz7', 'żół',
            'doe', 'Doé', 'john', 'player', 'PLAYER3', 'admin', 'sarka nova', 'xyzq', 'j', 'jo', 'Ž', 'a', 'ö'];

        foreach ($terms as $term) {
            self::assertSame($this->previousQuery($term), $this->sorted($this->search($term)), $term);
        }

        self::assertContains($sarka, $this->search('sar'));
        self::assertContains($sarka, $this->search('NOVAK'));
        self::assertContains($zoe, $this->search('mull'));
        self::assertContains($lukasz, $this->search('luk'));
        self::assertContains($lukasz, $this->search('lukasz7'));
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search('jóhn'));
    }

    public function testSearchOfThreeOrMoreCharactersCanUseTheTrigramIndex(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->query->fulltext('john', 10);

        /** @var list<array{sql: string, params: array<mixed>}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        self::assertCount(1, $executed);

        // The test database is tiny, so the planner would scan it anyway - only prove the index is usable
        $this->database->executeStatement('SET LOCAL enable_seqscan = off');
        /** @var list<string> $plan */
        $plan = $this->database->fetchFirstColumn('EXPLAIN ' . $executed[0]['sql'], array_values($executed[0]['params']));

        self::assertStringContainsString('custom_player_search_trgm', implode("\n", $plan));
    }

    public function testOrganiserToolingStillFindsTheBlockedPlayer(): void
    {
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->search(PlayerFixture::PLAYER_REGULAR_NAME, limit: 10, includeHidden: true));
    }

    /**
     * @return list<string>
     */
    private function search(string $search, null|int $limit = null, bool $includeHidden = false): array
    {
        return array_map(
            static fn (PlayerIdentification $player): string => $player->playerId,
            $this->query->fulltext($search, $limit, $includeHidden),
        );
    }

    /**
     * The WHERE before the trigram index (plain unaccent() for every search), for guests: kept here
     * as the reference the current query must match.
     *
     * @return list<string>
     */
    private function previousQuery(string $search): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            <<<SQL
SELECT id FROM player
WHERE (
    LOWER(name) LIKE LOWER(:full) OR LOWER(code) LIKE LOWER(:full)
    OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:full)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:full))
)
    AND (player.is_private = false OR LOWER(code) = LOWER(:search))
SQL,
            ['full' => "%$search%", 'search' => $search],
        );

        return $this->sorted($ids);
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function createPlayer(string $name, string $code): string
    {
        $id = Uuid::uuid7();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new Player(
            id: $id,
            code: $code,
            userId: null,
            name: $name,
            registeredAt: new DateTimeImmutable(),
        ));
        $entityManager->flush();

        return $id->toString();
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
