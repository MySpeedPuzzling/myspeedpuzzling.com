<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Exceptions\FollowTargetNotAvailable;
use SpeedPuzzling\Web\Message\FollowCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class FollowCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testFollowsAOneTimeEvent(): void
    {
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_ADMIN, 'competition:' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));

        self::assertSame(1, $this->follows(PlayerFixture::PLAYER_ADMIN, 'competition_id', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));
    }

    public function testFollowsASeries(): void
    {
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_ADMIN, 'series:' . EventsPageFixture::SERIES_SUMMIT_LEAGUE));

        self::assertSame(1, $this->follows(PlayerFixture::PLAYER_ADMIN, 'series_id', EventsPageFixture::SERIES_SUMMIT_LEAGUE));
    }

    public function testFollowsAnOrganization(): void
    {
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_ADMIN, 'organization:' . OrganizationFixture::ORGANIZATION_RIVERBEND));

        self::assertSame(1, $this->follows(PlayerFixture::PLAYER_ADMIN, 'organization_id', OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testFollowingAnOrganizationTwiceKeepsOneRow(): void
    {
        // PLAYER_REGULAR already follows Riverbend (fixture)
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'organization:' . OrganizationFixture::ORGANIZATION_RIVERBEND));

        self::assertSame(1, $this->follows(PlayerFixture::PLAYER_REGULAR, 'organization_id', OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testFollowingTwiceKeepsOneRow(): void
    {
        // PLAYER_REGULAR already follows Harbor Jigsaw Nights (fixture)
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . strtoupper(EventsPageFixture::SERIES_HARBOR_NIGHTS)));
        $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_REGULAR, 'series:' . EventsPageFixture::SERIES_HARBOR_NIGHTS));

        self::assertSame(1, $this->follows(PlayerFixture::PLAYER_REGULAR, 'series_id', EventsPageFixture::SERIES_HARBOR_NIGHTS));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnavailableTargets(): iterable
    {
        yield 'an edition (its series is followed instead)' => ['competition:' . EventsPageFixture::EDITION_HARBOR_1];
        yield 'an unapproved event' => ['competition:' . CompetitionFixture::COMPETITION_UNAPPROVED];
        yield 'a rejected event' => ['competition:' . EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED];
        yield 'a rejected series' => ['series:' . EventsPageFixture::SERIES_OLD_MILL_REJECTED];
        yield 'an unapproved series' => ['series:' . CompetitionSeriesFixture::SERIES_UNAPPROVED];
        yield 'an unknown id' => ['competition:018d0040-0000-0000-0000-0000000000ff'];
        yield 'a malformed target' => ['competition:not-a-uuid'];
        yield 'an unknown kind' => ['player:' . PlayerFixture::PLAYER_REGULAR];
        yield 'a draft organization' => ['organization:' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT];
        yield 'an organization waiting for approval' => ['organization:' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING];
        yield 'an organization waiting for approval and a draft' => ['organization:' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT];
        yield 'an unknown organization' => ['organization:018d0042-0000-0000-0000-0000000000ff'];
        yield 'a draft series' => ['series:' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT];
        yield 'a draft event' => ['competition:' . OrganizationFixture::COMPETITION_DRAFT_NIGHT];
    }

    #[DataProvider('provideUnavailableTargets')]
    public function testCannotFollowWhatIsNotAvailable(string $target): void
    {
        try {
            $this->messageBus->dispatch(new FollowCompetition(PlayerFixture::PLAYER_ADMIN, $target));
            self::fail('FollowTargetNotAvailable expected');
        } catch (FollowTargetNotAvailable) {
            // expected
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(FollowTargetNotAvailable::class, $exception->getPrevious());
        }

        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM followed_competition WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        ));
    }

    private function follows(string $playerId, string $column, string $targetId): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM followed_competition WHERE player_id = :player AND {$column} = :target",
            ['player' => $playerId, 'target' => $targetId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
