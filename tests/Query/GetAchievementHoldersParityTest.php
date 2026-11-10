<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetAchievementHolders;
use SpeedPuzzling\Web\Results\AchievementTierHolders;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\HeldAtLaunch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The holders page (docs/features/xp-levels/ux-polish/design-spec.md §1, §8.1, §8.2) must list the same holders in the
 * same order, with the same "First to earn" and the same counts, however the statements are written.
 * GetAchievementHoldersBeforeTierScans keeps them as they were (window functions over every holder of the type).
 *
 * The seeded holders cover what decides a row: every membership state of the eligibility rule, private / XP opted out /
 * achievements opted out profiles, countries, players holding every tier up to their highest or only the highest, many
 * holders earning at the same moment (the id breaks the tie), more listed holders in a tier than LISTED_PER_TIER, the
 * launch backfill's stamp, and a viewer who blocks some of them.
 */
final class GetAchievementHoldersParityTest extends KernelTestCase
{
    private const int SEEDED_PLAYERS = 80;

    private const array TYPES = [BadgeType::ZenPuzzler, BadgeType::Speed500Pieces, BadgeType::Streak, BadgeType::Supporter, BadgeType::NightOwl];

    private const array COUNTRIES = [null, 'cz', 'us', 'de', 'sk', 'xx'];

    private const array EARNED_AT = [
        '2026-07-15 09:00:00', // before the launch window: a "First to earn" where it is the tier's first
        '2026-08-01 10:00:00', // the launch backfill's stamp
        '2026-08-01 10:00:00',
        '2026-09-01 10:00:00',
        '2026-09-01 10:00:00',
        '2026-09-02 08:30:00',
        '2026-09-15 22:00:00',
        '2026-10-01 10:00:00',
        '2026-10-13 23:59:59',
    ];

    private GetAchievementHolders $query;

    private GetAchievementHoldersBeforeTierScans $before;

    private Connection $database;

    /** @var list<string> */
    private array $seededPlayerIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->query = $container->get(GetAchievementHolders::class);
        $this->before = new GetAchievementHoldersBeforeTierScans(
            $this->database,
            $container->get(HiddenPlayers::class),
            $container->get(ClockInterface::class),
        );

        $this->seedHolders();
    }

    protected function tearDown(): void
    {
        TestingViewer::signOut(self::getContainer());

        parent::tearDown();
    }

    public function testTheHoldersOfEveryTypeTierAndCountryAreTheSame(): void
    {
        $this->assertSameForEveryCombination();
    }

    public function testTheHoldersAViewerBlocksAreLeftOutTheSameWay(): void
    {
        foreach (array_slice($this->seededPlayerIds, 0, 12, true) as $index => $playerId) {
            if ($index % 2 === 0) {
                $this->database->executeStatement(
                    "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
                    ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => $playerId],
                );
            }
        }

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertNotSame([], self::getContainer()->get(HiddenPlayers::class)->ids());

        $this->assertSameForEveryCombination();
    }

    public function testTheSeedingReachesWhatTheListsDecide(): void
    {
        $sections = $this->query->forType(BadgeType::ZenPuzzler, null, $this->heldAtLaunch(), BadgeTier::Bronze, 1000);
        $listed = array_sum(array_map(static fn (AchievementTierHolders $section): int => $section->listedCount, $sections));
        $unlisted = array_sum(array_map(static fn (AchievementTierHolders $section): int => $section->totalCount - $section->listedCount, $sections));
        $firstToEarn = 0;
        $heldAtLaunch = 0;

        foreach ($sections as $section) {
            foreach ($section->holders as $holder) {
                $firstToEarn += $holder->firstToEarn ? 1 : 0;
                $heldAtLaunch += $holder->heldAtLaunch ? 1 : 0;
            }
        }

        self::assertGreaterThan(GetAchievementHolders::LISTED_PER_TIER, max([0, ...array_map(static fn (AchievementTierHolders $section): int => $section->listedCount, $sections)]));
        self::assertGreaterThan(0, $listed);
        self::assertGreaterThan(0, $unlisted);
        self::assertGreaterThan(0, $firstToEarn);
        self::assertGreaterThan(0, $heldAtLaunch);
    }

    private function assertSameForEveryCombination(): void
    {
        $heldAtLaunch = $this->heldAtLaunch();

        foreach (self::TYPES as $type) {
            self::assertSame($this->before->countries($type), $this->query->countries($type), $type->value . ' countries');

            foreach (self::COUNTRIES as $country) {
                foreach ([null, ...BadgeTier::cases()] as $expandedTier) {
                    foreach ($expandedTier === null ? [GetAchievementHolders::LISTED_PER_TIER] : [1, GetAchievementHolders::LISTED_PER_TIER, 33, 1000] as $expandedLimit) {
                        self::assertSame(
                            $this->export($this->before->forType($type, $country, $heldAtLaunch, $expandedTier, $expandedLimit)),
                            $this->export($this->query->forType($type, $country, $heldAtLaunch, $expandedTier, $expandedLimit)),
                            sprintf('%s, country %s, expanded %s up to %d', $type->value, $country ?? 'any', $expandedTier->name ?? 'none', $expandedLimit),
                        );
                    }
                }
            }
        }

        // Before launch nobody is "First to earn"; with no stamp window everybody earned after it
        foreach ([new HeldAtLaunch(null, null), new HeldAtLaunch(null, new DateTimeImmutable('2026-09-01 10:00:00'))] as $otherWindow) {
            self::assertSame(
                $this->export($this->before->forType(BadgeType::ZenPuzzler, null, $otherWindow)),
                $this->export($this->query->forType(BadgeType::ZenPuzzler, null, $otherWindow)),
            );
        }
    }

    private function heldAtLaunch(): HeldAtLaunch
    {
        return new HeldAtLaunch(new DateTimeImmutable('2026-08-01 00:00:00'), new DateTimeImmutable('2026-08-02 00:00:00'));
    }

    /**
     * @param list<AchievementTierHolders> $sections
     *
     * @return list<array<string, mixed>>
     */
    private function export(array $sections): array
    {
        return array_map(static fn (AchievementTierHolders $section): array => [
            'tier' => $section->tier->value,
            'total' => $section->totalCount,
            'all' => $section->allCount,
            'listed' => $section->listedCount,
            'earned' => $section->earnedCount,
            'holders' => array_map(static fn ($holder): array => [
                $holder->playerId,
                $holder->playerName,
                $holder->playerCode,
                $holder->countryCode?->name,
                $holder->avatar,
                $holder->earnedAt->format('Y-m-d H:i:s.u'),
                $holder->heldAtLaunch,
                $holder->firstToEarn,
            ], $section->holders),
        ], $sections);
    }

    private function seedHolders(): void
    {
        mt_srand(20261110);
        $countries = ['cz', 'us', 'de', null, 'cz', 'sk', 'cz'];

        for ($i = 1; $i <= self::SEEDED_PLAYERS; $i++) {
            $playerId = Uuid::uuid7()->toString();
            $this->seededPlayerIds[] = $playerId;

            $this->database->executeStatement(
                'INSERT INTO player (id, code, name, country, avatar, is_private, experience_system_opted_out, achievements_opted_out, registered_at)
                 VALUES (:id, :code, :name, :country, :avatar, :private, :xpOptedOut, :achievementsOptedOut, ' . TestClock::SQL_NOW . ')',
                [
                    'id' => $playerId,
                    'code' => 'holder' . $i,
                    'name' => $i % 9 === 0 ? null : 'Holder ' . $i,
                    'country' => $countries[$i % count($countries)],
                    'avatar' => $i % 4 === 0 ? 'avatar-' . $i . '.png' : null,
                    'private' => $i % 11 === 0,
                    'xpOptedOut' => $i % 13 === 0,
                    'achievementsOptedOut' => $i % 17 === 0,
                ],
                ['private' => ParameterType::BOOLEAN, 'xpOptedOut' => ParameterType::BOOLEAN, 'achievementsOptedOut' => ParameterType::BOOLEAN],
            );

            $this->seedMembership($playerId, $i % 8);

            foreach (self::TYPES as $type) {
                if ($type === BadgeType::Supporter) {
                    // An untiered badge: never on a holders list
                    if ($i % 3 === 0) {
                        $this->insertBadge($playerId, $type, null, self::EARNED_AT[$i % count(self::EARNED_AT)]);
                    }

                    continue;
                }

                $highest = mt_rand(0, 5);
                $onlyHighest = mt_rand(0, 4) === 0;

                for ($tier = $onlyHighest ? $highest : 1; $tier <= $highest; $tier++) {
                    $this->insertBadge($playerId, $type, $tier, self::EARNED_AT[mt_rand(0, count(self::EARNED_AT) - 1)]);
                }
            }
        }

        // Zen Puzzler Bronze with more listed holders than a section shows
        for ($i = 1; $i <= self::SEEDED_PLAYERS; $i += 2) {
            $this->database->executeStatement(
                "DELETE FROM badge WHERE player_id = :player AND type = 'zen_puzzler'",
                ['player' => $this->seededPlayerIds[$i - 1]],
            );
            $this->insertBadge($this->seededPlayerIds[$i - 1], BadgeType::ZenPuzzler, 1, self::EARNED_AT[$i % count(self::EARNED_AT)]);
        }

        // The very first Zen Puzzler Bronze, earned before the launch window by a listed member: "First to earn"
        $this->database->executeStatement(
            "UPDATE badge SET earned_at = '2026-07-01 00:00:00' WHERE player_id = :player AND type = 'zen_puzzler' AND tier = 1",
            ['player' => $this->seededPlayerIds[0]],
        );
    }

    private function seedMembership(string $playerId, int $variant): void
    {
        $columns = match ($variant) {
            0 => null, // never a member
            1 => ['granted_until' => '2026-11-01 00:00:00'], // a grant still running
            2 => ['granted_until' => '2026-09-01 00:00:00'], // a lapsed grant
            3 => ['billing_period_ends_at' => '2026-11-01 00:00:00'], // a running subscription
            4 => ['billing_period_ends_at' => '2026-09-01 00:00:00'], // a subscription whose period end was not renewed yet
            5 => ['billing_period_ends_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 00:00:00'], // cancelled, still running
            6 => ['billing_period_ends_at' => '2026-09-20 00:00:00', 'ends_at' => '2026-09-20 00:00:00'], // cancelled and over
            default => ['ends_at' => '2026-09-20 00:00:00', 'granted_until' => '2026-12-01 00:00:00'], // over, then a grant
        };

        if ($columns === null) {
            return;
        }

        $this->database->insert('membership', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'created_at' => '2026-01-01 00:00:00',
            ...$columns,
        ]);
    }

    private function insertBadge(string $playerId, BadgeType $type, null|int $tier, string $earnedAt): void
    {
        $this->database->insert('badge', [
            'id' => Uuid::uuid4()->toString(),
            'player_id' => $playerId,
            'type' => $type->value,
            'earned_at' => $earnedAt,
            'tier' => $tier,
        ]);
    }
}
